<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\AI\Services\ModelEmbeddingSynchronizer;
use Modules\AI\Tests\Stubs\EmbeddableTestModel;
use Modules\AI\Tests\Stubs\TranslatedEmbeddableTestModel;
use Modules\AI\Tests\Stubs\TranslatedEmbeddableTestModelTranslation;
use Modules\Core\Events\ModelPreProcessingCompleted;
use NeuronAI\RAG\Document;

/**
 * Builds a NeuronAI Document carrying the given embedding vector, the shape
 * IEmbeddingService::embedDocument() returns.
 *
 * @param  list<float>  $vector
 */
function perLocaleEmbeddingDocument(array $vector): Document
{
    $document = new Document('');
    $document->setEmbedding($vector);

    return $document;
}

/**
 * Stub the single batched embedding call the synchronizer makes, returning one
 * chunk document per input text from a text => vector map. An input text absent
 * from the map throws, which asserts that a fresh (skipped) locale's text is
 * never sent to the service.
 *
 * @param  array<string, list<float>>  $map
 */
function stubEmbedBatch($service, array $map): void
{
    $service->shouldReceive('embedDocumentsBatch')
        ->once()
        ->andReturnUsing(static function (array $texts) use ($map): array {
            return array_map(static function (string $text) use ($map): array {
                if (! array_key_exists($text, $map)) {
                    throw new RuntimeException("unexpected embed text: {$text}");
                }

                return [perLocaleEmbeddingDocument($map[$text])];
            }, $texts);
        });
}

/**
 * Create a bilingual (it/en) translated model and embed both locales once with
 * the given vectors, returning the model.
 *
 * @param  list<float>  $it
 * @param  list<float>  $en
 */
function makeBilingualEmbeddedModel(array $it = [0.1, 0.1], array $en = [0.2, 0.2]): TranslatedEmbeddableTestModel
{
    $model = new TranslatedEmbeddableTestModel();
    $model->saveQuietly();

    TranslatedEmbeddableTestModelTranslation::query()->create([
        'translated_embeddable_test_model_id' => $model->id,
        'locale' => 'it',
        'title' => 'Titolo italiano',
    ]);
    TranslatedEmbeddableTestModelTranslation::query()->create([
        'translated_embeddable_test_model_id' => $model->id,
        'locale' => 'en',
        'title' => 'English title',
    ]);

    $service = Mockery::mock(IEmbeddingService::class);
    stubEmbedBatch($service, ['Titolo italiano' => $it, 'English title' => $en]);

    (new GenerateEmbeddingsJob($model))->handle($service);

    return $model;
}

beforeEach(function (): void {
    config()->set('ai.features.embeddings.models.openai_compatible:intfloat/multilingual-e5-small.dimensions', 2);
    Schema::create('embeddable_test_models', function ($table): void {
        $table->id();
        $table->string('title')->nullable();
    });

    Schema::create('translated_embeddable_test_models', function ($table): void {
        $table->id();
    });

    Schema::create('translated_embeddable_test_model_translations', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('translated_embeddable_test_model_id');
        $table->string('locale', 10);
        $table->text('title')->nullable();
    });
});

it('stamps one ModelEmbedding row per locale for a bilingual translated model', function (): void {
    Event::fake([ModelPreProcessingCompleted::class]);

    $model = new TranslatedEmbeddableTestModel();
    $model->saveQuietly();

    TranslatedEmbeddableTestModelTranslation::query()->create([
        'translated_embeddable_test_model_id' => $model->id,
        'locale' => 'it',
        'title' => 'Titolo italiano',
    ]);
    TranslatedEmbeddableTestModelTranslation::query()->create([
        'translated_embeddable_test_model_id' => $model->id,
        'locale' => 'en',
        'title' => 'English title',
    ]);

    $embedding_service = Mockery::mock(IEmbeddingService::class);
    stubEmbedBatch($embedding_service, ['Titolo italiano' => [0.1, 0.1], 'English title' => [0.2, 0.2]]);

    $job = new GenerateEmbeddingsJob($model);
    $job->handle($embedding_service);

    $rows = $model->embeddings()->get();
    $expected_key = app(EmbeddingModelRegistry::class)->active()->key;

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('model_key')->unique()->all())->toBe([$expected_key])
        ->and($rows->firstWhere('locale', 'it')?->embedding)->toBe([0.1, 0.1])
        ->and($rows->firstWhere('locale', 'en')?->embedding)->toBe([0.2, 0.2]);

    Event::assertDispatched(ModelPreProcessingCompleted::class, fn (ModelPreProcessingCompleted $event): bool => $event->model->is($model) && $event->processing_type === 'embeddings');
});

it('stores embeddings without announcing completion on the bulk path', function (): void {
    Event::fake([ModelPreProcessingCompleted::class]);

    $model = new EmbeddableTestModel(['title' => 'Plain text']);
    $model->saveQuietly();

    $service = Mockery::mock(IEmbeddingService::class);
    stubEmbedBatch($service, ['Plain text' => [0.5, 0.5]]);

    $synchronizer = new ModelEmbeddingSynchronizer($service, app(EmbeddingModelRegistry::class));
    $synchronizer->sync([$model], announceCompletion: false);

    // Embeddings are still persisted...
    expect($model->embeddings()->get())->toHaveCount(1)
        ->and($model->embeddings()->first()->embedding)->toBe([0.5, 0.5]);
    // ...but no per-model completion event fires: the bulk indexer writes the engine.
    Event::assertNotDispatched(ModelPreProcessingCompleted::class);
});

it('issues no per-model embeddings query on the bulk path when the relation is eager-loaded', function (): void {
    // Seed three models, each with a fresh embedding already stored.
    $models = collect(range(1, 3))->map(function (int $i): EmbeddableTestModel {
        $model = new EmbeddableTestModel(['title' => "Text {$i}"]);
        $model->saveQuietly();

        $seed = Mockery::mock(IEmbeddingService::class);
        stubEmbedBatch($seed, ["Text {$i}" => [0.1, 0.2]]);
        (new GenerateEmbeddingsJob($model))->handle($seed);

        return $model->fresh();
    });

    // Eager-load embeddings the way Searchable::eagerLoadForIndexing does.
    EloquentCollection::make($models->all())->loadMissing('embeddings');

    // No embedDocumentsBatch expectation: everything is fresh, so a call would throw.
    $service = Mockery::mock(IEmbeddingService::class);
    $synchronizer = new ModelEmbeddingSynchronizer($service, app(EmbeddingModelRegistry::class));

    DB::enableQueryLog();
    $synchronizer->sync($models, announceCompletion: false, reload: false);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $embedding_reads = collect($queries)
        ->filter(static fn (array $q): bool => str_contains($q['query'], 'core_model_embeddings')
            && str_starts_with(mb_ltrim(mb_strtolower($q['query'])), 'select'))
        ->count();

    // Reads come from the eager-loaded relation, not one query per model.
    expect($embedding_reads)->toBe(0);
});

it('stamps a single locale = null row for a non-translated model', function (): void {
    $model = new EmbeddableTestModel(['title' => 'Plain text']);
    $model->saveQuietly();

    $embedding_service = Mockery::mock(IEmbeddingService::class);
    stubEmbedBatch($embedding_service, ['Plain text' => [0.5, 0.5]]);

    $job = new GenerateEmbeddingsJob($model);
    $job->handle($embedding_service);

    $rows = $model->embeddings()->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->locale)->toBeNull()
        ->and($rows->first()->model_key)->toBe(app(EmbeddingModelRegistry::class)->active()->key);
});

it('regenerates only the requested locale, leaving the other locale untouched', function (): void {
    $model = new TranslatedEmbeddableTestModel();
    $model->saveQuietly();

    TranslatedEmbeddableTestModelTranslation::query()->create([
        'translated_embeddable_test_model_id' => $model->id,
        'locale' => 'it',
        'title' => 'Titolo italiano',
    ]);
    TranslatedEmbeddableTestModelTranslation::query()->create([
        'translated_embeddable_test_model_id' => $model->id,
        'locale' => 'en',
        'title' => 'English title',
    ]);

    $first_service = Mockery::mock(IEmbeddingService::class);
    stubEmbedBatch($first_service, ['Titolo italiano' => [0.1, 0.1], 'English title' => [0.2, 0.2]]);

    $first_job = new GenerateEmbeddingsJob($model);
    $first_job->handle($first_service);

    $en_row_before = $model->embeddings()->get()->firstWhere('locale', 'en');

    // Change the italian translation only, then regenerate just that locale.
    TranslatedEmbeddableTestModelTranslation::query()
        ->where('translated_embeddable_test_model_id', $model->id)
        ->where('locale', 'it')
        ->update(['title' => 'Titolo italiano aggiornato']);

    $second_service = Mockery::mock(IEmbeddingService::class);
    stubEmbedBatch($second_service, ['Titolo italiano aggiornato' => [0.9, 0.9]]);

    $second_job = new GenerateEmbeddingsJob($model, 'it');
    $second_job->handle($second_service);

    $rows_after = $model->embeddings()->get();
    $it_row_after = $rows_after->firstWhere('locale', 'it');
    $en_row_after = $rows_after->firstWhere('locale', 'en');

    expect($rows_after)->toHaveCount(2)
        ->and($it_row_after->embedding)->toBe([0.9, 0.9])
        ->and($en_row_after->id)->toBe($en_row_before->id)
        ->and($en_row_after->updated_at->equalTo($en_row_before->updated_at))->toBeTrue()
        ->and($en_row_after->embedding)->toBe([0.2, 0.2]);
});

it('skips re-embedding on a full re-run when content and model are unchanged', function (): void {
    $model = makeBilingualEmbeddedModel();
    $before = $model->embeddings()->get()->keyBy('locale');

    // Full re-run (no locale) with nothing changed: the service must not be called.
    $service = Mockery::mock(IEmbeddingService::class);
    $service->shouldNotReceive('embedDocumentsBatch');

    (new GenerateEmbeddingsJob($model))->handle($service);

    $after = $model->embeddings()->get()->keyBy('locale');

    expect($after)->toHaveCount(2)
        ->and($after['it']->id)->toBe($before['it']->id)
        ->and($after['en']->id)->toBe($before['en']->id)
        ->and($after['it']->embedding)->toBe([0.1, 0.1])
        ->and($after['en']->embedding)->toBe([0.2, 0.2]);
});

it('recomputes only the changed locale on a full re-run', function (): void {
    $model = makeBilingualEmbeddedModel();
    $en_before = $model->embeddings()->get()->firstWhere('locale', 'en');

    // Change only the italian translation.
    TranslatedEmbeddableTestModelTranslation::query()
        ->where('translated_embeddable_test_model_id', $model->id)
        ->where('locale', 'it')
        ->update(['title' => 'Titolo italiano aggiornato']);

    // Full re-run (no locale): only the italian embedding is regenerated.
    $service = Mockery::mock(IEmbeddingService::class);
    stubEmbedBatch($service, ['Titolo italiano aggiornato' => [0.9, 0.9]]);

    (new GenerateEmbeddingsJob($model))->handle($service);

    $after = $model->embeddings()->get()->keyBy('locale');

    expect($after)->toHaveCount(2)
        ->and($after['it']->embedding)->toBe([0.9, 0.9])
        ->and($after['en']->id)->toBe($en_before->id)
        ->and($after['en']->embedding)->toBe([0.2, 0.2]);
});

it('synchronizes embeddings for multiple models in one call', function (): void {
    $alpha = new EmbeddableTestModel(['title' => 'Alpha']);
    $alpha->saveQuietly();
    $beta = new EmbeddableTestModel(['title' => 'Beta']);
    $beta->saveQuietly();

    $service = Mockery::mock(IEmbeddingService::class);
    stubEmbedBatch($service, ['Alpha' => [0.1, 0.1], 'Beta' => [0.2, 0.2]]);

    $synchronizer = new ModelEmbeddingSynchronizer($service, app(EmbeddingModelRegistry::class));
    $synchronizer->sync([$alpha, $beta]);

    expect($alpha->fresh()->embeddings()->count())->toBe(1)
        ->and($beta->fresh()->embeddings()->count())->toBe(1)
        ->and($alpha->fresh()->embeddings()->first()->embedding)->toBe([0.1, 0.1])
        ->and($beta->fresh()->embeddings()->first()->embedding)->toBe([0.2, 0.2]);
});

it('recomputes every locale when the active embedding model changed', function (): void {
    $model = makeBilingualEmbeddedModel();

    // Switch the active embedding-model profile: model_key no longer matches.
    config()->set('core.search.vector.model', 'openai_compatible:all-MiniLM-L6-v2');
    config()->set('ai.features.embeddings.models.openai_compatible:all-MiniLM-L6-v2.dimensions', 2);
    $new_key = app(EmbeddingModelRegistry::class)->active()->key;

    $service = Mockery::mock(IEmbeddingService::class);
    stubEmbedBatch($service, ['Titolo italiano' => [0.3, 0.3], 'English title' => [0.4, 0.4]]);

    (new GenerateEmbeddingsJob($model))->handle($service);

    // The previous profile's rows are not touched by a run for another profile: its vectors
    // are removed when the switch is activated, not by the embedding run.
    $rows = $model->embeddings()->where('model_key', $new_key)->get();

    expect($rows)->toHaveCount(2)
        ->and($model->embeddings()->count())->toBe(4)
        ->and($rows->firstWhere('locale', 'it')->embedding)->toBe([0.3, 0.3])
        ->and($rows->firstWhere('locale', 'en')->embedding)->toBe([0.4, 0.4]);
});

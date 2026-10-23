<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Console\RepairMissingEmbeddingsCommand;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\AI\Tests\Stubs\EmbeddableTestModel;
use Modules\AI\Tests\Stubs\TranslatedEmbeddableTestModel;
use Modules\Core\Helpers\LocaleContext;
use NeuronAI\RAG\Document;

/**
 * Builds a NeuronAI Document carrying the given embedding vector, the shape
 * IEmbeddingService::embedDocument() returns.
 *
 * @param  list<float>  $vector
 */
function repairEmbeddingDocument(array $vector): Document
{
    $document = new Document('');
    $document->setEmbedding($vector);

    return $document;
}

/**
 * Stub the single batched embedding call the repair path makes through
 * GenerateEmbeddingsJob -> ModelEmbeddingSynchronizer, returning one chunk
 * document per input text from a text => vector map. An input text absent from
 * the map throws, asserting that a fresh (skipped) record is never embedded.
 *
 * @param  array<string, list<float>>  $map
 */
function stubRepairEmbedBatch($service, array $map): void
{
    $service->shouldReceive('embedDocumentsBatch')
        ->once()
        ->andReturnUsing(static function (array $texts) use ($map): array {
            return array_map(static function (string $text) use ($map): array {
                if (! array_key_exists($text, $map)) {
                    throw new RuntimeException("unexpected embed text: {$text}");
                }

                return [repairEmbeddingDocument($map[$text])];
            }, $texts);
        });
}

/**
 * Runs the repair command via Artisan::call and returns [exitCode, output].
 * Chaining multiple expectsOutputToContain() assertions on $this->artisan()
 * only reliably consumes the first one when several substrings share a
 * single rendered line (see Modules/Core/tests/Feature/Console/RouteCheckCommandTest.php),
 * so multi-substring assertions here capture the raw buffer instead.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{0: int, 1: string}
 */
function runRepairEmbeddingsCommand(array $parameters): array
{
    $exit_code = Artisan::call('ai:embeddings:repair', $parameters);

    return [$exit_code, Artisan::output()];
}

/**
 * Fakes the /info preflight to report the currently active profile's
 * service_model, i.e. no mismatch. Http::fake() registrations are additive
 * (first URL match wins) rather than replacing, so each test registers its
 * own /info stub instead of sharing one from beforeEach.
 */
function fakeHealthyEmbeddingService(): void
{
    $active_service_model = app(EmbeddingModelRegistry::class)->active()->serviceModel;

    Http::fake([
        '*/info' => Http::response(['model_id' => $active_service_model]),
        '*/v1/embeddings' => repairProbeAnswer(),
    ]);
}

/**
 * The /v1/embeddings answer to the command's preflight probe: one vector of the active profile's dimensions.
 */
function repairProbeAnswer(): GuzzleHttp\Promise\PromiseInterface
{
    return Http::response(['data' => [['index' => 0, 'embedding' => ai_test_unit_vector(app(EmbeddingModelRegistry::class)->active()->dimensions)]]]);
}

beforeEach(function (): void {
    config()->set('ai.servers.embeddings.url', 'http://localhost:8000');
    config()->set('ai.features.embeddings.models.openai_compatible:intfloat/multilingual-e5-small.dimensions', 2);
    Config::set('core.search.vector.enabled', true);

    Schema::create('embeddable_test_models', function ($table): void {
        $table->id();
        $table->string('title')->nullable();
    });
});

it('regenerates records missing an embedding and stamps the active model_key', function (): void {
    fakeHealthyEmbeddingService();

    $model = new EmbeddableTestModel(['title' => 'Alpha']);
    $model->saveQuietly();

    $embedding_service = Mockery::mock(IEmbeddingService::class);
    stubRepairEmbedBatch($embedding_service, ['Alpha' => [0.1, 0.2]]);

    app()->instance(IEmbeddingService::class, $embedding_service);

    $this->artisan('ai:embeddings:repair', [
        'model' => EmbeddableTestModel::class,
        '--sync' => true,
    ])->assertSuccessful();

    $rows = $model->embeddings()->get();
    $expected_key = app(EmbeddingModelRegistry::class)->active()->key;

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->model_key)->toBe($expected_key)
        ->and($rows->first()->embedding)->toBe([0.1, 0.2]);
});

it('runs every record under --sync even when the embeddings rate limit is exhausted', function (): void {
    fakeHealthyEmbeddingService();
    // A released job is lost under the sync queue, so the limiter must not apply to a sync run.
    Config::set('core.queue_rate_limits.embeddings', 1);

    $models = [];

    foreach (['Alpha', 'Beta', 'Gamma'] as $title) {
        $model = new EmbeddableTestModel(['title' => $title]);
        $model->saveQuietly();
        $models[] = $model;
    }

    $embedding_service = Mockery::mock(IEmbeddingService::class);
    $embedding_service->shouldReceive('embedDocumentsBatch')
        ->times(3)
        ->andReturnUsing(static fn (array $texts): array => array_map(
            static fn (string $text): array => [repairEmbeddingDocument([0.1, 0.2])],
            $texts,
        ));
    app()->instance(IEmbeddingService::class, $embedding_service);

    $this->artisan('ai:embeddings:repair', [
        'model' => EmbeddableTestModel::class,
        '--sync' => true,
    ])->assertSuccessful();

    foreach ($models as $model) {
        expect($model->embeddings()->count())->toBe(1);
    }
});

it('reports a record that fails under --sync, fails the command and still embeds the others', function (): void {
    fakeHealthyEmbeddingService();

    $alpha = new EmbeddableTestModel(['title' => 'Alpha']);
    $alpha->saveQuietly();

    $beta = new EmbeddableTestModel(['title' => 'Beta']);
    $beta->saveQuietly();

    $embedding_service = Mockery::mock(IEmbeddingService::class);
    $embedding_service->shouldReceive('embedDocumentsBatch')
        ->twice()
        ->andReturnUsing(static function (array $texts): array {
            if (in_array('Alpha', $texts, true)) {
                throw new RuntimeException('embedding service down');
            }

            return array_map(
                static fn (string $text): array => [repairEmbeddingDocument([0.3, 0.3])],
                $texts,
            );
        });
    app()->instance(IEmbeddingService::class, $embedding_service);

    [$exit_code, $output] = runRepairEmbeddingsCommand([
        'model' => EmbeddableTestModel::class,
        '--sync' => true,
    ]);

    expect($exit_code)->toBe(RepairMissingEmbeddingsCommand::FAILURE)
        ->and($output)->toContain('embedding service down')
        ->and($alpha->embeddings()->count())->toBe(0)
        ->and($beta->embeddings()->count())->toBe(1);
});

it('also repairs records that LocaleScope hides because they have no translation in the current locale', function (): void {
    Queue::fake();
    fakeHealthyEmbeddingService();

    config(['app.locale' => 'it']);
    LocaleContext::set('it');

    Schema::create('translated_embeddable_test_models', function ($table): void {
        $table->id();
    });

    Schema::create('translated_embeddable_test_model_translations', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('translated_embeddable_test_model_id');
        $table->string('locale');
        $table->string('title')->nullable();
    });

    $hidden = new TranslatedEmbeddableTestModel;
    $hidden->saveQuietly();
    $hidden->translations()->create(['locale' => 'en', 'title' => 'English only']);

    expect(TranslatedEmbeddableTestModel::query()->count())->toBe(0);

    $this->artisan('ai:embeddings:repair', ['model' => TranslatedEmbeddableTestModel::class])->assertSuccessful();

    Queue::assertPushed(GenerateEmbeddingsJob::class, 1);
});

it('does not touch records that already carry an embedding when --stale is not set', function (): void {
    fakeHealthyEmbeddingService();

    $model = new EmbeddableTestModel(['title' => 'Beta']);
    $model->saveQuietly();
    $model->embeddings()->create(['embedding' => [0.9, 0.9], 'model_key' => 'legacy-model']);

    $embedding_service = Mockery::mock(IEmbeddingService::class);
    $embedding_service->shouldNotReceive('embedDocumentsBatch');
    app()->instance(IEmbeddingService::class, $embedding_service);

    $this->artisan('ai:embeddings:repair', [
        'model' => EmbeddableTestModel::class,
        '--sync' => true,
    ])->assertSuccessful();

    expect($model->embeddings()->count())->toBe(1)
        ->and($model->embeddings()->first()->model_key)->toBe('legacy-model');
});

it('--stale regenerates records whose embeddings carry a non-active model_key, leaving active ones untouched', function (): void {
    fakeHealthyEmbeddingService();

    $active_key = app(EmbeddingModelRegistry::class)->active()->key;

    $stale = new EmbeddableTestModel(['title' => 'Stale content']);
    $stale->saveQuietly();
    $stale->embeddings()->create(['embedding' => [0.1, 0.1], 'model_key' => 'legacy-model']);

    $fresh = new EmbeddableTestModel(['title' => 'Fresh content']);
    $fresh->saveQuietly();
    $fresh->embeddings()->create(['embedding' => [0.5, 0.5], 'model_key' => $active_key]);

    $embedding_service = Mockery::mock(IEmbeddingService::class);
    stubRepairEmbedBatch($embedding_service, ['Stale content' => [0.9, 0.9]]);
    app()->instance(IEmbeddingService::class, $embedding_service);

    $this->artisan('ai:embeddings:repair', [
        'model' => EmbeddableTestModel::class,
        '--sync' => true,
        '--stale' => true,
    ])->assertSuccessful();

    $stale_rows = $stale->embeddings()->get();
    $fresh_rows = $fresh->embeddings()->get();

    expect($stale_rows)->toHaveCount(1)
        ->and($stale_rows->first()->model_key)->toBe($active_key)
        ->and($stale_rows->first()->embedding)->toBe([0.9, 0.9])
        ->and($fresh_rows)->toHaveCount(1)
        ->and($fresh_rows->first()->embedding)->toBe([0.5, 0.5])
        ->and($fresh_rows->first()->model_key)->toBe($active_key);
});

it('--stale converges: a record kept by another configured profile is regenerated once, then no longer selected', function (): void {
    fakeHealthyEmbeddingService();

    $other_key = 'openai_compatible:all-MiniLM-L6-v2';
    $active_key = app(EmbeddingModelRegistry::class)->active()->key;

    $record = new EmbeddableTestModel(['title' => 'Switched content']);
    $record->saveQuietly();
    $record->embeddings()->create(['embedding' => [0.1, 0.1], 'model_key' => $other_key]);

    $embedding_service = Mockery::mock(IEmbeddingService::class);
    stubRepairEmbedBatch($embedding_service, ['Switched content' => [0.9, 0.9]]);
    app()->instance(IEmbeddingService::class, $embedding_service);

    $options = ['model' => EmbeddableTestModel::class, '--sync' => true, '--stale' => true];

    $this->artisan('ai:embeddings:repair', $options)
        ->expectsOutputToContain('dispatched for 1 record(s)')
        ->assertSuccessful();

    $this->artisan('ai:embeddings:repair', $options)
        ->expectsOutputToContain('dispatched for 0 record(s)')
        ->assertSuccessful();

    $keys = $record->embeddings()->orderBy('id')->pluck('model_key')->all();

    expect($keys)->toBe([$other_key, $active_key]);
});

it('refuses to repair when the embedding server /info reports a different model than the active profile', function (): void {
    Http::fake([
        '*/info' => Http::response(['model_id' => 'some-other-model']),
        '*/v1/embeddings' => repairProbeAnswer(),
    ]);

    $model = new EmbeddableTestModel(['title' => 'Gamma']);
    $model->saveQuietly();

    $embedding_service = Mockery::mock(IEmbeddingService::class);
    $embedding_service->shouldNotReceive('embedDocumentsBatch');
    app()->instance(IEmbeddingService::class, $embedding_service);

    $active = app(EmbeddingModelRegistry::class)->active();

    [$exit_code, $output] = runRepairEmbeddingsCommand([
        'model' => EmbeddableTestModel::class,
        '--sync' => true,
    ]);

    expect($exit_code)->toBe(RepairMissingEmbeddingsCommand::FAILURE)
        ->and($output)->toContain('some-other-model')
        ->and($output)->toContain($active->serviceModel)
        ->and($model->embeddings()->count())->toBe(0);
});

it('succeeds without a warning when the embedding server /info cannot be reached, the probe answer being enough', function (): void {
    Http::fake([
        '*/info' => Http::response(null, 500),
        '*/v1/embeddings' => repairProbeAnswer(),
    ]);

    $model = new EmbeddableTestModel(['title' => 'Delta']);
    $model->saveQuietly();

    $embedding_service = Mockery::mock(IEmbeddingService::class);
    stubRepairEmbedBatch($embedding_service, ['Delta' => [0.4, 0.4]]);
    app()->instance(IEmbeddingService::class, $embedding_service);

    [$exit_code, $output] = runRepairEmbeddingsCommand([
        'model' => EmbeddableTestModel::class,
        '--sync' => true,
    ]);

    expect($exit_code)->toBe(RepairMissingEmbeddingsCommand::SUCCESS)
        ->and($output)->not->toContain('Could not verify');
});

it('is scheduled hourly for every embeddable model, once at a time and only when idle', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(static fn ($event): bool => str_contains((string) $event->command, 'ai:embeddings:repair'));

    expect($event)->not->toBeNull()
        ->and($event->command)->toContain('--all')
        ->and($event->command)->toContain('--if-idle')
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});

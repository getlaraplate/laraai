<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Ai\Embeddings\EmbeddingDimensionProbe;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchPreview;
use Modules\AI\Contracts\IEmbeddableModels;
use Modules\AI\Tests\Stubs\EmbeddableTestModel;
use Modules\AI\Tests\Stubs\TranslatedEmbeddableTestModel;
use Modules\AI\Tests\Stubs\TranslatedEmbeddableTestModelTranslation;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('ai.servers.embeddings.url', 'http://localhost:8000');
    config()->set('ai.features.embeddings.models', [
        'openai_compatible:intfloat/multilingual-e5-small' => ['dimensions' => 384],
        'openai_compatible:all-MiniLM-L6-v2' => ['dimensions' => 384],
        'openai_compatible:BAAI/bge-m3' => ['dimensions' => 1024],
    ]);
    config()->set('core.search.vector.model', 'openai_compatible:intfloat/multilingual-e5-small');

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

/**
 * A preview over $models.
 *
 * @param  list<class-string>  $models
 */
function switch_preview(array $models = []): EmbeddingSwitchPreview
{
    $embeddable = Mockery::mock(IEmbeddableModels::class);
    $embeddable->shouldReceive('all')->andReturn($models);

    return new EmbeddingSwitchPreview(app(EmbeddingModelRegistry::class), $embeddable);
}

it('reports equal dimensions as a re-embed only', function (): void {
    $registry = app(EmbeddingModelRegistry::class);

    $preview = switch_preview()->for($registry->get('openai_compatible:all-MiniLM-L6-v2'));

    expect($preview->currentModel)->toBe('openai_compatible:intfloat/multilingual-e5-small')
        ->and($preview->targetModel)->toBe('openai_compatible:all-MiniLM-L6-v2')
        ->and($preview->currentDimensions)->toBe(384)
        ->and($preview->targetDimensions)->toBe(384)
        ->and($preview->dimensionsDiffer)->toBeFalse();
});

it('reports the declared target dimensions when they differ', function (): void {
    $registry = app(EmbeddingModelRegistry::class);

    $preview = switch_preview()->for($registry->get('openai_compatible:BAAI/bge-m3'));

    expect($preview->targetDimensions)->toBe(1024)
        ->and($preview->dimensionsDiffer)->toBeTrue();
});

it('counts the searchable records times their translations', function (): void {
    $first = new TranslatedEmbeddableTestModel();
    $first->saveQuietly();
    $second = new TranslatedEmbeddableTestModel();
    $second->saveQuietly();

    foreach ([[$first, 'en'], [$first, 'it'], [$second, 'en']] as [$record, $locale]) {
        TranslatedEmbeddableTestModelTranslation::query()->create([
            'translated_embeddable_test_model_id' => $record->getKey(),
            'locale' => $locale,
            'title' => 'A title',
        ]);
    }

    (new EmbeddableTestModel(['title' => 'One']))->saveQuietly();
    (new EmbeddableTestModel(['title' => 'Two']))->saveQuietly();

    Http::fake(['*/embed' => Http::response(['model' => 'all-MiniLM-L6-v2', 'embeddings' => [array_fill(0, 384, 0.1)]])]);
    $registry = app(EmbeddingModelRegistry::class);
    $preview = switch_preview([TranslatedEmbeddableTestModel::class, EmbeddableTestModel::class])
        ->for($registry->get('openai_compatible:all-MiniLM-L6-v2'));

    expect($preview->recordsToEmbed)->toBe(5)
        ->and($preview->estimatedSeconds)->toBeInt();
});

it('gives no estimate, after a short timeout, when the service cannot be measured', function (): void {
    $timeouts = [];
    Http::fake(['*/embed' => static function ($request, array $options) use (&$timeouts): never {
        $timeouts[] = $options['timeout'] ?? null;

        throw new ConnectionException('timed out');
    }]);
    $registry = app(EmbeddingModelRegistry::class);

    $preview = switch_preview()->for($registry->get('openai_compatible:all-MiniLM-L6-v2'));

    expect($preview->estimatedSeconds)->toBeNull()
        ->and($timeouts)->toBe([EmbeddingSwitchPreview::LATENCY_TIMEOUT_SECONDS])
        ->and(config('ai.servers.embeddings.timeout'))->not->toBe(EmbeddingSwitchPreview::LATENCY_TIMEOUT_SECONDS);
});

it('measures again once a failed measurement has expired from the cache', function (): void {
    $reachable = false;
    Http::fake(['*/embed' => static function () use (&$reachable) {
        if (! $reachable) {
            throw new ConnectionException('timed out');
        }

        return Http::response(['model' => 'all-MiniLM-L6-v2', 'embeddings' => [array_fill(0, 384, 0.1)]]);
    }]);
    $target = app(EmbeddingModelRegistry::class)->get('openai_compatible:all-MiniLM-L6-v2');
    $preview = switch_preview();

    expect($preview->for($target)->estimatedSeconds)->toBeNull();

    $reachable = true;
    expect($preview->for($target)->estimatedSeconds)->toBeNull();

    $this->travel(31)->seconds();
    expect($preview->for($target)->estimatedSeconds)->toBeInt();
});

it('does not measure a hosted provider: no estimate and no call to its client', function (): void {
    config()->set('ai.features.embeddings.models.openai:text-embedding-3-small', ['dimensions' => 1536]);
    Http::fake();
    $client = Mockery::mock(EmbeddingsProviderInterface::class);
    $client->shouldNotReceive('embedText');
    app()->instance(EmbeddingDimensionProbe::class, new EmbeddingDimensionProbe(
        app(EmbeddingModelRegistry::class),
        static fn (): EmbeddingsProviderInterface => $client,
    ));
    $embeddable = Mockery::mock(IEmbeddableModels::class);
    $embeddable->shouldReceive('all')->andReturn([]);
    app()->instance(IEmbeddableModels::class, $embeddable);

    $preview = app(EmbeddingSwitchPreview::class)->for(app(EmbeddingModelRegistry::class)->get('openai:text-embedding-3-small'));

    expect($preview->estimatedSeconds)->toBeNull()
        ->and($preview->targetDimensions)->toBe(1536);
    Http::assertNothingSent();
});

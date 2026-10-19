<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Services\ModelEmbeddingSynchronizer;
use Modules\AI\Tests\Stubs\EmbeddableTestModel;
use Modules\Core\Models\ModelEmbedding;
use NeuronAI\RAG\Document;

beforeEach(function (): void {
    config()->set('ai.features.embeddings.models.sentence_transformers:intfloat/multilingual-e5-small.dimensions', 2);
    Schema::create('embeddable_test_models', function ($table): void {
        $table->id();
        $table->string('title')->nullable();
    });
});

/**
 * @param  list<list<float>>  $vectors  one vector per text the service is expected to receive
 */
function vectorReuseService(int $expectedTexts, array $vectors): IEmbeddingService
{
    $service = Mockery::mock(IEmbeddingService::class);
    $service->shouldReceive('embedDocumentsBatch')
        ->once()
        ->withArgs(static fn (array $texts): bool => $expectedTexts === count($texts))
        ->andReturnUsing(static fn (array $texts): array => array_map(static function (int $index) use ($vectors): array {
            $document = new Document('');
            $document->setEmbedding($vectors[$index]);

            return [$document];
        }, array_keys($texts)));

    return $service;
}

function vectorReuseSync(IEmbeddingService $service, array $models): void
{
    (new ModelEmbeddingSynchronizer($service, app(EmbeddingModelRegistry::class)))
        ->sync($models, announceCompletion: false);
}

function vectorReuseModel(string $title): EmbeddableTestModel
{
    $model = new EmbeddableTestModel(['title' => $title]);
    $model->saveQuietly();

    return $model;
}

it('reuses the vectors of an identical text already embedded for another model', function (): void {
    $first = vectorReuseModel('Same caption');
    $copy = vectorReuseModel('Same caption');

    vectorReuseSync(vectorReuseService(1, [[0.1, 0.2]]), [$first]);

    $idle = Mockery::mock(IEmbeddingService::class);
    $idle->shouldNotReceive('embedDocumentsBatch');
    vectorReuseSync($idle, [$copy]);

    $rows = ModelEmbedding::query()->forModel($copy)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->embedding)->toEqual([0.1, 0.2])
        ->and(ModelEmbedding::query()->forModel($first)->count())->toBe(1);
});

it('embeds an identical text once when both models are in the same run', function (): void {
    $first = vectorReuseModel('Same caption');
    $copy = vectorReuseModel('Same caption');

    vectorReuseSync(vectorReuseService(1, [[0.3, 0.4]]), [$first, $copy]);

    expect(ModelEmbedding::query()->forModel($first)->first()->embedding)->toEqual([0.3, 0.4])
        ->and(ModelEmbedding::query()->forModel($copy)->first()->embedding)->toEqual([0.3, 0.4]);
});

it('does not reuse vectors produced by another embedding model', function (): void {
    $first = vectorReuseModel('Same caption');
    vectorReuseSync(vectorReuseService(1, [[0.1, 0.2]]), [$first]);
    ModelEmbedding::query()->forModel($first)->update(['model_key' => 'retired-model']);

    $copy = vectorReuseModel('Same caption');
    vectorReuseSync(vectorReuseService(1, [[0.9, 0.9]]), [$copy]);

    expect(ModelEmbedding::query()->forModel($copy)->first()->embedding)->toEqual([0.9, 0.9]);
});

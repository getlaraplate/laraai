<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Ai\Embeddings\EmbeddingDimensionMismatch;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\AI\Services\ModelEmbeddingSynchronizer;
use Modules\AI\Tests\Stubs\EmbeddableTestModel;
use Modules\Core\Events\ModelPreProcessingCompleted;
use Modules\Core\Models\ModelEmbedding;
use NeuronAI\RAG\Document;

const PROFILE_ACTIVE = 'sentence_transformers:intfloat/multilingual-e5-small';
const PROFILE_OTHER = 'sentence_transformers:all-MiniLM-L6-v2';

beforeEach(function (): void {
    Event::fake([ModelPreProcessingCompleted::class]);
    config()->set('core.search.vector.model', PROFILE_ACTIVE);

    Schema::create('embeddable_test_models', function ($table): void {
        $table->id();
        $table->string('title')->nullable();
    });
});

/**
 * @param  list<float>  $vector
 */
function profileTestService(array $vector): IEmbeddingService
{
    $service = Mockery::mock(IEmbeddingService::class);
    $service->shouldReceive('embedDocumentsBatch')
        ->andReturnUsing(static fn (array $texts): array => array_map(static function () use ($vector): array {
            $document = new Document('');
            $document->setEmbedding($vector);

            return [$document];
        }, $texts));

    return $service;
}

function profileTestModel(): EmbeddableTestModel
{
    $model = new EmbeddableTestModel(['title' => 'A title']);
    $model->saveQuietly();

    return $model;
}

it('stamps the explicit profile and leaves the active profile rows untouched', function (): void {
    $model = profileTestModel();
    $vector = array_fill(0, 384, 0.5);

    (new GenerateEmbeddingsJob($model))->handle(profileTestService($vector));
    expect(ModelEmbedding::query()->forModel($model)->pluck('model_key')->all())->toBe([PROFILE_ACTIVE]);

    (new GenerateEmbeddingsJob($model, null, PROFILE_OTHER))->handle(profileTestService($vector));

    $keys = ModelEmbedding::query()->forModel($model)->orderBy('id')->pluck('model_key')->all();
    expect($keys)->toContain(PROFILE_OTHER)
        ->and(ModelEmbedding::query()->forModel($model)->where('model_key', PROFILE_ACTIVE)->count())->toBe(1);
});

it('stamps the active profile when no profile is given', function (): void {
    $model = profileTestModel();

    (new GenerateEmbeddingsJob($model))->handle(profileTestService(array_fill(0, 384, 0.1)));

    expect(ModelEmbedding::query()->forModel($model)->pluck('model_key')->unique()->all())->toBe([PROFILE_ACTIVE]);
});

it('keeps the profile through queue serialization', function (): void {
    $job = new GenerateEmbeddingsJob(profileTestModel(), 'it', PROFILE_OTHER);

    $restored = unserialize(serialize($job));

    expect((fn (): ?string => $this->profile)->call($restored))->toBe(PROFILE_OTHER);
});

it('refuses to store a vector whose length differs from the profile', function (): void {
    $model = profileTestModel();
    $synchronizer = new ModelEmbeddingSynchronizer(profileTestService([0.1, 0.2, 0.3, 0.4, 0.5]), app(EmbeddingModelRegistry::class));

    expect(fn () => $synchronizer->sync([$model], announceCompletion: false))->toThrow(EmbeddingDimensionMismatch::class)
        ->and(ModelEmbedding::query()->forModel($model)->count())->toBe(0);
});

it('skips a row whose content hash and model key already match', function (): void {
    $model = profileTestModel();
    $vector = array_fill(0, 384, 0.5);

    (new GenerateEmbeddingsJob($model))->handle(profileTestService($vector));

    $idle = Mockery::mock(IEmbeddingService::class);
    $idle->shouldNotReceive('embedDocumentsBatch');
    (new GenerateEmbeddingsJob($model))->handle($idle);

    expect(ModelEmbedding::query()->forModel($model)->count())->toBe(1);
});

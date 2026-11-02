<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchCorpus;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Services\ModelEmbeddingSynchronizer;
use Modules\AI\Tests\Stubs\HeaderedEmbeddableTestModel;
use Modules\Core\Models\ModelEmbedding;
use NeuronAI\RAG\Document;

beforeEach(function (): void {
    config()->set('ai.features.embeddings.models.openai_compatible:intfloat/multilingual-e5-small.dimensions', 2);
    Schema::create('embeddable_test_models', function ($table): void {
        $table->id();
        $table->string('title')->nullable();
        $table->string('context_header')->nullable();
    });
});

/**
 * An embedding service expecting one batched call, recording the texts and headers it receives.
 *
 * @param  ArrayObject<int, array{texts: list<string>, headers: array<int, string|null>}>  $calls
 */
function headerRecordingService(ArrayObject $calls): IEmbeddingService
{
    $service = Mockery::mock(IEmbeddingService::class);
    $service->shouldReceive('embedDocumentsBatch')
        ->once()
        ->andReturnUsing(static function (array $texts, array $headers = []) use ($calls): array {
            $calls->append(['texts' => $texts, 'headers' => $headers]);

            return array_map(static function (): array {
                $document = new Document('chunk');
                $document->setEmbedding([0.1, 0.2]);

                return [$document];
            }, $texts);
        });

    return $service;
}

function headerSync(IEmbeddingService $service, HeaderedEmbeddableTestModel $model): void
{
    (new ModelEmbeddingSynchronizer($service, app(EmbeddingModelRegistry::class)))
        ->sync([$model], announceCompletion: false);
}

function idleEmbeddingService(): IEmbeddingService
{
    $service = Mockery::mock(IEmbeddingService::class);
    $service->shouldNotReceive('embedDocumentsBatch');

    return $service;
}

it('keeps the content hash of a text without header unchanged', function (): void {
    expect(ModelEmbeddingSynchronizer::contentHash('Summer festival'))->toBe(hash('sha256', 'Summer festival'))
        ->and(ModelEmbeddingSynchronizer::contentHash('Summer festival', null))->toBe(hash('sha256', 'Summer festival'))
        ->and(ModelEmbeddingSynchronizer::contentHash('Summer festival', 'news | Summer festival'))->not->toBe(hash('sha256', 'Summer festival'));
});

it('sends the header with the text and stores a hash that includes it', function (): void {
    $model = new HeaderedEmbeddableTestModel(['title' => 'Summer festival', 'context_header' => "news |  Summer\nfestival"]);
    $model->saveQuietly();
    $calls = new ArrayObject();

    headerSync(headerRecordingService($calls), $model);

    expect($calls[0]['texts'])->toBe(['Summer festival'])
        ->and($calls[0]['headers'])->toBe([0 => 'news | Summer festival'])
        ->and(ModelEmbedding::query()->forModel($model)->value('content_hash'))
        ->toBe(ModelEmbeddingSynchronizer::contentHash('Summer festival', 'news | Summer festival'));
});

it('changing the header changes the content hash, so the locale is re-embedded', function (): void {
    $model = new HeaderedEmbeddableTestModel(['title' => 'Summer festival', 'context_header' => 'news | Summer festival']);
    $model->saveQuietly();
    headerSync(headerRecordingService(new ArrayObject()), $model);

    // Same text, same header: fresh, nothing is embedded.
    headerSync(idleEmbeddingService(), $model);

    $model->forceFill(['context_header' => 'events | Summer festival'])->saveQuietly();
    $changed = new ArrayObject();
    headerSync(headerRecordingService($changed), $model);

    $model->forceFill(['context_header' => null])->saveQuietly();
    $removed = new ArrayObject();
    headerSync(headerRecordingService($removed), $model);

    expect($changed[0]['headers'])->toBe([0 => 'events | Summer festival'])
        ->and($removed[0]['texts'])->toBe(['Summer festival'])
        ->and(ModelEmbedding::query()->forModel($model)->pluck('content_hash')->all())
        ->toBe([hash('sha256', 'Summer festival')]);
});

it('counts a record embedded with its header as done in the switch corpus', function (): void {
    $model = new HeaderedEmbeddableTestModel(['title' => 'Summer festival', 'context_header' => 'news | Summer festival']);
    $model->saveQuietly();
    headerSync(headerRecordingService(new ArrayObject()), $model);

    $progress = app(EmbeddingSwitchCorpus::class)
        ->progress(app(EmbeddingModelRegistry::class)->active()->key, HeaderedEmbeddableTestModel::class);

    expect($progress['total'])->toBe(1)
        ->and($progress['done'])->toBe(1);
});

<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\AI\Tests\Stubs\EmbeddableTestModel;
use Modules\Core\Events\ModelPreProcessingCompleted;
use NeuronAI\RAG\Document;

beforeEach(function (): void {
    config()->set('ai.features.embeddings.models.openai_compatible:intfloat/multilingual-e5-small.dimensions', 2);
    Log::spy();
});

it('has correct properties', function (): void {
    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 1;
    $model->shouldReceive('getTable')->andReturn('test');

    $job = new GenerateEmbeddingsJob($model);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([30, 60, 120, 240])
        ->and($job->timeout)->toBe(300)
        ->and($job->maxExceptions)->toBe(5);
});

it('spends its exception budget inside the ten minutes the indexing coordination event lives', function (): void {
    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 1;
    $model->shouldReceive('getTable')->andReturn('test');

    $job = new GenerateEmbeddingsJob($model);

    // HandleModelIndexingListener keeps the event that FinalizeModelIndexingListener needs for ten
    // minutes. A job that fails after the budget (maxExceptions - 1 backoffs between the attempts)
    // must do so before that, or the document is indexed through the late fallback path.
    $window = array_sum(array_slice($job->backoff, 0, $job->maxExceptions - 1));

    expect($window)->toBeLessThan(10 * 60)
        ->and(count($job->backoff))->toBeGreaterThanOrEqual($job->maxExceptions - 1);
});

it('uses a future time-based retryUntil so rate-limit releases do not kill the job', function (): void {
    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 1;
    $model->shouldReceive('getTable')->andReturn('test');

    $job = new GenerateEmbeddingsJob($model);

    expect($job->retryUntil())->toBeInstanceOf(DateTimeInterface::class)
        ->and($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->getTimestamp());
});

it('middleware is the embeddings rate limiter alone: nothing catches an embedding error on the way', function (): void {
    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 1;
    $model->shouldReceive('getTable')->andReturn('test');

    $middleware = (new GenerateEmbeddingsJob($model))->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(Illuminate\Queue\Middleware\RateLimited::class);
});

it('has no middleware at all when unthrottled', function (): void {
    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 1;
    $model->shouldReceive('getTable')->andReturn('test');

    expect((new GenerateEmbeddingsJob($model))->unthrottled()->middleware())->toBe([]);
});

it('lets an embedding error reach the worker, so $maxExceptions can fail the job and failed() can degrade it', function (): void {
    Event::fake([ModelPreProcessingCompleted::class]);

    Schema::create('embeddable_test_models', function ($table): void {
        $table->id();
        $table->string('title')->nullable();
    });

    $model = new EmbeddableTestModel(['title' => 'Alpha']);
    $model->saveQuietly();

    $embedding_service = Mockery::mock(IEmbeddingService::class);
    $embedding_service->shouldReceive('embedDocumentsBatch')->andThrow(new RuntimeException('embedding service down'));
    app()->instance(IEmbeddingService::class, $embedding_service);

    // A catch-and-release middleware used to turn this into a silent retry that never counted as an
    // exception, so the job waited out its 24 hour retryUntil without ever failing.
    expect(fn () => dispatch_sync(new GenerateEmbeddingsJob($model)))
        ->toThrow(RuntimeException::class, 'embedding service down');
});

it('returns early when prepareDataToEmbedByLocale returns an empty array', function (): void {
    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 1;
    $model->shouldReceive('getTable')->andReturn('test');
    $model->shouldReceive('prepareDataToEmbedByLocale')->with(null)->andReturn([]);

    $embedding_service = Mockery::mock(IEmbeddingService::class);
    $embedding_service->shouldNotReceive('embedDocument');

    $job = new GenerateEmbeddingsJob($model);
    $job->handle($embedding_service);
});

it('replaces existing embeddings then creates records stamped with locale and model_key (idempotent regeneration)', function (): void {
    Event::fake([ModelPreProcessingCompleted::class]);

    $default_locale = (string) (config('app.locale') ?: 'en');
    $expected_model_key = app(EmbeddingModelRegistry::class)->active()->key;

    // A regeneration with no fresh existing row must delete the model's previous
    // embeddings for the locale before creating the fresh set, otherwise retries
    // append duplicate ModelEmbedding rows. Non-translated model: the default-locale
    // key maps to a null `locale` column, and the embedded text is hash-stamped.
    $expected_hash = hash('sha256', 'Some text to embed');

    $embeddingRelation = Mockery::mock();
    $embeddingRelation->shouldReceive('get')->andReturn(collect());
    $embeddingRelation->shouldReceive('forLocale')->andReturnSelf();
    $embeddingRelation->shouldReceive('where')->andReturnSelf();
    $embeddingRelation->shouldReceive('delete')->once();
    $embeddingRelation->shouldReceive('create')
        ->once()
        ->with(['embedding' => [0.1, 0.2], 'locale' => null, 'model_key' => $expected_model_key, 'content_hash' => $expected_hash])
        ->andReturn(null);

    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 1;
    $model->shouldReceive('getTable')->andReturn('test');
    $model->shouldReceive('prepareDataToEmbedByLocale')->with(null)->andReturn([$default_locale => 'Some text to embed']);
    $model->shouldReceive('embeddings')->andReturn($embeddingRelation);
    $model->shouldReceive('fresh')->andReturn($model);

    $document = new Document('');
    $document->setEmbedding([0.1, 0.2]);

    $embedding_service = Mockery::mock(IEmbeddingService::class);
    $embedding_service->shouldReceive('embedDocumentsBatch')
        ->once()
        ->with(['Some text to embed'])
        ->andReturn([[$document]]);

    $job = new GenerateEmbeddingsJob($model);
    $job->handle($embedding_service);

    Event::assertDispatched(ModelPreProcessingCompleted::class);
});

it('failed dispatches ModelPreProcessingCompleted so indexing proceeds without the vector', function (): void {
    // On permanent failure the document must still be indexed (keyword-only),
    // so the finalize listener needs the completion signal to fire.
    Event::fake([ModelPreProcessingCompleted::class]);

    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 7;
    $model->shouldReceive('getTable')->andReturn('test');

    $job = new GenerateEmbeddingsJob($model);
    $job->failed(new Exception('boom'));

    Event::assertDispatched(
        ModelPreProcessingCompleted::class,
        fn (ModelPreProcessingCompleted $event): bool => $event->model === $model && $event->processing_type === 'embeddings',
    );
});

it('failed logs error', function (): void {
    Event::fake([ModelPreProcessingCompleted::class]);

    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 1;

    $job = new GenerateEmbeddingsJob($model);
    $exception = new Exception('Job failed');

    $job->failed($exception);

    Log::shouldHaveReceived('error')
        ->once()
        ->with('GenerateEmbeddingsJob failed', Mockery::on(fn (array $context): bool => isset($context['model'], $context['model_id'], $context['error'])
            && $context['model_id'] === 1
            && $context['error'] === 'Job failed'));
});

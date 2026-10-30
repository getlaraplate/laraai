<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\AI\Listeners\HandleModelIndexingListener;
use Modules\AI\Tests\Stubs\CompoundKeySearchableModel;
use Modules\AI\Tests\Unit\SearchableModelStub;
use Modules\Core\Events\ModelRequiresIndexing;

beforeEach(function (): void {
    Config::set('ai.features.embeddings.enabled', true);
    Queue::fake();
});

/**
 * Helper to override the runningInConsole flag on the Application instance.
 */
function setRunningInConsole(Application $app, bool $value): void
{
    $prop = new ReflectionProperty(Application::class, 'isRunningInConsole');
    $prop->setAccessible(true);
    $prop->setValue($app, $value);
}

it('does nothing when embeddings feature disabled', function (): void {
    Config::set('ai.features.embeddings.enabled', false);

    $model = new SearchableModelStub;
    $model->id = 1;

    $event = new ModelRequiresIndexing($model, false);
    $listener = app(HandleModelIndexingListener::class);
    $listener->handle($event);

    Queue::assertNothingPushed();
});

it('does nothing when model does not support embeddings', function (): void {
    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 1;
    $model->shouldReceive('getTable')->andReturn('test');

    $event = new ModelRequiresIndexing($model, false);
    $listener = app(HandleModelIndexingListener::class);
    $listener->handle($event);

    Queue::assertNothingPushed();
});

it('dispatches GenerateEmbeddingsJob for async', function (): void {
    $model = new SearchableModelStub;
    $model->id = 1;

    $event = new ModelRequiresIndexing($model, false);
    $listener = app(HandleModelIndexingListener::class);
    $listener->handle($event);

    Queue::assertPushed(GenerateEmbeddingsJob::class);
    expect($event->required_pre_processing)->toContain('embeddings');
});

it('runs GenerateEmbeddingsJob sync when event sync is true in CLI context', function (): void {
    // Tests run in CLI context (runningInConsole() === true), so sync=true executes inline.
    $model = new SearchableModelStub;
    $model->id = 1;

    $event = new ModelRequiresIndexing($model, true);
    $listener = app(HandleModelIndexingListener::class);
    $listener->handle($event);

    Queue::assertNothingPushed();
    expect($event->required_pre_processing)->toContain('embeddings');
});

it('adds embeddings to required pre-processing', function (): void {
    $model = new SearchableModelStub;
    $model->id = 1;

    $event = new ModelRequiresIndexing($model, false);
    $listener = app(HandleModelIndexingListener::class);
    $listener->handle($event);

    expect($event->required_pre_processing)->toContain('embeddings');
});

it('skips event cache when model key is not scalar', function (): void {
    Cache::spy();

    $model = new CompoundKeySearchableModel;

    $event = new ModelRequiresIndexing($model, false);
    $listener = app(HandleModelIndexingListener::class);
    $listener->handle($event);

    Queue::assertPushed(GenerateEmbeddingsJob::class);
    Cache::shouldNotHaveReceived('put');
});

// Property 19: Web-context sync indexing is always dispatched asynchronously
// Validates: Requirements 14.1
it('dispatches GenerateEmbeddingsJob async when sync=true in web context', function (): void {
    // Feature: performance-optimization, Property 19: Web-context sync indexing is always dispatched asynchronously
    setRunningInConsole($this->app, false);

    $model = new SearchableModelStub;
    $model->id = 1;

    $event = new ModelRequiresIndexing($model, true);
    $listener = app(HandleModelIndexingListener::class);
    $listener->handle($event);

    Queue::assertPushed(GenerateEmbeddingsJob::class);
    expect($event->required_pre_processing)->toContain('embeddings');
});

// Property 20: CLI-context sync indexing executes synchronously
// Validates: Requirements 14.2
it('executes GenerateEmbeddingsJob synchronously when sync=true in CLI context', function (): void {
    // Feature: performance-optimization, Property 20: CLI-context sync indexing executes synchronously
    setRunningInConsole($this->app, true);

    $model = new SearchableModelStub;
    $model->id = 1;

    $event = new ModelRequiresIndexing($model, true);
    $listener = app(HandleModelIndexingListener::class);
    $listener->handle($event);

    // Sync execution does not push to the queue.
    Queue::assertNothingPushed();
    expect($event->required_pre_processing)->toContain('embeddings');
});

it('does nothing when the embeddings module allowlist excludes the model module', function (): void {
    // SearchableModelStub is Modules\AI\..., so a CMS-only allowlist excludes it.
    Config::set('ai.features.embeddings.modules', ['cms']);

    $model = new SearchableModelStub;
    $model->id = 1;

    $event = new ModelRequiresIndexing($model, false);
    app(HandleModelIndexingListener::class)->handle($event);

    Queue::assertNothingPushed();
});

it('dispatches when the embeddings module allowlist includes the model module', function (): void {
    Config::set('ai.features.embeddings.modules', ['ai']);

    $model = new SearchableModelStub;
    $model->id = 1;

    $event = new ModelRequiresIndexing($model, false);
    app(HandleModelIndexingListener::class)->handle($event);

    Queue::assertPushed(GenerateEmbeddingsJob::class);
});

it('does nothing while the embeddings setting is not there at all, like the seeded default which is off', function (): void {
    config()->set('ai.features.embeddings', []);

    $model = new SearchableModelStub;
    $model->id = 1;

    app(HandleModelIndexingListener::class)->handle(new ModelRequiresIndexing($model, false));

    Queue::assertNothingPushed();
});

it('dispatches for an embeddable model with only the embeddings switch on and a profile configured', function (): void {
    config()->set('ai.features.embeddings.serving_model', 'openai_compatible:intfloat/multilingual-e5-small');
    config()->set('core.search.vector', []);

    $model = new SearchableModelStub;
    $model->id = 1;

    $event = new ModelRequiresIndexing($model, false);
    app(HandleModelIndexingListener::class)->handle($event);

    Queue::assertPushed(GenerateEmbeddingsJob::class);
    expect($event->required_pre_processing)->toContain('embeddings');
});

it('does nothing while the active profile is not configured', function (): void {
    config()->set('ai.features.embeddings.serving_model', 'nope:missing');

    $model = new SearchableModelStub;
    $model->id = 1;

    app(HandleModelIndexingListener::class)->handle(new ModelRequiresIndexing($model, false));

    Queue::assertNothingPushed();
});

<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue as QueueConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Ai\Embeddings\EmbeddingDimensionProbe;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchOrchestrator;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchState;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchStore;
use Modules\AI\Contracts\IEmbeddableModels;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Database\Seeders\AIDatabaseSeeder;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\AI\Jobs\SwitchEmbeddingModelJob;
use Modules\AI\Tests\Stubs\EmbeddableTestModel;
use Modules\Core\Events\ModelPreProcessingCompleted;
use Modules\Core\Models\ModelEmbedding;
use Modules\Core\Models\Setting;
use Modules\Core\Search\Contracts\IVectorSearchAvailability;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

const SWITCH_ACTIVE = 'openai_compatible:intfloat/multilingual-e5-small';
const SWITCH_TARGET = 'openai_compatible:all-MiniLM-L6-v2';

beforeEach(function (): void {
    config()->set('ai.servers.embeddings.url', 'http://localhost:8000');
    $this->seed(AIDatabaseSeeder::class);

    config()->set('ai.features.embeddings.serving_model', SWITCH_ACTIVE);
    config()->set('ai.features.embeddings.switch', null);

    Schema::create('embeddable_test_models', function ($table): void {
        $table->id();
        $table->string('title')->nullable();
    });
});

/**
 * The probe's provider answers with $dimensions components, or throws when null.
 */
function switch_probe_measures(?int $dimensions): void
{
    $provider = Mockery::mock(EmbeddingsProviderInterface::class);

    if ($dimensions === null) {
        $provider->shouldReceive('embedText')->andThrow(new RuntimeException('connection refused'));
    } else {
        $provider->shouldReceive('embedText')->andReturn(array_fill(0, $dimensions, 0.1));
    }

    app()->instance(EmbeddingDimensionProbe::class, new EmbeddingDimensionProbe(
        app(EmbeddingModelRegistry::class),
        static fn (): EmbeddingsProviderInterface => $provider,
    ));
}

/**
 * The embeddings server reports $model on /info and in the /v1/embeddings answer.
 */
function switch_service_reports(string $model, int $dimensions = 384): void
{
    Http::fake([
        '*/info' => Http::response(['model_id' => $model]),
        '*/v1/embeddings' => Http::response(['model' => $model, 'data' => [['index' => 0, 'embedding' => ai_test_unit_vector($dimensions)]]]),
    ]);
}

/**
 * Whether vector search is suspended by the switch, as the search guard sees it.
 */
function switch_suspends_vector_search(): bool
{
    return app(IVectorSearchAvailability::class)->check(new EmbeddableTestModel)->reason === 'suspended';
}

/**
 * A queue connection whose every push answers with `$push`, which may throw.
 */
function switch_queue_pushing(Closure $push): void
{
    $queue = Mockery::mock(QueueConnection::class);
    $queue->shouldReceive('push', 'pushOn', 'later', 'laterOn')->andReturnUsing($push);
    $queues = Mockery::mock(QueueFactory::class);
    $queues->shouldReceive('connection')->andReturn($queue);
    app()->instance(QueueFactory::class, $queues);
}

function switch_expect_nothing_changed(): void
{
    expect(app(EmbeddingSwitchStore::class)->get()->status)->toBe('idle')
        ->and(app(EmbeddingSwitchStore::class)->isSwitching())->toBeFalse()
        ->and(switch_suspends_vector_search())->toBeFalse();

    Queue::assertNothingPushed();
}

it('starts a switch: records the state, suspends vector search and dispatches the job', function (): void {
    Queue::fake();
    switch_probe_measures(384);
    switch_service_reports('sentence-transformers/all-MiniLM-L6-v2');

    $this->artisan('ai:embeddings:switch', ['profile' => SWITCH_TARGET])->assertSuccessful();

    $state = app(EmbeddingSwitchStore::class)->get();

    expect($state->status)->toBe('running')
        ->and($state->phase)->toBe('preflight')
        ->and($state->target)->toBe(SWITCH_TARGET)
        ->and($state->previous)->toBe(SWITCH_ACTIVE)
        ->and($state->startedAt)->not->toBeNull()
        ->and(app(EmbeddingSwitchStore::class)->isSwitching())->toBeTrue()
        ->and(switch_suspends_vector_search())->toBeTrue()
        ->and(Setting::query()->withoutGlobalScopes()->where('name', 'like', 'search.vector.%')->exists())->toBeFalse();

    Queue::assertPushed(SwitchEmbeddingModelJob::class, 1);
    Queue::assertPushedOn(SwitchEmbeddingModelJob::QUEUE, SwitchEmbeddingModelJob::class);
});

it('does not start when the service is unreachable', function (): void {
    Queue::fake();
    switch_probe_measures(null);
    Http::fake(static fn () => throw new ConnectionException('connection refused'));

    $this->artisan('ai:embeddings:switch', ['profile' => SWITCH_TARGET])
        ->expectsOutputToContain('connection refused')
        ->assertFailed();

    switch_expect_nothing_changed();
});

it('starts when /info is unreachable, because the probe answer alone names the model', function (): void {
    Queue::fake();
    switch_probe_measures(384);
    Http::fake([
        '*/info' => static fn () => throw new ConnectionException('info timed out'),
        '*/v1/embeddings' => Http::response(['model' => 'all-MiniLM-L6-v2', 'data' => [['index' => 0, 'embedding' => ai_test_unit_vector(384)]]]),
    ]);

    $this->artisan('ai:embeddings:switch', ['profile' => SWITCH_TARGET])->assertSuccessful();

    expect(app(EmbeddingSwitchStore::class)->get()->status)->toBe('running');
});

it('does not start when the service reports another model', function (): void {
    Queue::fake();
    switch_probe_measures(384);
    switch_service_reports('intfloat/multilingual-e5-small');

    $this->artisan('ai:embeddings:switch', ['profile' => SWITCH_TARGET])
        ->expectsOutputToContain('multilingual-e5-small')
        ->assertFailed();

    switch_expect_nothing_changed();
});

it('does not start when /info and the probe answer name different models', function (): void {
    Queue::fake();
    switch_probe_measures(384);
    Http::fake([
        '*/info' => Http::response(['model_id' => 'intfloat/multilingual-e5-small']),
        '*/v1/embeddings' => Http::response(['model' => 'sentence-transformers/all-MiniLM-L6-v2', 'data' => [['index' => 0, 'embedding' => ai_test_unit_vector(384)]]]),
    ]);

    $this->artisan('ai:embeddings:switch', ['profile' => SWITCH_TARGET])
        ->expectsOutputToContain('/info reports model "intfloat/multilingual-e5-small"')
        ->assertFailed();

    switch_expect_nothing_changed();
});

it('does not start when the service names no model, so it cannot be checked', function (): void {
    Queue::fake();
    switch_probe_measures(384);
    Http::fake([
        '*/info' => Http::response([]),
        '*/v1/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => ai_test_unit_vector(384)]]]),
    ]);

    $this->artisan('ai:embeddings:switch', ['profile' => SWITCH_TARGET])
        ->expectsOutputToContain('does not report which model')
        ->assertFailed();

    switch_expect_nothing_changed();
});

it('does not start when the probe measures other dimensions', function (): void {
    Queue::fake();
    switch_probe_measures(768);
    switch_service_reports('all-MiniLM-L6-v2');

    $this->artisan('ai:embeddings:switch', ['profile' => SWITCH_TARGET])
        ->expectsOutputToContain('declares 384')
        ->assertFailed();

    switch_expect_nothing_changed();
});

it('refuses a profile that is unknown, already active or whose provider is not configured', function (string $profile, string $message): void {
    Queue::fake();
    config()->set('ai.features.embeddings.models.voyageai:voyage-3-lite', ['dimensions' => 512]);
    config()->set('ai.providers.voyageai.api_key', '');
    switch_probe_measures(384);
    switch_service_reports('all-MiniLM-L6-v2');

    $this->artisan('ai:embeddings:switch', ['profile' => $profile])
        ->expectsOutputToContain($message)
        ->assertFailed();

    switch_expect_nothing_changed();
})->with([
    'unknown' => ['nope:nothing', 'Unknown embedding model profile'],
    'active' => [SWITCH_ACTIVE, 'already the active'],
    'unconfigured provider' => ['voyageai:voyage-3-lite', 'not configured'],
]);

it('refuses a second start while another one holds the lock', function (): void {
    Queue::fake();
    switch_probe_measures(384);
    switch_service_reports('all-MiniLM-L6-v2');

    $release = app(EmbeddingSwitchStore::class)->lock();

    try {
        $this->artisan('ai:embeddings:switch', ['profile' => SWITCH_TARGET])
            ->expectsOutputToContain('Another embedding model switch is starting')
            ->assertFailed();
    } finally {
        $release();
    }

    switch_expect_nothing_changed();
});

it('refuses to start while a switch is running or failed', function (string $status): void {
    Queue::fake();
    switch_probe_measures(384);
    switch_service_reports('all-MiniLM-L6-v2');
    $existing = new EmbeddingSwitchState($status, 'embeddings', SWITCH_TARGET, SWITCH_ACTIVE, 10, 3, $status === 'failed' ? 'boom' : null);
    app(EmbeddingSwitchStore::class)->put($existing);

    $this->artisan('ai:embeddings:switch', ['profile' => SWITCH_TARGET])
        ->expectsOutputToContain("already {$status}")
        ->assertFailed();

    expect(app(EmbeddingSwitchStore::class)->get())->toEqual($existing)
        ->and(app(EmbeddingSwitchStore::class)->isSwitching())->toBeTrue();
    Queue::assertNothingPushed();
})->with(['running', 'failed']);

it('records a refused start as a failed preflight when asked to report it', function (): void {
    Queue::fake();
    switch_probe_measures(768);
    switch_service_reports('all-MiniLM-L6-v2');

    $this->artisan('ai:embeddings:switch', ['profile' => SWITCH_TARGET, '--report-failure' => true])
        ->expectsOutputToContain('declares 384')
        ->assertFailed();

    $state = app(EmbeddingSwitchStore::class)->get();

    expect($state->status)->toBe('failed')
        ->and($state->phase)->toBe('preflight')
        ->and($state->target)->toBe(SWITCH_TARGET)
        ->and($state->previous)->toBe(SWITCH_ACTIVE)
        ->and($state->error)->toContain('declares 384')
        ->and(app(EmbeddingSwitchStore::class)->isSwitching())->toBeFalse()
        ->and(switch_suspends_vector_search())->toBeFalse();
    Queue::assertNothingPushed();
});

it('records nothing when a refused start is not asked to report it', function (): void {
    Queue::fake();
    switch_probe_measures(768);
    switch_service_reports('all-MiniLM-L6-v2');

    $this->artisan('ai:embeddings:switch', ['profile' => SWITCH_TARGET])
        ->expectsOutputToContain('declares 384')
        ->assertFailed();

    switch_expect_nothing_changed();
});

it('records nothing for a start that finds the lock held, even when asked to report it', function (?string $holderState): void {
    Queue::fake();
    switch_probe_measures(768);
    switch_service_reports('all-MiniLM-L6-v2');
    $store = app(EmbeddingSwitchStore::class);
    // The holder of the lock is still in its preflight (idle) or has already stored its start (running).
    $before = $holderState === null ? EmbeddingSwitchState::idle() : new EmbeddingSwitchState($holderState, 'preflight', SWITCH_TARGET, SWITCH_ACTIVE);
    $store->put($before);
    $release = $store->lock();

    try {
        $this->artisan('ai:embeddings:switch', ['profile' => SWITCH_TARGET, '--report-failure' => true])
            ->expectsOutputToContain('Another embedding model switch is starting')
            ->assertFailed();
    } finally {
        $release();
    }

    expect($store->get())->toEqual($before)
        ->and($store->isSwitching())->toBe($holderState === 'running');
    Queue::assertNothingPushed();
})->with(['holder in its preflight' => [null], 'holder already started' => ['running']]);

it('records nothing for the already active profile, even when asked to report it', function (): void {
    Queue::fake();

    $this->artisan('ai:embeddings:switch', ['profile' => SWITCH_ACTIVE, '--report-failure' => true])
        ->expectsOutputToContain('already the active')
        ->assertFailed();

    switch_expect_nothing_changed();
});

it('records an unconfigured provider, decided under the lock, as a failed preflight when asked to report it', function (): void {
    Queue::fake();
    config()->set('ai.features.embeddings.models.voyageai:voyage-3-lite', ['dimensions' => 512]);
    config()->set('ai.providers.voyageai.api_key', '');

    $this->artisan('ai:embeddings:switch', ['profile' => 'voyageai:voyage-3-lite', '--report-failure' => true])
        ->expectsOutputToContain('not configured')
        ->assertFailed();

    $state = app(EmbeddingSwitchStore::class)->get();

    expect($state->status)->toBe('failed')
        ->and($state->phase)->toBe('preflight')
        ->and($state->target)->toBe('voyageai:voyage-3-lite')
        ->and($state->error)->toContain('not configured')
        ->and(app(EmbeddingSwitchStore::class)->isSwitching())->toBeFalse()
        ->and(switch_suspends_vector_search())->toBeFalse();
    Queue::assertNothingPushed();
});

it('never replaces a running switch with a reported refusal', function (): void {
    Queue::fake();
    $running = new EmbeddingSwitchState('running', 'embeddings', SWITCH_TARGET, SWITCH_ACTIVE, 10, 3);
    app(EmbeddingSwitchStore::class)->put($running);

    $this->artisan('ai:embeddings:switch', ['profile' => SWITCH_TARGET, '--report-failure' => true])
        ->expectsOutputToContain('already running')
        ->assertFailed();

    expect(app(EmbeddingSwitchStore::class)->get())->toEqual($running);
    Queue::assertNothingPushed();
});

it('rolls the start back when the job cannot be queued', function (): void {
    switch_probe_measures(384);
    switch_service_reports('all-MiniLM-L6-v2');
    switch_queue_pushing(static fn (): never => throw new RuntimeException('queue down'));

    $this->artisan('ai:embeddings:switch', ['profile' => SWITCH_TARGET])
        ->expectsOutputToContain('could not queue the switch; nothing was changed (queue down)')
        ->assertFailed();

    expect(app(EmbeddingSwitchStore::class)->get()->status)->toBe('idle')
        ->and(app(EmbeddingSwitchStore::class)->isSwitching())->toBeFalse()
        ->and(switch_suspends_vector_search())->toBeFalse();
});

it('rolls the start back to idle, lifting the suspension the stored start had already put in force', function (): void {
    switch_probe_measures(384);
    switch_service_reports('all-MiniLM-L6-v2');
    $suspendedWhilePushing = null;
    switch_queue_pushing(static function () use (&$suspendedWhilePushing): never {
        $suspendedWhilePushing = switch_suspends_vector_search();

        throw new RuntimeException('queue down');
    });

    $this->artisan('ai:embeddings:switch', ['profile' => SWITCH_TARGET])
        ->expectsOutputToContain('could not queue the switch; nothing was changed (queue down)')
        ->assertFailed();

    expect($suspendedWhilePushing)->toBeTrue()
        ->and(app(EmbeddingSwitchStore::class)->get()->status)->toBe('idle')
        ->and(switch_suspends_vector_search())->toBeFalse();
});

it('stores the running state before it queues the job, so vector search is suspended before any work can run', function (): void {
    switch_probe_measures(384);
    switch_service_reports('all-MiniLM-L6-v2');
    $seenWhilePushing = null;
    switch_queue_pushing(static function () use (&$seenWhilePushing): string {
        $seenWhilePushing = [app(EmbeddingSwitchStore::class)->get()->status, switch_suspends_vector_search()];

        return 'job-id';
    });

    $this->artisan('ai:embeddings:switch', ['profile' => SWITCH_TARGET])->assertSuccessful();

    expect($seenWhilePushing)->toBe(['running', true]);
});

it('completes the preflight, moves to the embeddings phase and only queues its own next run', function (): void {
    $resolver = Mockery::mock(IEmbeddableModels::class);
    $resolver->shouldReceive('all')->andReturn([EmbeddableTestModel::class]);
    app()->instance(IEmbeddableModels::class, $resolver);
    (new EmbeddableTestModel(['title' => 'One']))->saveQuietly();
    (new EmbeddableTestModel(['title' => 'Two']))->saveQuietly();

    app(EmbeddingSwitchStore::class)->put(new EmbeddingSwitchState('running', 'preflight', SWITCH_TARGET, SWITCH_ACTIVE));
    Queue::fake();

    (new SwitchEmbeddingModelJob())->handle(app(EmbeddingSwitchOrchestrator::class));

    $state = app(EmbeddingSwitchStore::class)->get();

    expect($state->status)->toBe('running')
        ->and($state->phase)->toBe('embeddings')
        ->and($state->target)->toBe(SWITCH_TARGET)
        ->and($state->total)->toBe(2)
        ->and($state->done)->toBe(0);
    Queue::assertPushed(SwitchEmbeddingModelJob::class, 1);
    Queue::assertPushedOn(SwitchEmbeddingModelJob::QUEUE, SwitchEmbeddingModelJob::class);
    Queue::assertNotPushed(GenerateEmbeddingsJob::class);
});

it('runs the switch job on its own queue, which a Horizon supervisor watches with a timeout above the job\'s', function (): void {
    $supervisor = config('horizon.defaults.supervisor-embeddings-switch');

    expect(SwitchEmbeddingModelJob::QUEUE)->toBe('embeddings-switch')
        ->and(SwitchEmbeddingModelJob::QUEUE)->not->toBe(GenerateEmbeddingsJob::QUEUE)
        ->and((new SwitchEmbeddingModelJob())->queue)->toBe(SwitchEmbeddingModelJob::QUEUE)
        ->and($supervisor['queue'] ?? null)->toBe([SwitchEmbeddingModelJob::QUEUE])
        ->and($supervisor['processes'] ?? null)->toBe(1)
        ->and($supervisor['timeout'] ?? 0)->toBeGreaterThan(SwitchEmbeddingModelJob::TIMEOUT_SECONDS);

    foreach (config('horizon.environments') as $environment => $supervisors) {
        expect($supervisors)->toHaveKey('supervisor-embeddings-switch', message: "Horizon environment {$environment} has no switch supervisor")
            ->and($supervisors['supervisor-embeddings-switch']['timeout'] ?? $supervisor['timeout'])->toBeGreaterThan(SwitchEmbeddingModelJob::TIMEOUT_SECONDS);
    }
});

it('records the failure in the state when the job fails', function (): void {
    app(EmbeddingSwitchStore::class)->put(new EmbeddingSwitchState('running', 'preflight', SWITCH_TARGET, SWITCH_ACTIVE));

    (new SwitchEmbeddingModelJob())->failed(new RuntimeException('worker lost'));

    $state = app(EmbeddingSwitchStore::class)->get();

    expect($state->status)->toBe('failed')
        ->and($state->phase)->toBe('preflight')
        ->and($state->error)->toBe('worker lost');
});

it('embeds a record saved while a switch runs with the target profile', function (): void {
    Event::fake([ModelPreProcessingCompleted::class]);
    app(EmbeddingSwitchStore::class)->put(new EmbeddingSwitchState('running', 'embeddings', SWITCH_TARGET, SWITCH_ACTIVE));

    $model = new EmbeddableTestModel(['title' => 'Created during the switch']);
    $model->saveQuietly();

    $service = Mockery::mock(IEmbeddingService::class);
    $service->shouldReceive('embedDocumentsBatch')
        ->andReturnUsing(static fn (array $texts): array => array_map(static function (): array {
            $document = new Document('');
            $document->setEmbedding(array_fill(0, 384, 0.2));

            return [$document];
        }, $texts));

    (new GenerateEmbeddingsJob($model))->handle($service);

    expect(ModelEmbedding::query()->forModel($model)->pluck('model_key')->unique()->all())->toBe([SWITCH_TARGET]);
});

it('prints the switch state and its counts', function (): void {
    app(EmbeddingSwitchStore::class)->put(new EmbeddingSwitchState('running', 'embeddings', SWITCH_TARGET, SWITCH_ACTIVE, 40, 12));

    $this->artisan('ai:embeddings:status')
        ->expectsOutputToContain('running')
        ->expectsOutputToContain('embeddings')
        ->expectsOutputToContain(SWITCH_TARGET)
        ->expectsOutputToContain('12/40')
        ->assertSuccessful();
});

it('prints idle when no switch has run', function (): void {
    $this->artisan('ai:embeddings:status')
        ->expectsOutputToContain('idle')
        ->assertSuccessful();
});

it('starts a switch to a profile whose own server is the only one configured', function (): void {
    Queue::fake();
    config()->set('ai.servers.embeddings.url', '');
    config()->set('ai.servers.embeddings_minilm', ['url' => 'http://minilm.test:8082', 'api_key' => null]);
    switch_probe_measures(384);
    Http::fake([
        'minilm.test:8082/info' => Http::response(['model_id' => 'all-MiniLM-L6-v2']),
        'minilm.test:8082/v1/embeddings' => Http::response(['model' => 'all-MiniLM-L6-v2', 'data' => [['index' => 0, 'embedding' => ai_test_unit_vector(384)]]]),
    ]);

    $this->artisan('ai:embeddings:switch', ['profile' => SWITCH_TARGET])->assertSuccessful();

    expect(app(EmbeddingSwitchStore::class)->get()->status)->toBe('running');
});

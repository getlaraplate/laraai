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
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

const SWITCH_ACTIVE = 'sentence_transformers:intfloat/multilingual-e5-small';
const SWITCH_TARGET = 'sentence_transformers:all-MiniLM-L6-v2';

beforeEach(function (): void {
    config()->set('ai.providers.sentence_transformers.url', 'http://localhost:8000');
    $this->seed(AIDatabaseSeeder::class);

    $suspended = Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'search.vector.suspended_reason',
        'module' => 'Core',
        'type' => 'string',
        'value' => 'unset',
        'choices' => null,
        'group_name' => 'search',
    ]);
    // Seeded as the seeder does it: a JSON null, since core_settings.value is NOT NULL.
    Setting::query()->withoutGlobalScopes()->whereKey($suspended->getKey())->toBase()->update(['value' => 'null', 'managed' => true]);

    config()->set('core.search.vector.model', SWITCH_ACTIVE);
    config()->set('core.search.vector.suspended_reason', null);

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
 * The sentence-transformers service reports $model on /health and in the /embed answer.
 */
function switch_service_reports(string $model, int $dimensions = 384): void
{
    Http::fake([
        '*/health' => Http::response(['status' => 'healthy', 'model' => $model]),
        '*/embed' => Http::response(['model' => $model, 'embeddings' => [array_fill(0, $dimensions, 0.1)]]),
    ]);
}

function switch_suspended_reason(): mixed
{
    return Setting::query()->withoutGlobalScopes()->where('name', 'search.vector.suspended_reason')->value('value');
}

function switch_expect_nothing_changed(): void
{
    expect(app(EmbeddingSwitchStore::class)->get()->status)->toBe('idle')
        ->and(switch_suspended_reason())->toBeNull();

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
        ->and(switch_suspended_reason())->toBe('switching')
        ->and(config('core.search.vector.suspended_reason'))->toBe('switching');

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

it('does not start when /health is unreachable even if the probe embeds', function (): void {
    Queue::fake();
    switch_probe_measures(384);
    Http::fake([
        '*/health' => static fn () => throw new ConnectionException('health timed out'),
        '*/embed' => Http::response(['model' => 'all-MiniLM-L6-v2', 'embeddings' => [array_fill(0, 384, 0.1)]]),
    ]);

    $this->artisan('ai:embeddings:switch', ['profile' => SWITCH_TARGET])
        ->expectsOutputToContain('health timed out')
        ->assertFailed();

    switch_expect_nothing_changed();
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

it('trusts the model the probe answer names over the default /health reports, as the repair does', function (): void {
    Queue::fake();
    switch_probe_measures(384);
    Http::fake([
        '*/health' => Http::response(['status' => 'healthy', 'model' => 'intfloat/multilingual-e5-small']),
        '*/embed' => Http::response(['model' => 'sentence-transformers/all-MiniLM-L6-v2', 'embeddings' => [array_fill(0, 384, 0.1)]]),
    ]);

    $this->artisan('ai:embeddings:switch', ['profile' => SWITCH_TARGET])->assertSuccessful();

    expect(app(EmbeddingSwitchStore::class)->get()->status)->toBe('running');
});

it('does not start when the service names no model, so it cannot be checked', function (): void {
    Queue::fake();
    switch_probe_measures(384);
    Http::fake([
        '*/health' => Http::response(['status' => 'healthy']),
        '*/embed' => Http::response(['embeddings' => [array_fill(0, 384, 0.1)]]),
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
        ->and(switch_suspended_reason())->toBeNull();
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
        ->and(switch_suspended_reason())->toBeNull();
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
        ->and(switch_suspended_reason())->toBeNull();
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
        ->and(switch_suspended_reason())->toBeNull();
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
    $queue = Mockery::mock(QueueConnection::class);
    $queue->shouldReceive('push', 'pushOn', 'later', 'laterOn')->andThrow(new RuntimeException('queue down'));
    $queues = Mockery::mock(QueueFactory::class);
    $queues->shouldReceive('connection')->andReturn($queue);
    app()->instance(QueueFactory::class, $queues);

    $this->artisan('ai:embeddings:switch', ['profile' => SWITCH_TARGET])
        ->expectsOutputToContain('could not queue the switch; nothing was changed (queue down)')
        ->assertFailed();

    expect(app(EmbeddingSwitchStore::class)->get()->status)->toBe('idle')
        ->and(switch_suspended_reason())->toBeNull()
        ->and(config('core.search.vector.suspended_reason'))->toBeNull();
});

it('rolls the start back to idle even when the suspended reason row has gone', function (): void {
    switch_probe_measures(384);
    switch_service_reports('all-MiniLM-L6-v2');
    $queue = Mockery::mock(QueueConnection::class);
    $queue->shouldReceive('push', 'pushOn', 'later', 'laterOn')->andReturnUsing(static function (): never {
        Setting::query()->withoutGlobalScopes()->where('name', 'search.vector.suspended_reason')->toBase()->delete();

        throw new RuntimeException('queue down');
    });
    $queues = Mockery::mock(QueueFactory::class);
    $queues->shouldReceive('connection')->andReturn($queue);
    app()->instance(QueueFactory::class, $queues);

    $this->artisan('ai:embeddings:switch', ['profile' => SWITCH_TARGET])
        ->expectsOutputToContain('could not queue the switch; nothing was changed (queue down)')
        ->assertFailed();

    expect(app(EmbeddingSwitchStore::class)->get()->status)->toBe('idle');
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

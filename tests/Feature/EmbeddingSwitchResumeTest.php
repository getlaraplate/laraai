<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchOrchestrator;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchState;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchStore;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Database\Seeders\AIDatabaseSeeder;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\AI\Jobs\SwitchEmbeddingModelJob;
use Modules\AI\Services\ModelEmbeddingSynchronizer;
use Modules\AI\Tests\Stubs\EmbeddableTestModel;
use Modules\AI\Tests\Stubs\EmbeddingSwitchHarness as Harness;
use Modules\Core\Events\ModelPreProcessingCompleted;
use Modules\Core\Models\ModelEmbedding;
use Modules\Core\Models\Setting;
use Modules\Core\Search\Contracts\IVectorSearchAvailability;
use Modules\Core\Search\Support\VectorProfileContext;

beforeEach(function (): void {
    config()->set('ai.servers.embeddings.url', 'http://localhost:8000');
    Event::fake([ModelPreProcessingCompleted::class]);
    $this->seed(AIDatabaseSeeder::class);
    $this->engine = Harness::boot();
    $this->records = Harness::corpus($this->engine, 'One', 'Two', 'Three');
    $this->index = (new EmbeddableTestModel)->searchableAs();
});

/**
 * Drives a switch to `$target` until its verification fails on a record with no row of the target.
 */
function resume_fail_verification(string $target, EmbeddableTestModel $record): void
{
    Harness::start($target);
    Harness::advanceUntil('verify');
    ModelEmbedding::query()->forModel($record)->where('model_key', $target)->delete();
    Harness::advanceUntil();
}

function resume_store(): EmbeddingSwitchStore
{
    return app(EmbeddingSwitchStore::class);
}

/**
 * The embeddings server reports $model on /info and in the /v1/embeddings answer.
 */
function resume_service_reports(string $model, int $dimensions = 384): void
{
    Http::fake([
        '*/info' => Http::response(['model_id' => $model]),
        '*/v1/embeddings' => Http::response(['model' => $model, 'data' => [['index' => 0, 'embedding' => ai_test_unit_vector($dimensions)]]]),
    ]);
}

it('resumes a switch failed in its verification once the cause is fixed, and completes it', function (): void {
    resume_fail_verification(Harness::TARGET, $this->records[1]);
    expect(resume_store()->get()->status)->toBe('failed');

    new GenerateEmbeddingsJob($this->records[1], null, Harness::TARGET)->handle(app(IEmbeddingService::class));

    $this->artisan('ai:embeddings:switch', ['--resume' => true])
        ->expectsOutputToContain('resumed from phase embeddings')
        ->assertSuccessful();

    expect(resume_store()->get()->status)->toBe('idle')
        ->and(Harness::setting('features.embeddings.serving_model'))->toBe(Harness::TARGET)
        ->and(Harness::storedModelKeys())->toBe([Harness::TARGET])
        ->and(resume_store()->isSwitching())->toBeFalse();
});

it('leaves an activation whose settings write throws at the activate phase, and resumes it', function (): void {
    $thrown = false;
    Setting::saving(static function (Setting $setting) use (&$thrown): void {
        if ($setting->name === 'features.embeddings.serving_model' && ! $thrown) {
            $thrown = true;

            throw new RuntimeException('settings store unavailable');
        }
    });
    Harness::start(Harness::TARGET);
    Harness::advanceUntil('activate');

    $failure = null;

    try {
        app(EmbeddingSwitchOrchestrator::class)->advance();
    } catch (RuntimeException $exception) {
        $failure = $exception;
    }

    $state = resume_store()->get();

    expect($failure?->getMessage())->toBe('settings store unavailable')
        ->and($state->status)->toBe('running')
        ->and($state->phase)->toBe('activate')
        ->and(Harness::setting('features.embeddings.serving_model'))->toBe(Harness::ACTIVE)
        ->and(app(EmbeddingModelRegistry::class)->activeKey())->toBe(Harness::ACTIVE)
        ->and(config('ai.features.embeddings.serving_model'))->toBe(Harness::ACTIVE)
        ->and(resume_store()->isSwitching())->toBeTrue()
        ->and(Harness::rowsOf(Harness::ACTIVE))->toBe(3);

    (new SwitchEmbeddingModelJob)->failed($failure);
    expect(resume_store()->get()->status)->toBe('failed');

    $this->artisan('ai:embeddings:switch', ['--resume' => true])->assertSuccessful();

    expect(resume_store()->get()->status)->toBe('idle')
        ->and(Harness::setting('features.embeddings.serving_model'))->toBe(Harness::TARGET)
        ->and(Harness::storedModelKeys())->toBe([Harness::TARGET]);
});

it('resumes a running switch that stopped making progress', function (): void {
    Queue::fake();
    resume_store()->put(new EmbeddingSwitchState('running', 'indexes', Harness::TARGET, Harness::ACTIVE, updatedAt: now()->subHours(2)->toIso8601String()));

    $this->artisan('ai:embeddings:switch', ['--resume' => true])->assertSuccessful();

    expect(resume_store()->get()->status)->toBe('running')
        ->and(resume_store()->get()->phase)->toBe('indexes');
    Queue::assertPushed(SwitchEmbeddingModelJob::class, 1);
    Queue::assertPushedOn(SwitchEmbeddingModelJob::QUEUE, SwitchEmbeddingModelJob::class);
});

it('refuses to resume or abandon a switch that is running normally', function (string $option): void {
    Queue::fake();
    $state = new EmbeddingSwitchState('running', 'embeddings', Harness::TARGET, Harness::ACTIVE, updatedAt: now()->toIso8601String());
    resume_store()->put($state);

    $this->artisan('ai:embeddings:switch', [$option => true])
        ->expectsOutputToContain('running')
        ->assertFailed();

    expect(resume_store()->get())->toEqual($state);
    Queue::assertNothingPushed();
})->with(['--resume', '--abandon']);

it('refuses to resume a refused start and points to --abandon', function (): void {
    Queue::fake();
    resume_store()->put(new EmbeddingSwitchState('failed', 'preflight', Harness::TARGET, Harness::ACTIVE, error: 'probe failed'));

    $this->artisan('ai:embeddings:switch', ['--resume' => true])
        ->expectsOutputToContain('--abandon')
        ->assertFailed();

    expect(resume_store()->get()->status)->toBe('failed');
    Queue::assertNothingPushed();
});

it('refuses --resume and --abandon together, and either with no switch to act on', function (array $options, string $message): void {
    Queue::fake();

    $this->artisan('ai:embeddings:switch', $options)
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(resume_store()->get()->status)->toBe('idle');
    Queue::assertNothingPushed();
})->with([
    'both' => [['--resume' => true, '--abandon' => true], 'not both'],
    'resume idle' => [['--resume' => true], 'No embedding model switch'],
    'abandon idle' => [['--abandon' => true], 'No embedding model switch'],
    'nothing' => [[], 'profile'],
]);

it('abandons a refused start: idle again and the chosen model back to the active one, nothing re-embedded', function (): void {
    Queue::fake();
    Setting::query()->withoutGlobalScopes()->where('name', 'features.embeddings.model')->toBase()->update(['value' => json_encode(Harness::TARGET)]);
    resume_store()->put(new EmbeddingSwitchState('failed', 'preflight', Harness::TARGET, Harness::ACTIVE, error: 'probe failed'));

    $this->artisan('ai:embeddings:switch', ['--abandon' => true])->assertSuccessful();

    expect(resume_store()->get()->status)->toBe('idle')
        ->and(Harness::setting('features.embeddings.model'))->toBe(Harness::ACTIVE)
        ->and(config('ai.features.embeddings.model'))->toBe(Harness::ACTIVE)
        ->and(Harness::storedModelKeys())->toBe([Harness::ACTIVE]);
    Queue::assertNothingPushed();
    expect($this->engine->createdIndexes)->toBe([]);
});

it('abandons a switch failed after it changed the index: rebuilds for the previous model and activates it again', function (): void {
    Setting::query()->withoutGlobalScopes()->where('name', 'features.embeddings.model')->toBase()->update(['value' => json_encode(Harness::WIDE)]);
    resume_fail_verification(Harness::WIDE, $this->records[1]);
    expect(resume_store()->get()->status)->toBe('failed')
        ->and(Harness::rowsOf(Harness::WIDE))->toBe(2);
    resume_service_reports('intfloat/multilingual-e5-small');

    $this->artisan('ai:embeddings:switch', ['--abandon' => true])->assertSuccessful();

    $forced = $this->engine->forcedIndexes();

    expect(resume_store()->get()->status)->toBe('idle')
        ->and(Harness::setting('features.embeddings.serving_model'))->toBe(Harness::ACTIVE)
        ->and(Harness::setting('features.embeddings.model'))->toBe(Harness::ACTIVE)
        ->and(VectorProfileContext::get()?->dimensions)->toBe(384)
        ->and(resume_store()->isSwitching())->toBeFalse()
        ->and($forced)->toHaveCount(2)
        ->and(end($forced)['dimensions'])->toBe(384)
        ->and(end($forced)['model_key'])->toBe(Harness::ACTIVE)
        ->and(Harness::storedModelKeys())->toBe([Harness::ACTIVE])
        ->and(Harness::rowsOf(Harness::ACTIVE))->toBe(3);
});

it('leaves vector search on when the job failed its preflight, which changed nothing, and clears it on abandon', function (): void {
    Queue::fake();
    resume_store()->put(new EmbeddingSwitchState('running', 'preflight', Harness::TARGET, Harness::ACTIVE));
    expect(resume_store()->isSwitching())->toBeTrue();

    resume_store()->put(new EmbeddingSwitchState('failed', 'preflight', Harness::TARGET, Harness::ACTIVE, error: 'worker lost'));
    expect(resume_store()->isSwitching())->toBeFalse();

    $this->artisan('ai:embeddings:switch', ['--abandon' => true])->assertSuccessful();

    expect(resume_store()->get()->status)->toBe('idle')
        ->and(resume_store()->isSwitching())->toBeFalse()
        ->and(config('ai.features.embeddings.switch'))->toBe(resume_store()->get()->toJson());
    Queue::assertNothingPushed();
});

it('keeps vector search suspended when it abandons a failed switch, for the whole switch back', function (): void {
    Queue::fake();
    resume_service_reports('intfloat/multilingual-e5-small');
    resume_store()->put(new EmbeddingSwitchState('failed', 'verify', Harness::TARGET, Harness::ACTIVE, error: 'a record has no embedding of the target'));

    $this->artisan('ai:embeddings:switch', ['--abandon' => true])->assertSuccessful();

    expect(resume_store()->get()->status)->toBe('running')
        ->and(resume_store()->get()->target)->toBe(Harness::ACTIVE)
        ->and(resume_store()->isSwitching())->toBeTrue()
        ->and(app(IVectorSearchAvailability::class)->check(new EmbeddableTestModel)->reason)->toBe('suspended');
    Queue::assertPushed(SwitchEmbeddingModelJob::class, 1);
});

it('shows a running switch without progress as interrupted', function (): void {
    resume_store()->put(new EmbeddingSwitchState('running', 'indexes', Harness::TARGET, Harness::ACTIVE, updatedAt: now()->subHours(2)->toIso8601String()));

    $this->artisan('ai:embeddings:status')
        ->expectsOutputToContain('interrupted: no progress for over 30 minutes; run ai:embeddings:switch --resume or --abandon')
        ->assertSuccessful();
});

it('does not show a running switch that makes progress as interrupted', function (): void {
    resume_store()->put(new EmbeddingSwitchState('running', 'indexes', Harness::TARGET, Harness::ACTIVE, updatedAt: now()->toIso8601String()));

    $this->artisan('ai:embeddings:status')
        ->doesntExpectOutputToContain('interrupted')
        ->assertSuccessful();
});

it('resumes a verification failed on a leftover document by rebuilding the index, as its message says', function (): void {
    Harness::start(Harness::TARGET);
    Harness::advanceUntil('verify');
    $this->engine->documents[$this->index]['999'] = ['id' => '999'];
    Harness::advanceUntil();
    expect(resume_store()->get()->status)->toBe('failed');

    $this->artisan('ai:embeddings:switch', ['--resume' => true])->assertSuccessful();

    expect(resume_store()->get()->status)->toBe('idle')
        ->and($this->engine->documentsOf($this->index))->not->toHaveKey('999')
        ->and(Harness::setting('features.embeddings.serving_model'))->toBe(Harness::TARGET);
});

it('resumes a switch failed in its indexes or verification from the embeddings, so a record edited meanwhile is embedded again', function (string $phase): void {
    Harness::start(Harness::TARGET);
    Harness::advanceUntil($phase);
    $edited = $this->records[1];
    $edited->title = 'Two, edited while the switch was failed';
    $edited->saveQuietly();
    resume_store()->put(resume_store()->get()->with(status: 'failed', error: 'a record has no embedding of the target'));

    $this->artisan('ai:embeddings:switch', ['--resume' => true])
        ->expectsOutputToContain('resumed from phase embeddings')
        ->assertSuccessful();

    expect(resume_store()->get()->status)->toBe('idle')
        ->and(Harness::setting('features.embeddings.serving_model'))->toBe(Harness::TARGET)
        ->and(ModelEmbedding::query()->forModel($edited)->where('model_key', Harness::TARGET)->value('content_hash'))
        ->toBe(ModelEmbeddingSynchronizer::contentHash('Two, edited while the switch was failed'));
})->with(['indexes', 'verify']);

it('refuses to abandon, changing nothing, when the service does not run the previous model', function (): void {
    Queue::fake();
    resume_service_reports('sentence-transformers/all-MiniLM-L6-v2');
    $failed = new EmbeddingSwitchState('failed', 'verify', Harness::TARGET, Harness::ACTIVE, error: 'a record has no embedding of the target');
    resume_store()->put($failed);

    $this->artisan('ai:embeddings:switch', ['--abandon' => true])
        ->expectsOutputToContain('was not abandoned: the embedding server /v1/embeddings reports model "sentence-transformers/all-MiniLM-L6-v2" but the profile expects "intfloat/multilingual-e5-small"')
        ->assertFailed();

    expect(resume_store()->get())->toEqual($failed)
        ->and(resume_store()->isSwitching())->toBeTrue();
    Queue::assertNothingPushed();
});

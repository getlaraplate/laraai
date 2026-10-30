<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchState;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchStore;
use Modules\AI\Database\Seeders\AIDatabaseSeeder;
use Modules\Core\Models\Setting;

it('reads idle before anything is written and persists through the managed setting', function (): void {
    $this->seed(AIDatabaseSeeder::class);
    $store = new EmbeddingSwitchStore;

    expect($store->get()->status)->toBe('idle');

    $state = new EmbeddingSwitchState('running', 'preflight', 'a:b', 'c:d', 5, 1, null, '2026-10-05T10:00:00+00:00');
    $store->put($state);

    $stored = Setting::query()->withoutGlobalScopes()->where('name', 'features.embeddings.switch')->sole();

    expect(EmbeddingSwitchState::fromJson((string) $stored->value))->toEqual($state)
        ->and($store->get())->toEqual($state);
});

it('refuses a second lock holder and accepts one after release', function (): void {
    $store = new EmbeddingSwitchStore;

    $release = $store->lock();

    expect($release)->toBeInstanceOf(Closure::class)
        ->and($store->lock())->toBeNull();

    $release();

    $again = $store->lock();
    expect($again)->toBeInstanceOf(Closure::class);
    $again();
});

it('lets the start lock expire after two minutes', function (): void {
    $store = new EmbeddingSwitchStore;

    expect($store->lock())->toBeInstanceOf(Closure::class);

    $this->travel(119)->seconds();
    expect($store->lock())->toBeNull();

    $this->travel(2)->seconds();
    $again = $store->lock();
    expect($again)->toBeInstanceOf(Closure::class);
    $again();
});

it('has no suspension of its own: no suspended reason, nothing written under search.vector', function (): void {
    expect(method_exists(EmbeddingSwitchStore::class, 'suspend'))->toBeFalse()
        ->and(method_exists(EmbeddingSwitchStore::class, 'clearSuspension'))->toBeFalse()
        ->and(defined(EmbeddingSwitchStore::class . '::SUSPENDED_REASON'))->toBeFalse();

    $this->seed(AIDatabaseSeeder::class);
    (new EmbeddingSwitchStore)->put(new EmbeddingSwitchState('running', 'preflight', 'a:b', 'c:d'));

    expect(Setting::query()->withoutGlobalScopes()->where('name', 'like', 'search.vector.%')->exists())->toBeFalse();
});

it('is switching while the state is running or failed after its preflight, read from the runtime config', function (EmbeddingSwitchState $state, bool $switching): void {
    $this->seed(AIDatabaseSeeder::class);
    $store = new EmbeddingSwitchStore;
    $store->put($state);

    expect(config('ai.features.embeddings.switch'))->toBe($state->toJson())
        ->and($store->isSwitching())->toBe($switching);
})->with([
    'idle' => [EmbeddingSwitchState::idle(), false],
    'running preflight' => [new EmbeddingSwitchState('running', 'preflight', 'a:b', 'c:d'), true],
    'running embeddings' => [new EmbeddingSwitchState('running', 'embeddings', 'a:b', 'c:d'), true],
    'running activate' => [new EmbeddingSwitchState('running', 'activate', 'a:b', 'c:d'), true],
    'failed indexes' => [new EmbeddingSwitchState('failed', 'indexes', 'a:b', 'c:d', error: 'x'), true],
    'failed verify' => [new EmbeddingSwitchState('failed', 'verify', 'a:b', 'c:d', error: 'x'), true],
    'refused at its preflight' => [new EmbeddingSwitchState('failed', 'preflight', 'a:b', 'c:d', error: 'x'), false],
]);

it('answers isSwitching without a database query', function (): void {
    config()->set('ai.features.embeddings.switch', (new EmbeddingSwitchState('running', 'indexes', 'a:b', 'c:d'))->toJson());
    $store = new EmbeddingSwitchStore;
    $queries = 0;
    DB::listen(static function () use (&$queries): void {
        $queries++;
    });

    expect($store->isSwitching())->toBeTrue()
        ->and($queries)->toBe(0);
});

it('is not switching when the runtime config holds no state, or one it cannot read', function (mixed $value): void {
    config()->set('ai.features.embeddings.switch', $value);

    expect((new EmbeddingSwitchStore)->isSwitching())->toBeFalse();
})->with(['unset' => [null], 'empty' => [''], 'not json' => ['{oops'], 'not a state' => [['status' => 'running']]]);

it('flushes the stored state again once the surrounding transaction commits', function (): void {
    $this->seed(AIDatabaseSeeder::class);
    $store = new EmbeddingSwitchStore;

    new Setting()->getConnection()->transaction(static function () use ($store): void {
        $store->put(new EmbeddingSwitchState('running', 'preflight', 'a:b', 'c:d'));
        // Another writer of the runtime config inside the transaction, e.g. a stale cache read.
        config()->set('ai.features.embeddings.switch', EmbeddingSwitchState::idle()->toJson());
    });

    expect($store->isSwitching())->toBeTrue();
});

it('updates the state it reads under the state lock, and writes nothing when the change keeps it', function (): void {
    $this->seed(AIDatabaseSeeder::class);
    $store = new EmbeddingSwitchStore;
    $store->put(new EmbeddingSwitchState('running', 'indexes', 'a:b', 'c:d', chunksDone: 1));
    $updatedAt = Setting::query()->withoutGlobalScopes()->where('name', 'features.embeddings.switch')->value('updated_at');
    $this->travel(2)->seconds();

    $unchanged = $store->update(static fn (EmbeddingSwitchState $state): EmbeddingSwitchState => $state);

    expect($unchanged->chunksDone)->toBe(1)
        ->and(Setting::query()->withoutGlobalScopes()->where('name', 'features.embeddings.switch')->value('updated_at'))->toEqual($updatedAt);

    $store->put($store->get()->with(chunksDone: 2));
    $next = $store->update(static fn (EmbeddingSwitchState $state): EmbeddingSwitchState => $state->with(chunksDone: $state->chunksDone + 1));

    expect($next->chunksDone)->toBe(3)
        ->and($store->get()->chunksDone)->toBe(3);
});

it('waits for the state lock held by another writer, fails after the configured wait, and updates once it is free', function (): void {
    $this->seed(AIDatabaseSeeder::class);
    config()->set(EmbeddingSwitchStore::STATE_LOCK_WAIT_CONFIG, 1);
    $store = new EmbeddingSwitchStore;
    $store->put(new EmbeddingSwitchState('running', 'indexes', 'a:b', 'c:d', chunksDone: 1));
    $held = Cache::lock(EmbeddingSwitchStore::STATE_LOCK, 10);
    $held->get();

    expect(static fn (): EmbeddingSwitchState => $store->update(static fn (EmbeddingSwitchState $state): EmbeddingSwitchState => $state->with(chunksDone: 2)))
        ->toThrow(LockTimeoutException::class)
        ->and($store->get()->chunksDone)->toBe(1);

    $held->release();

    expect($store->update(static fn (EmbeddingSwitchState $state): EmbeddingSwitchState => $state->with(chunksDone: 2))->chunksDone)->toBe(2)
        ->and($store->get()->chunksDone)->toBe(2);
});

it('waits ten seconds for the state lock unless configured otherwise, falling back on an invalid wait', function (mixed $configured, int $expected): void {
    config()->set(EmbeddingSwitchStore::STATE_LOCK_WAIT_CONFIG, $configured);

    expect((new EmbeddingSwitchStore)->stateLockWaitSeconds())->toBe($expected);
})->with([
    'unset' => [null, 10],
    'configured' => [3, 3],
    'zero' => [0, 10],
    'not a number' => ['soon', 10],
]);

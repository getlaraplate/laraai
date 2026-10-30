<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings\Switching;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Models\Setting;
use Modules\Core\Services\SettingsCacheCoordinator;

/**
 * Persists the {@see EmbeddingSwitchState} in the managed setting `features.embeddings.switch`,
 * tells whether a switch keeps vector search suspended ({@see self::isSwitching()}), and serialises
 * switch starts with an atomic lock. While a switch runs, the switch job and the chunk jobs write
 * the state through {@see self::update()}, which re-reads and writes it under its own lock, so a
 * chunk completion is never overwritten by a write computed from an older state.
 *
 * Both locks are cache locks: they exclude across workers only on a cache store shared by them and
 * supporting atomic locks (redis, database, memcached). On the `array` store a lock is local to its
 * process; the default `failover` store (redis, then array) falls back to it when redis fails.
 */
final class EmbeddingSwitchStore
{
    public const string STATE_LOCK = 'embeddings:switch:state';

    /**
     * The config key of how long a writer waits for the state lock before it fails and its job is
     * retried, {@see self::DEFAULT_STATE_LOCK_WAIT_SECONDS} by default.
     */
    public const string STATE_LOCK_WAIT_CONFIG = 'ai.features.embeddings.state_lock_wait_seconds';

    public const int DEFAULT_STATE_LOCK_WAIT_SECONDS = 10;

    private const string SETTING = 'features.embeddings.switch';

    /**
     * The runtime config copy of {@see self::SETTING}, kept in sync by the settings observer.
     */
    private const string SETTING_CONFIG = 'ai.features.embeddings.switch';

    private const string TARGET_SETTING = 'features.embeddings.model';

    private const string LOCK = 'embeddings:switch';

    /**
     * An update is a read and a write of one setting: the lock is held for milliseconds. A holder
     * that dies frees it after this long.
     */
    private const int STATE_LOCK_SECONDS = 10;

    /**
     * The lock covers only the start (the preflight's network calls, about 35 seconds at most); the
     * persisted state guards the rest of the switch.
     */
    private const int LOCK_SECONDS = 120;

    public function get(): EmbeddingSwitchState
    {
        $setting = Setting::query()->withoutGlobalScopes()->where('name', self::SETTING)->first();
        $value = $setting?->value;

        return EmbeddingSwitchState::fromJson(is_string($value) ? $value : null);
    }

    /**
     * Stores the state. The settings observer pushes it into the runtime config at once; the settings
     * cache and the runtime config are flushed again once the surrounding transaction commits (at
     * once outside one), so a read made before the commit cannot keep the old state cached.
     */
    public function put(EmbeddingSwitchState $state): void
    {
        Setting::writeManaged(self::SETTING, $state->toJson());

        $this->flushAfterCommit([self::SETTING]);
    }

    /**
     * Whether a switch keeps vector search suspended: the state is `running`, or `failed` after its
     * preflight (the indexes may be half rebuilt). A start refused at its preflight changed nothing,
     * so it suspends nothing. Read from the runtime config copy of the state, without a query: the
     * search guard asks on every search.
     */
    public function isSwitching(): bool
    {
        $value = config(self::SETTING_CONFIG);
        $state = EmbeddingSwitchState::fromJson(is_string($value) ? $value : null);

        return $state->status === 'running'
            || ($state->status === 'failed' && $state->phase !== 'preflight');
    }

    /**
     * Reads the state, applies `$change` and stores the result, under a lock shared by every writer
     * of a running switch; nothing is written when `$change` returns the state unchanged.
     *
     * @param  Closure(EmbeddingSwitchState): EmbeddingSwitchState  $change
     *
     * @throws LockTimeoutException when another writer holds the lock for longer than the wait
     */
    public function update(Closure $change): EmbeddingSwitchState
    {
        $lock = Cache::lock(self::STATE_LOCK, self::STATE_LOCK_SECONDS);
        $lock->block($this->stateLockWaitSeconds());

        try {
            $current = $this->get();
            $next = $change($current);

            if ($next->toJson() !== $current->toJson()) {
                $this->put($next);
            }

            return $next;
        } finally {
            $lock->release();
        }
    }

    /**
     * `ai.features.embeddings.state_lock_wait_seconds`; below 1 or not a number falls back to
     * {@see self::DEFAULT_STATE_LOCK_WAIT_SECONDS}.
     */
    public function stateLockWaitSeconds(): int
    {
        $configured = config(self::STATE_LOCK_WAIT_CONFIG);

        return is_numeric($configured) && (int) $configured >= 1 ? (int) $configured : self::DEFAULT_STATE_LOCK_WAIT_SECONDS;
    }

    /**
     * Records `$key` as the model the operator chose (`features.embeddings.model`, the dropdown),
     * so the settings page names the model the switch ended on. The setting is not managed, and a
     * plain save would go through approval; it is written directly, and flushed after the commit.
     */
    public function recordTarget(string $key): void
    {
        Setting::query()->withoutGlobalScopes()
            ->where('name', self::TARGET_SETTING)
            ->toBase()
            ->update(['value' => json_encode($key, JSON_THROW_ON_ERROR)]);

        $this->flushAfterCommit([self::TARGET_SETTING]);
    }

    /**
     * Flushes the settings cache and the runtime config of the named settings once the surrounding
     * transaction commits (at once outside one), so a read made before the commit cannot keep the
     * old value cached.
     *
     * @param  list<string>  $names
     */
    public function flushAfterCommit(array $names): void
    {
        new Setting()->getConnection()->afterCommit(function () use ($names): void {
            $this->resync($names);
        });
    }

    /**
     * Reloads the named settings and flushes their cache and runtime config now: after a rolled
     * back write, the observer has already pushed the value that never reached the database.
     *
     * @param  list<string>  $names
     */
    public function resync(array $names): void
    {
        $coordinator = app(SettingsCacheCoordinator::class);

        foreach (Setting::query()->withoutGlobalScopes()->whereIn('name', $names)->get() as $setting) {
            $coordinator->flushSetting($setting, sync_runtime_config: true);
        }
    }

    /**
     * @return (Closure(): void)|null The release closure, or null when another switch holds the lock.
     */
    public function lock(): ?Closure
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        return static function () use ($lock): void {
            $lock->release();
        };
    }
}

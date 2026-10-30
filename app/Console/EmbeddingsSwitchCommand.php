<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use InvalidArgumentException;
use Modules\AI\Ai\Embeddings\EmbeddingDimensionMismatch;
use Modules\AI\Ai\Embeddings\EmbeddingDimensionProbe;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\EmbeddingServerIdentity;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchState;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchStore;
use Modules\AI\Jobs\SwitchEmbeddingModelJob;
use Modules\Core\Models\Setting;
use Override;
use Throwable;

/**
 * Starts a switch of the embedding model. The preflight runs here, synchronously, before anything
 * changes: the profile exists, is not the active one, its provider is configured, the probe measures
 * its declared dimensions and the service runs its model. Only then is the state stored as
 * `running`/`preflight`, which suspends vector search ({@see EmbeddingSwitchStore::isSwitching()}),
 * and `SwitchEmbeddingModelJob` dispatched, in that order.
 *
 * The atomic lock covers only the start (state still idle, then persisted as running); for the rest
 * of the switch the persisted `running` or `failed` state is what refuses another start.
 *
 * A refusal changes nothing. With `--report-failure`, the option the settings confirmation queues
 * the command with, a refusal decided under the start lock (provider not configured, preflight
 * failed) is also stored as a `failed` switch in phase `preflight`, so the settings page shows it;
 * a switch failed at its preflight does not suspend vector search. A held lock, an already active
 * profile or a switch already running or failed records nothing. A start whose job cannot be
 * queued is rolled back.
 *
 * `--resume` continues a failed switch, or a running one that stopped making progress
 * ({@see EmbeddingSwitchState::isInterrupted()}), from its stored phase (a failed index build or
 * verification from the embeddings, which re-embed what changed; an interrupted verification from
 * the indexes, which are rebuilt; an index build keeps its chunk plan and writes only the chunks
 * still pending). `--abandon` clears a start
 * refused by its preflight, and after a later failure runs the same procedure back to the previous
 * model. Neither acts on a switch that is running normally.
 */
final class EmbeddingsSwitchCommand extends Command
{
    #[Override]
    protected $signature = 'ai:embeddings:switch
                            {profile? : Target embedding model profile key, e.g. openai_compatible:intfloat/multilingual-e5-small}
                            {--resume : Continue a failed switch, or a running one that stopped making progress, from its stored phase}
                            {--abandon : Give up the switch and return to the active model (a refused start is just cleared)}
                            {--report-failure : Internal (the settings confirmation): record a refused start as a failed switch, so the settings page shows why}';

    #[Override]
    protected $description = 'Start switching the embedding model to another profile: preflight, then re-embed and reindex in the background while vector search is off <fg=magenta>(✨ Modules\AI)</fg=magenta>';

    public function handle(
        EmbeddingModelRegistry $registry,
        EmbeddingSwitchStore $store,
        EmbeddingDimensionProbe $probe,
        EmbeddingServerIdentity $identity,
    ): int {
        $resume = (bool) $this->option('resume');
        $abandon = (bool) $this->option('abandon');
        $profileKey = $this->argument('profile');

        if ($resume && $abandon) {
            return $this->refuse('Pass either --resume or --abandon, not both.');
        }

        if (($resume || $abandon) && $profileKey !== null) {
            return $this->refuse('--resume and --abandon act on the stored switch: pass no profile with them.');
        }

        if ($resume) {
            return $this->resume($store);
        }

        if ($abandon) {
            return $this->abandon($store, $registry, $identity);
        }

        if ($profileKey === null) {
            return $this->refuse('Pass the target profile, or --resume / --abandon to act on a stored switch.');
        }

        try {
            $profile = $registry->get((string) $profileKey);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $active = $registry->activeKey();

        if ($profile->key === $active) {
            return $this->refuse("Profile \"{$profile->key}\" is already the active embedding model: there is nothing to switch.");
        }

        $release = $store->lock();

        if ($release === null) {
            return $this->refuse('Another embedding model switch is starting: try again in a moment. Nothing was changed.');
        }

        try {
            $current = $store->get();

            if ($current->status !== 'idle') {
                return $this->refuse("An embedding model switch is already {$current->status} (phase {$current->phase}, target {$current->target}). Nothing was changed.");
            }

            $failure = in_array($profile->key, $registry->configuredKeys(), true)
                ? $this->preflight($profile, $probe, $identity)
                : "the provider \"{$profile->provider}\" is not configured";

            if ($failure !== null) {
                return $this->refuseUnderLock($store, $profile, $active, "The switch to \"{$profile->key}\" did not start: {$failure}. Nothing was changed.");
            }

            $this->persistStart($store, $profile, $active);
        } catch (Throwable $exception) {
            return $this->refuseUnderLock($store, $profile, $active, "The switch to \"{$profile->key}\" did not start: {$exception->getMessage()}");
        } finally {
            $release();
        }

        try {
            app(Dispatcher::class)->dispatch(new SwitchEmbeddingModelJob());
        } catch (Throwable $exception) {
            $this->rollBackStart($store);
            $this->error("The switch to \"{$profile->key}\" did not start: could not queue the switch; nothing was changed ({$exception->getMessage()}).");

            return self::FAILURE;
        }

        $this->info("Switch to \"{$profile->key}\" started: vector search is off until it ends. Follow it with ai:embeddings:status.");

        return self::SUCCESS;
    }

    /**
     * Continues a failed switch, or a running one that stopped making progress, from its stored
     * phase. A switch refused at its start has nothing to continue; one still running is left alone.
     */
    private function resume(EmbeddingSwitchStore $store): int
    {
        $release = $store->lock();

        if ($release === null) {
            return $this->refuse('An embedding model switch is starting: try again in a moment. Nothing was changed.');
        }

        $refusal = null;
        $state = EmbeddingSwitchState::idle();

        try {
            // Decided and written under the state lock: a chunk job of an interrupted switch may
            // still record a completion, which must not be overwritten.
            $resumed = $store->update(function (EmbeddingSwitchState $current) use (&$refusal, &$state): EmbeddingSwitchState {
                $state = $current;
                $refusal = $this->refusalToActOn($current, 'resume');

                if ($refusal === null && $current->status === 'failed' && $current->phase === 'preflight') {
                    $refusal = "The switch to \"{$current->target}\" was refused before it started, so there is nothing to resume: run ai:embeddings:switch --abandon to clear it.";
                }

                if ($refusal !== null) {
                    return $current;
                }

                return $current->with(status: 'running', phase: $this->resumePhase($current), error: null, rounds: 0, updatedAt: now()->toIso8601String());
            });
        } finally {
            $release();
        }

        if ($refusal !== null) {
            return $this->refuse($refusal);
        }

        if (! $this->dispatchSwitch($store, $state, $resumed)) {
            return self::FAILURE;
        }

        $this->info("Switch to \"{$state->target}\" resumed from phase {$resumed->phase}. Follow it with ai:embeddings:status.");

        return self::SUCCESS;
    }

    /**
     * The phase a resumed switch restarts at. A failed index build or verification restarts at the
     * embeddings: a record edited while the switch was failed (or whose embedding job failed) is
     * embedded again, and the embeddings phase moves straight on when nothing is pending. An
     * interrupted verification restarts at the indexes. The resumed state keeps its chunk plan:
     * an index build writes only the chunks still pending; a verification whose refresh ran out of
     * rounds goes back to `verify` and refreshes every document again; any other verification
     * empties and rebuilds the indexes, so a leftover document or one with the wrong vectors is gone.
     */
    private function resumePhase(EmbeddingSwitchState $state): string
    {
        if ($state->status === 'failed' && in_array($state->phase, ['indexes', 'verify'], true)) {
            return 'embeddings';
        }

        return $state->phase === 'verify' ? 'indexes' : $state->phase;
    }

    /**
     * Gives the switch up. A start refused by its preflight changed nothing: the state is cleared and
     * the chosen model set back to the active one. After a later failure the indexes and the target's
     * rows may already have changed, so the same procedure runs with the previous model as target,
     * once the service is checked to run that model (otherwise nothing changes):
     * its rows still exist, so only records missing one are embedded, the indexes are rebuilt for its
     * dimensions, verified, and it is activated again.
     */
    private function abandon(EmbeddingSwitchStore $store, EmbeddingModelRegistry $registry, EmbeddingServerIdentity $identity): int
    {
        $release = $store->lock();

        if ($release === null) {
            return $this->refuse('An embedding model switch is starting: try again in a moment. Nothing was changed.');
        }

        try {
            $state = $store->get();
            $refusal = $this->refusalToActOn($state, 'abandon');

            if ($refusal !== null) {
                return $this->refuse($refusal);
            }

            $active = $registry->activeKey();

            if ($state->status === 'failed' && $state->phase === 'preflight') {
                new Setting()->getConnection()->transaction(static function () use ($store, $active): void {
                    $store->update(static fn (): EmbeddingSwitchState => EmbeddingSwitchState::idle()->with(updatedAt: now()->toIso8601String()));
                    $store->recordTarget($active);
                });

                $this->info("The refused switch to \"{$state->target}\" was cleared: the embedding model stays \"{$active}\".");

                return self::SUCCESS;
            }

            $previous = $state->previous ?? $active;
            $refusal = $this->returnRefusal($registry, $identity, $previous);

            if ($refusal !== null) {
                return $this->refuse("The switch to \"{$state->target}\" was not abandoned: {$refusal}. Nothing was changed.");
            }

            $return = new EmbeddingSwitchState(
                status: 'running',
                phase: 'preflight',
                target: $previous,
                previous: $active,
                startedAt: now()->toIso8601String(),
                updatedAt: now()->toIso8601String(),
            );

            // Stored as running before the job is queued: vector search stays suspended throughout.
            new Setting()->getConnection()->transaction(static function () use ($store, $return, $previous): void {
                $store->update(static fn (): EmbeddingSwitchState => $return);
                $store->recordTarget($previous);
            });
        } finally {
            $release();
        }

        if (! $this->dispatchSwitch($store, $state, $return)) {
            return self::FAILURE;
        }

        $this->info("Switch to \"{$state->target}\" abandoned: returning to \"{$previous}\" (vector search stays off until it ends). Follow it with ai:embeddings:status.");

        return self::SUCCESS;
    }

    /**
     * Why the switch back to the previous model cannot start, or null when it can: its profile is no
     * longer declared, or the embeddings server does not run its model (the same identity
     * check as the start's preflight).
     */
    private function returnRefusal(EmbeddingModelRegistry $registry, EmbeddingServerIdentity $identity, string $previous): ?string
    {
        try {
            $profile = $registry->get($previous);
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        }

        if ($profile->provider !== 'openai_compatible') {
            return null;
        }

        return $identity->refusal($profile);
    }

    /**
     * Why the stored switch cannot be resumed or abandoned, or null when it can: there is none, or it
     * is running and still making progress.
     */
    private function refusalToActOn(EmbeddingSwitchState $state, string $action): ?string
    {
        if ($state->status === 'idle') {
            return "No embedding model switch to {$action}.";
        }

        if ($state->status === 'running' && ! $state->isInterrupted()) {
            $since = $state->updatedAt ?? $state->startedAt ?? 'unknown';

            return "The switch to \"{$state->target}\" is running (phase {$state->phase}, {$state->progressLabel()}, last progress at {$since}): it cannot be resumed or abandoned while it runs. Nothing was changed.";
        }

        return null;
    }

    /**
     * Frees the overlap lock a dead run may still hold, then queues the switch job. When it cannot be
     * queued, the state goes back to `$before`, unless a synchronous run has already moved it on.
     */
    private function dispatchSwitch(EmbeddingSwitchStore $store, EmbeddingSwitchState $before, EmbeddingSwitchState $stored): bool
    {
        SwitchEmbeddingModelJob::releaseOverlapLock();

        try {
            app(Dispatcher::class)->dispatch(new SwitchEmbeddingModelJob());
        } catch (Throwable $exception) {
            $store->update(static fn (EmbeddingSwitchState $current): EmbeddingSwitchState => $current->toJson() === $stored->toJson() ? $before : $current);

            $this->error("Could not queue the switch job; nothing was changed ({$exception->getMessage()}).");

            return false;
        }

        return true;
    }

    /**
     * The reason the target cannot be switched to, or null when it can.
     */
    private function preflight(EmbeddingModelProfile $profile, EmbeddingDimensionProbe $probe, EmbeddingServerIdentity $identity): ?string
    {
        try {
            $probe->verify($profile);
        } catch (EmbeddingDimensionMismatch $mismatch) {
            return $mismatch->getMessage();
        } catch (Throwable $exception) {
            return "the embedding probe failed: {$exception->getMessage()}";
        }

        if ($profile->provider !== 'openai_compatible') {
            return null;
        }

        return $identity->refusal($profile);
    }

    /**
     * Stores the start as `running`, which suspends vector search before the job can run.
     */
    private function persistStart(EmbeddingSwitchStore $store, EmbeddingModelProfile $profile, string $active): void
    {
        $store->put(new EmbeddingSwitchState(
            status: 'running',
            phase: 'preflight',
            target: $profile->key,
            previous: $active,
            startedAt: now()->toIso8601String(),
        ));
    }

    /**
     * Undoes a stored start whose job could not be queued: the state is idle again, so vector search
     * is no longer suspended.
     */
    private function rollBackStart(EmbeddingSwitchStore $store): void
    {
        $store->put(EmbeddingSwitchState::idle());
    }

    /**
     * Prints why the start was refused, recording nothing: the profile is already active, another
     * start holds the lock, or a switch is already running or failed.
     */
    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }

    /**
     * Prints why the start was refused by its own checks, while this command still holds the start
     * lock and the state is idle. With `--report-failure` (the settings confirmation, whose queued
     * output nobody reads) the refusal is also stored as a failed switch in its preflight, so the
     * settings page locks the field and shows the reason. Vector search stays available: a switch
     * failed at its preflight does not suspend it.
     */
    private function refuseUnderLock(EmbeddingSwitchStore $store, EmbeddingModelProfile $profile, string $active, string $message): int
    {
        $this->error($message);

        if ((bool) $this->option('report-failure') && $store->get()->status === 'idle') {
            $store->put(new EmbeddingSwitchState(
                status: 'failed',
                phase: 'preflight',
                target: $profile->key,
                previous: $active,
                error: $message,
                startedAt: now()->toIso8601String(),
            ));
        }

        return self::FAILURE;
    }
}

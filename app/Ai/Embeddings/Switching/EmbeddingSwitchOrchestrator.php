<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings\Switching;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\AI\Jobs\IndexDocumentsChunkJob;
use Modules\Core\Search\Support\VectorProfileContext;
use Throwable;

/**
 * Advances a running embedding model switch one phase at a time, in order: `preflight`,
 * `embeddings`, `indexes`, `verify`, `activate`. The persisted state is the only memory between
 * steps, so a worker restart loses nothing: `SwitchEmbeddingModelJob` calls {@see self::advance()}
 * and dispatches itself again while {@see self::canAdvance()} holds. Every step writes the state,
 * which is also the heartbeat {@see EmbeddingSwitchState::isInterrupted()} reads. The write goes
 * through {@see EmbeddingSwitchStore::update()} and keeps the chunks completed while the step ran.
 *
 * Three phases wait on queued work. `embeddings` dispatches `GenerateEmbeddingsJob` with the target
 * for every record still missing a row of it, then stays in the phase until every record has one.
 * `indexes` prepares the indexes in one step and stores a chunk plan (model documents and, when a
 * switch rebuilds them, documentation documents), then dispatches one
 * `IndexDocumentsChunkJob` per pending chunk and stays in the phase until none is pending; `verify`
 * plans, dispatches and waits for its refresh the same way, then checks. While the queue of the
 * phase's jobs holds work the phase waits; when it is empty and work is still missing, it dispatches
 * what is missing again, up to {@see self::MAX_EMBEDDING_ROUNDS} (records) or
 * {@see self::MAX_CHUNK_ROUNDS} (chunks) times, then fails naming it. A chunked phase also fails
 * when its queue holds jobs and no chunk was written for {@see self::chunkStallSeconds()}: a missing
 * or dead `embeddings-index` worker would otherwise keep the switch waiting, and since every waiting
 * pass writes the state, the switch would never count as interrupted. The waiting passes keep
 * writing `updatedAt`, so {@see EmbeddingSwitchState::isInterrupted()} still means that the switch
 * job itself is lost; the stall is a failure of the phase, which `--resume` and `--abandon` accept.
 *
 * Every phase runs with the target's vector profile pinned ({@see VectorProfileContext::using()}),
 * so the embeddable models are discovered, counted and indexed for the target whether or not the
 * embeddings feature is on: a model is embeddable only while a vector profile is in force.
 *
 * A check that does not hold fails the switch in its phase ({@see EmbeddingSwitchPhaseFailed}).
 * Any other error propagates, so the job retries it and records it once its tries are spent.
 * The serving model and the previous model's rows are left as they are, and vector search stays
 * suspended while the state is `failed` ({@see EmbeddingSwitchStore::isSwitching()}).
 */
final readonly class EmbeddingSwitchOrchestrator
{
    /**
     * The phases this orchestrator knows how to complete.
     */
    public const array HANDLED_PHASES = ['preflight', 'embeddings', 'indexes', 'verify', 'activate'];

    public const int MAX_EMBEDDING_ROUNDS = 3;

    public const int MAX_CHUNK_ROUNDS = 3;

    /**
     * The config key of the longest time a chunked phase waits on a non-empty chunk queue without a
     * chunk written before it fails.
     */
    public const string CHUNK_STALL_CONFIG = 'ai.features.embeddings.index_chunk_stall_seconds';

    /**
     * Above the longest one chunk may legitimately take with the single shipped worker: 3 tries of
     * up to {@see IndexDocumentsChunkJob::TIMEOUT_SECONDS} (240 s) plus the backoff of 10 and 30 s,
     * 760 s, is about 3 times the supervisor timeout of 300 s.
     */
    public const int DEFAULT_CHUNK_STALL_SECONDS = 900;

    public function __construct(
        private EmbeddingSwitchStore $store,
        private EmbeddingSwitchPreview $preview,
        private EmbeddingModelRegistry $registry,
        private EmbeddingSwitchCorpus $corpus,
        private EmbeddingSwitchIndexes $indexes,
        private EmbeddingSwitchVerifier $verifier,
        private EmbeddingSwitchActivation $activation,
    ) {}

    /**
     * Runs the current phase of a running switch and stores the next state; returns the state
     * unchanged when the switch is not running.
     */
    public function advance(): EmbeddingSwitchState
    {
        $state = $this->store->get();

        if (! $this->canAdvance($state)) {
            return $state;
        }

        try {
            $target = $this->target($state);

            if ($state->phase === 'activate') {
                return $this->activation->activate($target);
            }

            $next = VectorProfileContext::using($target->vectorProfile(), fn (): EmbeddingSwitchState => match ($state->phase) {
                'preflight' => $this->completePreflight($state),
                'embeddings' => $this->embed($state),
                'indexes' => $this->rebuildIndexes($state),
                'verify' => $this->verify($state),
            });
        } catch (EmbeddingSwitchPhaseFailed $failure) {
            return $this->fail($failure->getMessage());
        }

        return $this->commit($state, $next->with(updatedAt: now()->toIso8601String()));
    }

    public function canAdvance(EmbeddingSwitchState $state): bool
    {
        return $state->status === 'running' && in_array($state->phase, self::HANDLED_PHASES, true);
    }

    /**
     * `ai.features.embeddings.index_chunk_stall_seconds`; below 1 or not a number falls back to
     * {@see self::DEFAULT_CHUNK_STALL_SECONDS}.
     */
    public function chunkStallSeconds(): int
    {
        $configured = config(self::CHUNK_STALL_CONFIG);

        return is_numeric($configured) && (int) $configured >= 1 ? (int) $configured : self::DEFAULT_CHUNK_STALL_SECONDS;
    }

    /**
     * Marks the switch failed in its current phase. The serving model is left as it is and vector
     * search stays suspended: the serving model is intact and the indexes may be half rebuilt.
     */
    public function fail(string $error): EmbeddingSwitchState
    {
        return $this->store->update(static fn (EmbeddingSwitchState $state): EmbeddingSwitchState => $state->status === 'running'
            ? $state->with(status: 'failed', error: $error, updatedAt: now()->toIso8601String())
            : $state);
    }

    /**
     * Stores `$next`, computed from `$snapshot`, keeping the chunks completed since the snapshot was
     * read. When the stored switch is no longer the one the step ran for (resumed, abandoned, failed
     * meanwhile), the stored state wins and `$next` is dropped.
     */
    private function commit(EmbeddingSwitchState $snapshot, EmbeddingSwitchState $next): EmbeddingSwitchState
    {
        return $this->store->update(static function (EmbeddingSwitchState $fresh) use ($snapshot, $next): EmbeddingSwitchState {
            if ($fresh->status !== 'running' || $fresh->phase !== $snapshot->phase || $fresh->target !== $snapshot->target || $fresh->startedAt !== $snapshot->startedAt) {
                return $fresh;
            }

            return $next->withCompletionsSince($snapshot, $fresh);
        });
    }

    /**
     * The command has verified the target before storing the state; what is left is to count the
     * work of the embeddings phase.
     */
    private function completePreflight(EmbeddingSwitchState $state): EmbeddingSwitchState
    {
        return $state->with(
            phase: 'embeddings',
            total: $this->preview->recordsToEmbed(),
            done: 0,
            rounds: 0,
            error: null,
        );
    }

    private function embed(EmbeddingSwitchState $state): EmbeddingSwitchState
    {
        $target = $this->target($state)->key;
        $progress = $this->corpus->progress($target);
        $counted = $state->with(total: $progress['total'], done: $progress['done']);

        if ($progress['pending'] === []) {
            return $this->afterEmbeddings($counted);
        }

        if ($state->rounds > 0 && Queue::size(GenerateEmbeddingsJob::QUEUE) > 0) {
            return $counted;
        }

        if ($state->rounds >= self::MAX_EMBEDDING_ROUNDS) {
            throw new EmbeddingSwitchPhaseFailed(count($progress['pending']) . " record(s) still have no embedding of \"{$target}\" after " . self::MAX_EMBEDDING_ROUNDS . ' rounds: ' . EmbeddingSwitchCorpus::describe($progress['pending']));
        }

        $this->dispatchEmbeddings($progress['pending'], $target);

        return $counted->with(rounds: $state->rounds + 1);
    }

    /**
     * Dispatches a full per-locale embedding of each record with the target. A dispatch that throws
     * (a synchronous job that failed, a queue that refused it) is logged: the record is still
     * missing at the next pass and is dispatched again in the next round.
     *
     * @param  list<Model>  $records
     */
    private function dispatchEmbeddings(array $records, string $target): void
    {
        foreach ($records as $record) {
            try {
                app(Dispatcher::class)->dispatch(new GenerateEmbeddingsJob($record->withoutRelations(), null, $target));
            } catch (Throwable $exception) {
                Log::warning('Embedding model switch: could not embed a record with the target', [
                    'model' => $record::class,
                    'model_id' => $record->getKey(),
                    'target' => $target,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * The phase after the embeddings. Normally `indexes`; a switch resumed after its `verify` refresh
     * ran out of rounds goes back to `verify`, which plans the refresh again: the indexes are not
     * the problem there, and a full refresh also rewrites documents of records edited while the
     * switch was failed.
     */
    private function afterEmbeddings(EmbeddingSwitchState $state): EmbeddingSwitchState
    {
        if ($state->chunkPhase === 'verify' && $state->pendingChunks !== []) {
            return $state->with(phase: 'verify', rounds: 0, chunkPhase: null, pendingChunks: [], chunksTotal: 0, chunksDone: 0);
        }

        return $state->with(phase: 'indexes', rounds: 0);
    }

    /**
     * Without a plan of its own, prepares the indexes (once-only work, in this step) and stores the
     * chunk plan; with one, dispatches and waits for its chunks. A plan left by a failed or
     * interrupted run is kept, so a resumed switch writes only the chunks still pending.
     */
    private function rebuildIndexes(EmbeddingSwitchState $state): EmbeddingSwitchState
    {
        $target = $this->target($state);

        if ($state->chunkPhase !== 'indexes') {
            $this->indexes->prepare($target, $this->activeDimensions());

            return $state->withChunkPlan('indexes', $this->indexes->plan(withDocumentation: true));
        }

        if ($state->pendingChunks === []) {
            return $state->withoutChunkPlan()->with(phase: 'verify', rounds: 0);
        }

        return $this->awaitChunks($state, $target);
    }

    /**
     * Rewrites every document with the target's vectors first, in chunks, then checks. A record
     * edited after the `indexes` phase is embedded with the target (the switch runs), but the normal
     * pipeline writes its document with the rows of the serving model, which is still the previous
     * one; the refresh replaces those documents, also when a switch left failed in `verify` is
     * resumed days later. A record edited between its chunk of the refresh and the activation keeps
     * such a document until it is saved again.
     */
    private function verify(EmbeddingSwitchState $state): EmbeddingSwitchState
    {
        $target = $this->target($state);

        if ($state->chunkPhase !== 'verify') {
            return $state->withChunkPlan('verify', $this->indexes->plan());
        }

        if ($state->pendingChunks !== []) {
            return $this->awaitChunks($state, $target);
        }

        $this->verifier->verify($target);

        return $state->with(phase: 'activate');
    }

    /**
     * Waits while the chunk queue holds jobs (after the first dispatch), failing when no chunk was
     * written for {@see self::chunkStallSeconds()}; otherwise dispatches every chunk still pending,
     * up to {@see self::MAX_CHUNK_ROUNDS} rounds, then fails naming them. Before that failure the
     * stored plan is read again: the last chunk may have been written after this pass read the state.
     *
     * @throws EmbeddingSwitchPhaseFailed
     */
    private function awaitChunks(EmbeddingSwitchState $state, EmbeddingModelProfile $target): EmbeddingSwitchState
    {
        if ($state->rounds > 0) {
            $queued = Queue::size(IndexDocumentsChunkJob::QUEUE);

            if ($queued > 0) {
                return $this->waitForChunks($state, $queued);
            }
        }

        if ($state->rounds >= self::MAX_CHUNK_ROUNDS) {
            $current = $state->withCompletionsSince($state, $this->store->get());

            if ($current->pendingChunks === []) {
                return $current;
            }

            throw new EmbeddingSwitchPhaseFailed(count($current->pendingChunks) . " of {$current->chunksTotal} index chunk(s) of the {$current->phase} phase were not written after " . self::MAX_CHUNK_ROUNDS . ' rounds (see the IndexDocumentsChunkJob errors in the log): ' . EmbeddingSwitchState::describeChunks($current->pendingChunks) . '. Run ai:embeddings:switch --resume to write them again');
        }

        foreach ($state->pendingChunks as $id => $chunk) {
            try {
                app(Dispatcher::class)->dispatch(new IndexDocumentsChunkJob((string) $state->phase, $target->key, $id, $chunk));
            } catch (Throwable $exception) {
                Log::warning('Embedding model switch: could not write an index chunk', [
                    'phase' => $state->phase,
                    'chunk' => $id,
                    'target' => $target->key,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $state->with(rounds: $state->rounds + 1, chunkProgressAt: now()->toIso8601String());
    }

    /**
     * The state of a pass that waits on `$queued` chunk jobs, or a failure when no chunk was written
     * for {@see self::chunkStallSeconds()} since the last dispatch or the last chunk written. A plan
     * stored before that time was recorded starts counting now.
     *
     * @throws EmbeddingSwitchPhaseFailed
     */
    private function waitForChunks(EmbeddingSwitchState $state, int $queued): EmbeddingSwitchState
    {
        if ($state->chunkProgressAt === null) {
            return $state->with(chunkProgressAt: now()->toIso8601String());
        }

        $stall = $this->chunkStallSeconds();

        try {
            $idle = Date::parse($state->chunkProgressAt)->diffInSeconds(Date::now(), absolute: false);
        } catch (Throwable) {
            return $state->with(chunkProgressAt: now()->toIso8601String());
        }

        if ($idle <= $stall) {
            return $state;
        }

        throw new EmbeddingSwitchPhaseFailed("no index chunk was written for {$stall} s while the " . IndexDocumentsChunkJob::QUEUE . " queue holds {$queued} job(s) ({$state->chunksDone} of {$state->chunksTotal} chunk(s) of the {$state->phase} phase written): check that a worker runs on " . IndexDocumentsChunkJob::QUEUE . ' (Horizon supervisor-embeddings-index), then run ai:embeddings:switch --resume');
    }

    /**
     * @throws EmbeddingSwitchPhaseFailed when the stored target is no longer a profile
     */
    private function target(EmbeddingSwitchState $state): EmbeddingModelProfile
    {
        try {
            return $this->registry->get((string) $state->target);
        } catch (InvalidArgumentException $exception) {
            throw new EmbeddingSwitchPhaseFailed($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * The dimensions of the serving model: what an index whose engine cannot report its mapping is
     * assumed to hold.
     */
    private function activeDimensions(): ?int
    {
        try {
            return $this->registry->get($this->registry->activeKey())->dimensions;
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings\Switching;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use JsonException;
use Throwable;

/**
 * Where an embedding model switch stands: idle, running a phase, or failed. Kept as JSON in the
 * managed setting `features.embeddings.switch`, so the panel and the command read the same progress.
 *
 * The `indexes` and `verify` phases write their documents in chunks: `chunkPhase` names the phase
 * the current chunk plan belongs to, `pendingChunks` holds the chunks not written yet (by id, with
 * the model and the key range each covers), `chunksTotal` and `chunksDone` count them, and
 * `chunkProgressAt` is the time of the last dispatch of chunks or the last chunk written, which a
 * waiting phase reads to tell a working chunk queue from a stalled one. A chunk is either a range
 * of an embeddable model's keys (`model` is the class) or, in the `indexes` plan only, a range of
 * documentation source names of one documentation profile (`model` is `rag:<profile>`, see
 * {@see self::RAG_CHUNK_PREFIX}). A state stored before these keys existed loads with no plan.
 */
final readonly class EmbeddingSwitchState
{
    public const array STATUSES = ['idle', 'running', 'failed'];

    public const array PHASES = ['preflight', 'embeddings', 'indexes', 'verify', 'activate'];

    public const array CHUNKED_PHASES = ['indexes', 'verify'];

    /**
     * The `model` of a documentation chunk starts with this, followed by the documentation profile.
     */
    public const string RAG_CHUNK_PREFIX = 'rag:';

    /**
     * A running switch whose state has not been written for this long has lost its job: no pass of
     * the switch job runs longer than {@see \Modules\AI\Jobs\SwitchEmbeddingModelJob::TIMEOUT_SECONDS},
     * a waiting phase writes the state on every pass, and every chunk written writes it too.
     */
    public const int INTERRUPTED_AFTER_SECONDS = 1800;

    public function __construct(
        public string $status = 'idle',
        public ?string $phase = null,
        public ?string $target = null,
        public ?string $previous = null,
        public int $total = 0,
        public int $done = 0,
        public ?string $error = null,
        public ?string $startedAt = null,
        public int $rounds = 0,
        public ?string $updatedAt = null,
        public ?string $chunkPhase = null,
        public int $chunksTotal = 0,
        public int $chunksDone = 0,
        /**
         * @var array<string, array{model: string, from: int|string|null, to: int|string|null}>
         */
        public array $pendingChunks = [],
        public ?string $chunkProgressAt = null,
    ) {}

    public static function idle(): self
    {
        return new self;
    }

    public static function fromJson(?string $json): self
    {
        if ($json === null || $json === '') {
            return self::idle();
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::idle();
        }

        if (! is_array($data) || ! in_array($data['status'] ?? null, self::STATUSES, true)) {
            return self::idle();
        }

        $phase = $data['phase'] ?? null;

        return new self(
            status: $data['status'],
            phase: in_array($phase, self::PHASES, true) ? $phase : null,
            target: self::nullableString($data['target'] ?? null),
            previous: self::nullableString($data['previous'] ?? null),
            total: self::count($data['total'] ?? null),
            done: self::count($data['done'] ?? null),
            error: self::nullableString($data['error'] ?? null),
            startedAt: self::nullableString($data['startedAt'] ?? null),
            rounds: self::count($data['rounds'] ?? null),
            updatedAt: self::nullableString($data['updatedAt'] ?? null),
            chunkPhase: in_array($data['chunkPhase'] ?? null, self::CHUNKED_PHASES, true) ? $data['chunkPhase'] : null,
            chunksTotal: self::count($data['chunksTotal'] ?? null),
            chunksDone: self::count($data['chunksDone'] ?? null),
            pendingChunks: self::chunks($data['pendingChunks'] ?? []),
            chunkProgressAt: self::nullableString($data['chunkProgressAt'] ?? null),
        );
    }

    /**
     * Whether `$chunk` covers documentation source names rather than an embeddable model's keys.
     *
     * @param  array{model: string, from: int|string|null, to: int|string|null}  $chunk
     */
    public static function isRagChunk(array $chunk): bool
    {
        return str_starts_with($chunk['model'], self::RAG_CHUNK_PREFIX);
    }

    /**
     * Readable references to chunks, at most ten of them: `Class [from, to)` for a model's key range,
     * `rag:<profile>:[from, to)` for a range of documentation source names.
     *
     * @param  array<string, array{model: string, from: int|string|null, to: int|string|null}>  $chunks
     */
    public static function describeChunks(array $chunks): string
    {
        $references = array_map(
            static fn (array $chunk): string => self::isRagChunk($chunk)
                ? $chunk['model'] . ':[' . ($chunk['from'] ?? 'start') . ', ' . ($chunk['to'] ?? 'end') . ')'
                : class_basename($chunk['model']) . ' [' . ($chunk['from'] ?? 'start') . ', ' . ($chunk['to'] ?? 'end') . ')',
            array_slice(array_values($chunks), 0, 10),
        );

        return implode(', ', $references) . (count($chunks) > 10 ? ', ...' : '');
    }

    /**
     * A copy carrying a new chunk plan for `$phase`: every chunk pending, none done, no round yet.
     *
     * @param  array<string, array{model: string, from: int|string|null, to: int|string|null}>  $chunks
     */
    public function withChunkPlan(string $phase, array $chunks): self
    {
        return $this->with(chunkPhase: $phase, pendingChunks: $chunks, chunksTotal: count($chunks), chunksDone: 0, rounds: 0, chunkProgressAt: null);
    }

    /**
     * A copy carrying no chunk plan: what a phase that is done with its chunks hands over, so the
     * next phase, or a resume, never mistakes it for a plan of its own.
     */
    public function withoutChunkPlan(): self
    {
        return $this->with(chunkPhase: null, pendingChunks: [], chunksTotal: 0, chunksDone: 0, chunkProgressAt: null);
    }

    /**
     * Whether a chunk job for `$phase` and `$target` is still expected to write `$chunk` under `$id`:
     * the switch runs that phase with that plan and the chunk is pending with the same key range.
     *
     * @param  array{model: string, from: int|string|null, to: int|string|null}  $chunk
     */
    public function expectsChunk(string $phase, string $target, string $id, array $chunk): bool
    {
        return $this->status === 'running'
            && $this->phase === $phase
            && $this->chunkPhase === $phase
            && $this->target === $target
            && ($this->pendingChunks[$id] ?? null) === $chunk;
    }

    /**
     * A copy with the chunk recorded as written, or this state unchanged when it no longer expects
     * the chunk (written already, another plan, another switch): completing twice counts once.
     *
     * @param  array{model: string, from: int|string|null, to: int|string|null}  $chunk
     */
    public function withChunkCompleted(string $phase, string $target, string $id, array $chunk, string $at): self
    {
        if (! $this->expectsChunk($phase, $target, $id, $chunk)) {
            return $this;
        }

        $pending = $this->pendingChunks;
        unset($pending[$id]);

        return $this->with(pendingChunks: $pending, chunksDone: $this->chunksDone + 1, updatedAt: $at, chunkProgressAt: $at);
    }

    /**
     * This state (computed from `$snapshot`) with the chunks completed since `$snapshot` was read,
     * as `$fresh` records them, also completed: chunk jobs only ever remove pending chunks, so a
     * chunk pending in the snapshot and gone from the fresh state was written meanwhile, and the
     * later of the two `chunkProgressAt` is kept. A state carrying a new plan keeps it whole: what was
     * completed belongs to the plan it replaced.
     */
    public function withCompletionsSince(self $snapshot, self $fresh): self
    {
        if ($this->chunkPhase !== $snapshot->chunkPhase) {
            return $this;
        }

        $completed = array_diff_key($snapshot->pendingChunks, $fresh->pendingChunks);
        $stillPending = array_filter(
            $this->pendingChunks,
            static fn (array $chunk, string $id): bool => ! isset($completed[$id]) || $completed[$id] !== $chunk,
            ARRAY_FILTER_USE_BOTH,
        );
        $newlyDone = count($this->pendingChunks) - count($stillPending);

        if ($newlyDone === 0) {
            return $this;
        }

        return $this->with(
            pendingChunks: $stillPending,
            chunksDone: $this->chunksDone + $newlyDone,
            chunkProgressAt: self::later($this->chunkProgressAt, $fresh->chunkProgressAt),
        );
    }

    /**
     * The progress of the current phase: index chunks written in a chunked phase with its plan,
     * otherwise the embedded (record, locale) pairs, as `done/total`.
     */
    public function progressLabel(): string
    {
        if ($this->phase !== null && $this->phase === $this->chunkPhase) {
            return "{$this->chunksDone}/{$this->chunksTotal} index chunks";
        }

        return "{$this->done}/{$this->total}";
    }

    /**
     * A copy with the given properties changed, by name.
     */
    public function with(mixed ...$changes): self
    {
        // @phpstan-ignore argument.type (a copy by named arguments: the constructor checks each one's type when it runs)
        return new self(...[...get_object_vars($this), ...$changes]);
    }

    /**
     * Whether a running switch has stopped making progress: its last write (or its start) is older
     * than {@see self::INTERRUPTED_AFTER_SECONDS}, or it carries no time at all.
     */
    public function isInterrupted(?CarbonInterface $now = null): bool
    {
        if ($this->status !== 'running') {
            return false;
        }

        $last = $this->updatedAt ?? $this->startedAt;

        if ($last === null) {
            return true;
        }

        try {
            $lastProgress = Date::parse($last);
        } catch (Throwable) {
            return true;
        }

        return $lastProgress->diffInSeconds($now ?? Date::now(), absolute: false) > self::INTERRUPTED_AFTER_SECONDS;
    }

    public function toJson(): string
    {
        return json_encode([
            'status' => $this->status,
            'phase' => $this->phase,
            'target' => $this->target,
            'previous' => $this->previous,
            'total' => $this->total,
            'done' => $this->done,
            'error' => $this->error,
            'startedAt' => $this->startedAt,
            'rounds' => $this->rounds,
            'updatedAt' => $this->updatedAt,
            'chunkPhase' => $this->chunkPhase,
            'chunksTotal' => $this->chunksTotal,
            'chunksDone' => $this->chunksDone,
            'pendingChunks' => $this->pendingChunks === [] ? (object) [] : $this->pendingChunks,
            'chunkProgressAt' => $this->chunkProgressAt,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * The later of two stored times; an unreadable or missing one loses.
     */
    private static function later(?string $first, ?string $second): ?string
    {
        if ($first === null || $second === null) {
            return $first ?? $second;
        }

        try {
            return Date::parse($second)->greaterThan(Date::parse($first)) ? $second : $first;
        } catch (Throwable) {
            return $first;
        }
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * The well-formed chunks of a decoded plan; anything else is dropped.
     *
     * @return array<string, array{model: string, from: int|string|null, to: int|string|null}>
     */
    private static function chunks(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $chunks = [];

        foreach ($value as $id => $chunk) {
            if (! is_string($id) || ! is_array($chunk) || ! is_string($chunk['model'] ?? null)) {
                continue;
            }

            $chunks[$id] = [
                'model' => $chunk['model'],
                'from' => self::key($chunk['from'] ?? null),
                'to' => self::key($chunk['to'] ?? null),
            ];
        }

        return $chunks;
    }

    private static function count(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function key(mixed $value): int|string|null
    {
        return is_int($value) || is_string($value) ? $value : null;
    }
}

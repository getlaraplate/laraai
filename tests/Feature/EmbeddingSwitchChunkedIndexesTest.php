<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchCorpus;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchIndexes;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchOrchestrator;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchState;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchStore;
use Modules\AI\Database\Seeders\AIDatabaseSeeder;
use Modules\AI\Jobs\IndexDocumentsChunkJob;
use Modules\AI\Jobs\SwitchEmbeddingModelJob;
use Modules\AI\Tests\Stubs\EmbeddableTestModel;
use Modules\AI\Tests\Stubs\EmbeddingSwitchHarness as Harness;
use Modules\Core\Events\ModelPreProcessingCompleted;

beforeEach(function (): void {
    Event::fake([ModelPreProcessingCompleted::class]);
    $this->seed(AIDatabaseSeeder::class);
    $this->engine = Harness::boot();
    $this->records = Harness::corpus($this->engine, 'One', 'Two', 'Three');
    $this->index = (new EmbeddableTestModel)->searchableAs();
    config()->set(EmbeddingSwitchIndexes::CHUNK_SIZE_CONFIG, 1);
});

function chunked_store(): EmbeddingSwitchStore
{
    return app(EmbeddingSwitchStore::class);
}

function chunked_orchestrator(): EmbeddingSwitchOrchestrator
{
    return app(EmbeddingSwitchOrchestrator::class);
}

/**
 * The chunk id and range that covers `$record` in the stored plan.
 *
 * @return array{0: string, 1: array{model: string, from: int|string|null, to: int|string|null}}
 */
function chunked_chunk_of(EmbeddableTestModel $record): array
{
    foreach (chunked_store()->get()->pendingChunks as $id => $chunk) {
        $key = $record->getKey();

        if (($chunk['from'] === null || $key >= $chunk['from']) && ($chunk['to'] === null || $key < $chunk['to'])) {
            return [$id, $chunk];
        }
    }

    throw new RuntimeException('no pending chunk covers record #' . $record->getKey());
}

it('splits the key space into ranges of the configured size, open at both ends, covering every record', function (): void {
    $keys = array_map(static fn (EmbeddableTestModel $record): int => $record->getKey(), $this->records);

    expect(app(EmbeddingSwitchCorpus::class)->keyRanges(EmbeddableTestModel::class, 2))->toBe([
        ['from' => null, 'to' => $keys[2]],
        ['from' => $keys[2], 'to' => null],
    ])
        ->and(app(EmbeddingSwitchCorpus::class)->keyRanges(EmbeddableTestModel::class, 250))->toBe([['from' => null, 'to' => null]]);

    EmbeddableTestModel::query()->delete();

    expect(app(EmbeddingSwitchCorpus::class)->keyRanges(EmbeddableTestModel::class, 2))->toBe([['from' => null, 'to' => null]]);
});

it('prepares the indexes and stores the chunk plan in one pass, then dispatches one chunk job per chunk on its queue', function (): void {
    app(EmbeddingSwitchStore::class)->put(new EmbeddingSwitchState('running', 'indexes', Harness::TARGET, Harness::ACTIVE, startedAt: now()->toIso8601String()));
    Queue::fake();

    $state = chunked_orchestrator()->advance();

    expect($state->phase)->toBe('indexes')
        ->and($state->chunkPhase)->toBe('indexes')
        ->and($state->chunksTotal)->toBe(3)
        ->and($state->chunksDone)->toBe(0)
        ->and($state->pendingChunks)->toHaveCount(3)
        ->and($state->rounds)->toBe(0)
        ->and($state->progressLabel())->toBe('0/3 index chunks')
        ->and($this->engine->documentsOf($this->index))->toBe([])
        ->and(Harness::$ragRebuilds)->toBe([['target' => Harness::TARGET, 'dimensions' => 384]]);
    Queue::assertNothingPushed();

    $state = chunked_orchestrator()->advance();

    expect($state->rounds)->toBe(1);
    Queue::assertPushed(IndexDocumentsChunkJob::class, 3);
    Queue::assertPushedOn(IndexDocumentsChunkJob::QUEUE, IndexDocumentsChunkJob::class);
    Queue::assertPushed(IndexDocumentsChunkJob::class, static fn (IndexDocumentsChunkJob $job): bool => $job->phase === 'indexes' && $job->target === Harness::TARGET);

    $state = chunked_orchestrator()->advance();

    expect($state->phase)->toBe('indexes')
        ->and($state->rounds)->toBe(1)
        ->and(Harness::$ragRebuilds)->toHaveCount(1);
    Queue::assertPushed(IndexDocumentsChunkJob::class, 3);
});

it('keeps the chunks written while a pass ran: completions are merged into what the pass stores', function (): void {
    Harness::start(Harness::TARGET);
    Harness::advanceUntil('indexes');
    chunked_orchestrator()->advance();

    // On the sync queue every chunk job runs, and records its completion, inside the dispatching pass.
    $state = chunked_orchestrator()->advance();

    expect($state->pendingChunks)->toBe([])
        ->and($state->chunksDone)->toBe(3)
        ->and($state->rounds)->toBe(1)
        ->and(chunked_store()->get()->chunksDone)->toBe(3)
        ->and($this->engine->documentsOf($this->index))->toHaveCount(3);

    foreach ($this->engine->documentsOf($this->index) as $document) {
        expect($document['embeddings'][0]['vector'][0])->toBe(Harness::vectorValue(Harness::TARGET));
    }

    expect(chunked_orchestrator()->advance()->phase)->toBe('verify');
});

it('writes a chunk again harmlessly and skips a chunk the state no longer expects', function (): void {
    app(EmbeddingSwitchStore::class)->put(new EmbeddingSwitchState('running', 'indexes', Harness::TARGET, Harness::ACTIVE, startedAt: now()->toIso8601String()));
    chunked_orchestrator()->advance();
    [$id, $chunk] = chunked_chunk_of($this->records[0]);
    $job = new IndexDocumentsChunkJob('indexes', Harness::TARGET, $id, $chunk);

    app()->call([$job, 'handle']);
    $writes = $this->engine->writes;
    app()->call([$job, 'handle']);

    $state = chunked_store()->get();

    expect($state->chunksDone)->toBe(1)
        ->and($state->pendingChunks)->toHaveCount(2)
        ->and($state->pendingChunks)->not->toHaveKey($id)
        ->and($this->engine->writes)->toBe($writes)
        ->and($this->engine->documentsOf($this->index))->toHaveKey((string) $this->records[0]->getKey());

    [$otherId, $otherChunk] = chunked_chunk_of($this->records[1]);
    $stale = new IndexDocumentsChunkJob('indexes', Harness::TARGET, $otherId, [...$otherChunk, 'to' => null]);
    app()->call([$stale, 'handle']);

    $abandoned = new IndexDocumentsChunkJob('indexes', Harness::WIDE, $otherId, $otherChunk);
    app()->call([$abandoned, 'handle']);

    expect(chunked_store()->get()->chunksDone)->toBe(1)
        ->and($this->engine->documentsOf($this->index))->not->toHaveKey((string) $this->records[1]->getKey());

    $first = chunked_store()->get();

    expect($first->withChunkCompleted('indexes', Harness::TARGET, $otherId, $otherChunk, 'now')->withChunkCompleted('indexes', Harness::TARGET, $otherId, $otherChunk, 'now')->chunksDone)->toBe(2);
});

it('dispatches the pending chunks again for a bounded number of rounds, then fails naming them', function (): void {
    $refused = $this->records[1];
    $this->engine->refusedDocuments = [(string) $refused->getKey()];
    Harness::start(Harness::TARGET);

    $state = Harness::advanceUntil();

    expect($state->status)->toBe('failed')
        ->and($state->phase)->toBe('indexes')
        ->and($state->rounds)->toBe(EmbeddingSwitchOrchestrator::MAX_CHUNK_ROUNDS)
        ->and($state->chunksDone)->toBe(2)
        ->and($state->pendingChunks)->toHaveCount(1)
        ->and($state->error)->toContain('1 of 3 index chunk(s) of the indexes phase were not written after 3 rounds')
        ->and($state->error)->toContain('EmbeddableTestModel [' . $refused->getKey() . ', ' . $this->records[2]->getKey() . ')')
        ->and($state->error)->toContain('ai:embeddings:switch --resume')
        ->and(Harness::setting('features.embeddings.serving_model'))->toBe(Harness::ACTIVE);
});

it('resumes a failed index build by dispatching only the chunks still pending, without preparing the indexes again', function (): void {
    $refused = $this->records[1];
    $this->engine->refusedDocuments = [(string) $refused->getKey()];
    Harness::start(Harness::TARGET);
    Harness::advanceUntil();
    $failed = chunked_store()->get();
    [$pendingId] = chunked_chunk_of($refused);
    $this->engine->refusedDocuments = [];
    $createdIndexes = $this->engine->createdIndexes;
    Queue::fake();

    $this->artisan('ai:embeddings:switch', ['--resume' => true])->assertSuccessful();
    Queue::assertPushedOn(SwitchEmbeddingModelJob::QUEUE, SwitchEmbeddingModelJob::class);

    expect(chunked_orchestrator()->advance()->phase)->toBe('indexes');

    $state = chunked_orchestrator()->advance();

    expect($failed->pendingChunks)->toHaveKey($pendingId)
        ->and($state->chunkPhase)->toBe('indexes')
        ->and($state->chunksDone)->toBe(2)
        ->and($state->rounds)->toBe(1)
        ->and($this->engine->createdIndexes)->toBe($createdIndexes)
        ->and($this->engine->documentsOf($this->index))->toHaveCount(2)
        ->and(Harness::$ragRebuilds)->toHaveCount(1);
    Queue::assertPushed(IndexDocumentsChunkJob::class, 1);
    Queue::assertPushed(IndexDocumentsChunkJob::class, static fn (IndexDocumentsChunkJob $job): bool => $job->chunkId === $pendingId);
});

it('refreshes the documents in chunks at the start of the verification, then checks', function (): void {
    Harness::start(Harness::TARGET);
    Harness::advanceUntil('verify');
    Queue::fake();
    $vectorQueries = count($this->engine->vectorQueries);

    $state = chunked_orchestrator()->advance();

    expect($state->phase)->toBe('verify')
        ->and($state->chunkPhase)->toBe('verify')
        ->and($state->chunksTotal)->toBe(3)
        ->and($state->pendingChunks)->toHaveCount(3);

    chunked_orchestrator()->advance();

    Queue::assertPushed(IndexDocumentsChunkJob::class, 3);
    Queue::assertPushed(IndexDocumentsChunkJob::class, static fn (IndexDocumentsChunkJob $job): bool => $job->phase === 'verify');
    expect($this->engine->vectorQueries)->toHaveCount($vectorQueries)
        ->and(chunked_store()->get()->phase)->toBe('verify');
});

it('resumes a verification whose refresh ran out of rounds at the refresh, without rebuilding the indexes', function (): void {
    Harness::start(Harness::TARGET);
    Harness::advanceUntil('verify');
    $this->engine->refusedDocuments = [(string) $this->records[0]->getKey()];

    $failed = Harness::advanceUntil();

    expect($failed->status)->toBe('failed')
        ->and($failed->phase)->toBe('verify')
        ->and($failed->error)->toContain('of the verify phase');

    $this->engine->refusedDocuments = [];
    $createdIndexes = $this->engine->createdIndexes;
    Queue::fake();

    $this->artisan('ai:embeddings:switch', ['--resume' => true])->assertSuccessful();
    $state = chunked_orchestrator()->advance();

    expect($state->phase)->toBe('verify')
        ->and($state->chunkPhase)->toBeNull();

    $state = chunked_orchestrator()->advance();

    expect($state->chunkPhase)->toBe('verify')
        ->and($state->pendingChunks)->toHaveCount(3)
        ->and($this->engine->createdIndexes)->toBe($createdIndexes)
        ->and($this->engine->documentsOf($this->index))->toHaveCount(3);
});

it('shows the chunk progress in the status command', function (): void {
    app(EmbeddingSwitchStore::class)->put(new EmbeddingSwitchState('running', 'indexes', Harness::TARGET, Harness::ACTIVE, startedAt: now()->toIso8601String()));
    Queue::fake();
    chunked_orchestrator()->advance();

    $this->artisan('ai:embeddings:status')
        ->expectsOutputToContain('0/3 written (indexes phase, 3 pending, round 0)')
        ->assertSuccessful();
});

it('ships the chunk queue with its own single-process Horizon supervisor, timed out above a chunk', function (): void {
    $supervisor = config('horizon.defaults.supervisor-embeddings-index');

    expect(IndexDocumentsChunkJob::QUEUE)->toBe('embeddings-index')
        ->and(IndexDocumentsChunkJob::QUEUE)->not->toBe(SwitchEmbeddingModelJob::QUEUE)
        ->and($supervisor['queue'] ?? null)->toBe([IndexDocumentsChunkJob::QUEUE])
        ->and($supervisor['processes'] ?? null)->toBe(1)
        ->and($supervisor['timeout'] ?? 0)->toBeGreaterThan(IndexDocumentsChunkJob::TIMEOUT_SECONDS)
        ->and(config('horizon.defaults.supervisor-embeddings-switch.queue'))->not->toContain(IndexDocumentsChunkJob::QUEUE);

    foreach (config('horizon.environments') as $environment => $supervisors) {
        expect($supervisors)->toHaveKey('supervisor-embeddings-index', message: "Horizon environment {$environment} has no chunk supervisor")
            ->and($supervisors['supervisor-embeddings-index']['timeout'] ?? $supervisor['timeout'])->toBeGreaterThan(IndexDocumentsChunkJob::TIMEOUT_SECONDS)
            ->and($supervisors['supervisor-embeddings-index']['maxProcesses'] ?? $supervisors['supervisor-embeddings-index']['processes'] ?? 1)->toBe(1);
    }
});

it('fails a chunked phase naming its queue when the queue holds jobs and no chunk completed for the stall time', function (): void {
    config()->set(EmbeddingSwitchOrchestrator::CHUNK_STALL_CONFIG, 600);
    app(EmbeddingSwitchStore::class)->put(new EmbeddingSwitchState('running', 'indexes', Harness::TARGET, Harness::ACTIVE, startedAt: now()->toIso8601String()));
    Queue::fake();
    chunked_orchestrator()->advance();
    chunked_orchestrator()->advance();

    $this->travel(500)->seconds();
    [$id, $chunk] = chunked_chunk_of($this->records[0]);
    app()->call([new IndexDocumentsChunkJob('indexes', Harness::TARGET, $id, $chunk), 'handle']);

    $this->travel(599)->seconds();
    $waiting = chunked_orchestrator()->advance();

    expect($waiting->status)->toBe('running')
        ->and($waiting->isInterrupted())->toBeFalse();

    $this->travel(2)->seconds();
    $state = chunked_orchestrator()->advance();

    expect($state->status)->toBe('failed')
        ->and($state->phase)->toBe('indexes')
        ->and($state->error)->toContain('no index chunk was written for 600 s')
        ->and($state->error)->toContain('embeddings-index')
        ->and($state->error)->toContain('supervisor-embeddings-index');
});

it('re-reads the pending chunks before failing the last round, and moves on when the last one was written meanwhile', function (): void {
    app(EmbeddingSwitchStore::class)->put(new EmbeddingSwitchState('running', 'indexes', Harness::TARGET, Harness::ACTIVE, startedAt: now()->toIso8601String()));
    Queue::fake();
    chunked_orchestrator()->advance();
    $plan = chunked_store()->get();
    [$lastId, $lastChunk] = chunked_chunk_of($this->records[2]);
    $others = array_diff_key($plan->pendingChunks, [$lastId => true]);
    chunked_store()->put($plan->with(
        pendingChunks: [$lastId => $lastChunk],
        chunksDone: count($others),
        rounds: EmbeddingSwitchOrchestrator::MAX_CHUNK_ROUNDS,
        chunkProgressAt: now()->toIso8601String(),
    ));

    // The last chunk job records its completion while the pass reads the (now empty) queue.
    Queue::shouldReceive('size')->andReturnUsing(static function () use ($lastId, $lastChunk): int {
        chunked_store()->update(static fn (EmbeddingSwitchState $state): EmbeddingSwitchState => $state->withChunkCompleted('indexes', Harness::TARGET, $lastId, $lastChunk, now()->toIso8601String()));

        return 0;
    });

    $state = chunked_orchestrator()->advance();

    expect($state->status)->toBe('running')
        ->and($state->pendingChunks)->toBe([])
        ->and($state->chunksDone)->toBe(3)
        ->and(chunked_orchestrator()->advance()->phase)->toBe('verify');
});

it('clears the chunk plan when the indexes phase hands over to the verification', function (): void {
    Harness::start(Harness::TARGET);
    Harness::advanceUntil('indexes');
    chunked_orchestrator()->advance();
    chunked_orchestrator()->advance();

    $state = chunked_orchestrator()->advance();

    expect($state->phase)->toBe('verify')
        ->and($state->chunkPhase)->toBeNull()
        ->and($state->pendingChunks)->toBe([])
        ->and($state->chunksTotal)->toBe(0)
        ->and($state->chunksDone)->toBe(0)
        ->and($state->rounds)->toBe(0);

    // Interrupted right after the hand-over: the resume rebuilds the indexes instead of skipping them.
    $this->travel(EmbeddingSwitchState::INTERRUPTED_AFTER_SECONDS + 60)->seconds();
    Queue::fake();
    $this->artisan('ai:embeddings:switch', ['--resume' => true])->assertSuccessful();

    $resumed = chunked_orchestrator()->advance();

    expect($resumed->phase)->toBe('indexes')
        ->and($resumed->chunkPhase)->toBe('indexes')
        ->and($resumed->pendingChunks)->toHaveCount(3)
        ->and(Harness::$ragRebuilds)->toHaveCount(2);
});

it('falls back to the default chunk size when the configured one is below 1 or not a number, and caps it', function (mixed $configured, int $expected): void {
    config()->set(EmbeddingSwitchIndexes::CHUNK_SIZE_CONFIG, $configured);

    expect(app(EmbeddingSwitchIndexes::class)->chunkSize())->toBe($expected);
})->with([
    'zero' => [0, EmbeddingSwitchIndexes::DEFAULT_CHUNK_SIZE],
    'negative' => [-5, EmbeddingSwitchIndexes::DEFAULT_CHUNK_SIZE],
    'not a number' => ['many', EmbeddingSwitchIndexes::DEFAULT_CHUNK_SIZE],
    'numeric string' => ['40', 40],
    'too large' => [10_000_000, EmbeddingSwitchIndexes::MAX_CHUNK_SIZE],
]);

it('writes the documentation in chunks of the indexes plan, with the target, and leaves it out of the verify refresh', function (): void {
    Harness::$ragPlan = [
        'rag:developer#0' => ['model' => 'rag:developer', 'from' => null, 'to' => 'm.md'],
        'rag:developer#1' => ['model' => 'rag:developer', 'from' => 'm.md', 'to' => null],
        'rag:user#0' => ['model' => 'rag:user', 'from' => null, 'to' => null],
    ];
    app(EmbeddingSwitchStore::class)->put(new EmbeddingSwitchState('running', 'indexes', Harness::TARGET, Harness::ACTIVE, startedAt: now()->toIso8601String()));
    Queue::fake();

    $state = chunked_orchestrator()->advance();

    expect($state->chunksTotal)->toBe(6)
        ->and($state->pendingChunks)->toHaveKeys(array_keys(Harness::$ragPlan))
        ->and(Harness::$ragWrites)->toBe([]);

    chunked_orchestrator()->advance();

    Queue::assertPushed(IndexDocumentsChunkJob::class, 6);
    Queue::assertPushed(IndexDocumentsChunkJob::class, static fn (IndexDocumentsChunkJob $job): bool => $job->chunkId === 'rag:user#0' && $job->phase === 'indexes' && $job->target === Harness::TARGET);

    $job = new IndexDocumentsChunkJob('indexes', Harness::TARGET, 'rag:user#0', Harness::$ragPlan['rag:user#0']);
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    expect(Harness::$ragWrites)->toBe([['target' => Harness::TARGET, 'model' => 'rag:user', 'from' => null, 'to' => null]])
        ->and(chunked_store()->get()->pendingChunks)->not->toHaveKey('rag:user#0')
        ->and(chunked_store()->get()->chunksDone)->toBe(1);

    $stale = new IndexDocumentsChunkJob('indexes', Harness::TARGET, 'rag:developer#0', [...Harness::$ragPlan['rag:developer#0'], 'to' => 'z.md']);
    app()->call([$stale, 'handle']);

    expect(Harness::$ragWrites)->toHaveCount(1);

    chunked_store()->put(chunked_store()->get()->withoutChunkPlan()->with(phase: 'verify', rounds: 0));
    $verify = chunked_orchestrator()->advance();

    expect($verify->chunkPhase)->toBe('verify')
        ->and($verify->chunksTotal)->toBe(3)
        ->and(array_filter(array_keys($verify->pendingChunks), static fn (string $id): bool => str_starts_with($id, 'rag:')))->toBe([]);
});

it('fails naming the documentation chunks not written, and resumes by writing only those', function (): void {
    Harness::$ragPlan = [
        'rag:developer#0' => ['model' => 'rag:developer', 'from' => null, 'to' => 'm.md'],
        'rag:user#0' => ['model' => 'rag:user', 'from' => null, 'to' => null],
    ];
    Harness::$refusedRagChunks = ['rag:user'];
    Harness::start(Harness::TARGET);

    $failed = Harness::advanceUntil();

    expect($failed->status)->toBe('failed')
        ->and($failed->phase)->toBe('indexes')
        ->and(array_keys($failed->pendingChunks))->toBe(['rag:user#0'])
        ->and($failed->error)->toContain('1 of 5 index chunk(s) of the indexes phase were not written')
        ->and($failed->error)->toContain('rag:user:[start, end)');

    Harness::$refusedRagChunks = [];
    Harness::$ragWrites = [];
    Queue::fake();

    $this->artisan('ai:embeddings:switch', ['--resume' => true])->assertSuccessful();
    chunked_orchestrator()->advance();
    chunked_orchestrator()->advance();

    Queue::assertPushed(IndexDocumentsChunkJob::class, 1);
    Queue::assertPushed(IndexDocumentsChunkJob::class, static fn (IndexDocumentsChunkJob $job): bool => $job->chunkId === 'rag:user#0');
    expect(Harness::$ragRebuilds)->toHaveCount(1);
});

it('falls back to the default documentation chunk size when the configured one is below 1 or not a number, and caps it', function (mixed $configured, int $expected): void {
    config()->set(EmbeddingSwitchIndexes::RAG_CHUNK_SIZE_CONFIG, $configured);

    expect(app(EmbeddingSwitchIndexes::class)->ragChunkSize())->toBe($expected);
})->with([
    'unset' => [null, EmbeddingSwitchIndexes::DEFAULT_RAG_CHUNK_SIZE],
    'zero' => [0, EmbeddingSwitchIndexes::DEFAULT_RAG_CHUNK_SIZE],
    'not a number' => ['few', EmbeddingSwitchIndexes::DEFAULT_RAG_CHUNK_SIZE],
    'configured' => [5, 5],
    'too large' => [100_000, EmbeddingSwitchIndexes::MAX_RAG_CHUNK_SIZE],
]);

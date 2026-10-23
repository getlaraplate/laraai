<?php

declare(strict_types=1);

use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchState;

it('round-trips through JSON', function (): void {
    $state = new EmbeddingSwitchState(
        status: 'running',
        phase: 'embeddings',
        target: 'openai_compatible:all-MiniLM-L6-v2',
        previous: 'openai_compatible:intfloat/multilingual-e5-small',
        total: 10,
        done: 4,
        error: null,
        startedAt: '2026-10-05T10:00:00+00:00',
    );

    expect(EmbeddingSwitchState::fromJson($state->toJson()))->toEqual($state);
});

it('degrades to idle on null, empty and invalid JSON', function (?string $json): void {
    $state = EmbeddingSwitchState::fromJson($json);

    expect($state->status)->toBe('idle')
        ->and($state->phase)->toBeNull()
        ->and($state->target)->toBeNull()
        ->and($state->total)->toBe(0)
        ->and($state->done)->toBe(0);
})->with([
    'null' => [null],
    'empty' => [''],
    'garbage' => ['{not json'],
    'scalar' => ['"x"'],
    'unknown status' => ['{"status":"weird"}'],
]);

it('exposes an idle factory', function (): void {
    expect(EmbeddingSwitchState::idle()->status)->toBe('idle');
});

it('loads a state stored before the chunk plan existed with no plan', function (): void {
    $state = EmbeddingSwitchState::fromJson('{"status":"failed","phase":"indexes","target":"t","previous":"p","total":3,"done":3,"error":"boom","startedAt":"2026-10-05T10:00:00+00:00","rounds":1,"updatedAt":"2026-10-05T10:05:00+00:00"}');

    expect($state->status)->toBe('failed')
        ->and($state->phase)->toBe('indexes')
        ->and($state->rounds)->toBe(1)
        ->and($state->chunkPhase)->toBeNull()
        ->and($state->chunksTotal)->toBe(0)
        ->and($state->chunksDone)->toBe(0)
        ->and($state->pendingChunks)->toBe([])
        ->and($state->progressLabel())->toBe('3/3');
});

it('round-trips a chunk plan through JSON and drops malformed chunks', function (): void {
    $state = (new EmbeddingSwitchState('running', 'indexes', 't', 'p'))->withChunkPlan('indexes', [
        'App\Models\Post#0' => ['model' => 'App\Models\Post', 'from' => null, 'to' => 251],
        'App\Models\Post#1' => ['model' => 'App\Models\Post', 'from' => 251, 'to' => null],
        'rag:user#0' => ['model' => 'rag:user', 'from' => null, 'to' => 'faq-module-AI/guide.md'],
    ])->with(chunkProgressAt: '2026-10-06T10:00:00+00:00');

    expect(EmbeddingSwitchState::fromJson($state->toJson()))->toEqual($state)
        ->and(EmbeddingSwitchState::fromJson((new EmbeddingSwitchState('idle'))->toJson())->pendingChunks)->toBe([])
        ->and(EmbeddingSwitchState::fromJson('{"status":"running","chunkPhase":"embeddings","pendingChunks":{"a":{"model":"M","from":1,"to":2},"b":{"from":1},"c":"x"}}'))
        ->chunkPhase->toBeNull()
        ->pendingChunks->toBe(['a' => ['model' => 'M', 'from' => 1, 'to' => 2]]);
});

it('records a chunk once, and only for the plan, phase and target that expect it', function (): void {
    $chunk = ['model' => 'M', 'from' => null, 'to' => null];
    $state = (new EmbeddingSwitchState('running', 'indexes', 't', 'p'))->withChunkPlan('indexes', ['M#0' => $chunk]);

    $done = $state->withChunkCompleted('indexes', 't', 'M#0', $chunk, '2026-10-06T10:00:00+00:00');

    expect($done->pendingChunks)->toBe([])
        ->and($done->chunksDone)->toBe(1)
        ->and($done->updatedAt)->toBe('2026-10-06T10:00:00+00:00')
        ->and($done->chunkProgressAt)->toBe('2026-10-06T10:00:00+00:00')
        ->and($done->withChunkCompleted('indexes', 't', 'M#0', $chunk, 'later'))->toBe($done)
        ->and($state->withChunkCompleted('verify', 't', 'M#0', $chunk, 'x'))->toBe($state)
        ->and($state->withChunkCompleted('indexes', 'other', 'M#0', $chunk, 'x'))->toBe($state)
        ->and($state->withChunkCompleted('indexes', 't', 'M#0', [...$chunk, 'to' => 5], 'x'))->toBe($state)
        ->and($state->with(status: 'failed')->withChunkCompleted('indexes', 't', 'M#0', $chunk, 'x')->chunksDone)->toBe(0);
});

it('keeps the chunks completed since a snapshot when a state computed from it is stored', function (): void {
    $chunk = static fn (int $from): array => ['model' => 'M', 'from' => $from, 'to' => $from + 1];
    $snapshot = (new EmbeddingSwitchState('running', 'indexes', 't', 'p'))->withChunkPlan('indexes', ['a' => $chunk(1), 'b' => $chunk(2), 'c' => $chunk(3)]);
    $fresh = $snapshot->withChunkCompleted('indexes', 't', 'a', $chunk(1), 'x')->withChunkCompleted('indexes', 't', 'b', $chunk(2), 'x');
    $next = $snapshot->with(rounds: 1);

    $merged = $next->withCompletionsSince($snapshot, $fresh);

    expect($merged->pendingChunks)->toBe(['c' => $chunk(3)])
        ->and($merged->chunksDone)->toBe(2)
        ->and($merged->rounds)->toBe(1)
        ->and($next->withCompletionsSince($snapshot, $snapshot))->toBe($next);

    $replanned = $snapshot->withChunkPlan('verify', ['a' => $chunk(1)]);

    expect($replanned->withCompletionsSince($snapshot, $fresh))->toBe($replanned)
        ->and(EmbeddingSwitchState::describeChunks(['a' => $chunk(1), 'z' => ['model' => 'App\Models\Post', 'from' => null, 'to' => null]]))->toBe('M [1, 2), Post [start, end)');
});

it('names documentation chunks by profile and source range, model chunks by class and key range', function (): void {
    expect(EmbeddingSwitchState::describeChunks([
        'App\Models\Post#1' => ['model' => 'App\Models\Post', 'from' => 251, 'to' => null],
        'rag:user#0' => ['model' => 'rag:user', 'from' => null, 'to' => 'faq-module-AI/guide.md'],
    ]))->toBe('Post [251, end), rag:user:[start, faq-module-AI/guide.md)')
        ->and(EmbeddingSwitchState::isRagChunk(['model' => 'rag:developer', 'from' => null, 'to' => null]))->toBeTrue()
        ->and(EmbeddingSwitchState::isRagChunk(['model' => 'App\Models\Post', 'from' => null, 'to' => null]))->toBeFalse();
});

it('keeps the later chunk progress time when it merges completions', function (): void {
    $chunks = ['M#0' => ['model' => 'M', 'from' => null, 'to' => 5], 'M#1' => ['model' => 'M', 'from' => 5, 'to' => null]];
    $snapshot = (new EmbeddingSwitchState('running', 'indexes', 't', 'p'))->withChunkPlan('indexes', $chunks)->with(chunkProgressAt: '2026-10-06T10:00:00+00:00');
    $fresh = $snapshot->withChunkCompleted('indexes', 't', 'M#0', $chunks['M#0'], '2026-10-06T10:05:00+00:00');

    $merged = $snapshot->with(rounds: 1)->withCompletionsSince($snapshot, $fresh);

    expect($merged->pendingChunks)->toBe(['M#1' => $chunks['M#1']])
        ->and($merged->chunkProgressAt)->toBe('2026-10-06T10:05:00+00:00')
        ->and($snapshot->withoutChunkPlan()->chunkPhase)->toBeNull()
        ->and($snapshot->withoutChunkPlan()->chunkProgressAt)->toBeNull();
});

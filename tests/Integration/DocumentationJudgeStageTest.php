<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Ai\Rag\Judge\RelevanceJudge;
use Modules\AI\Ai\Rag\Judge\RelevanceJudgeStage;
use Modules\AI\Jobs\LogRagQueryJob;
use Modules\AI\Models\Conversation;
use Modules\AI\Tests\Stubs\Assistance\ScriptedAssistantFixtures;
use Modules\Core\Models\User;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\RAG\Document;
use NeuronAI\Testing\FakeAIProvider;

/**
 * A stage whose judge is Neuron's fake provider, answering with the given verdicts in order; with
 * none left, it fails like a provider that is down.
 *
 * @param  list<array<string, mixed>>  $verdicts
 */
function judgeStageAnswering(array $verdicts, ?FakeAIProvider &$provider = null): RelevanceJudgeStage
{
    $provider = new FakeAIProvider(...array_map(
        static fn (array $verdict): AssistantMessage => new AssistantMessage(json_encode($verdict, JSON_THROW_ON_ERROR)),
        $verdicts,
    ));

    return new RelevanceJudgeStage(new RelevanceJudge(
        static fn (string $system): ChatAgent => ChatAgent::make(systemPrompt: $system)->setAiProvider($provider),
    ));
}

function judgeStageDocument(string $label): Document
{
    return (new Document('Documentation about ' . $label . '.'))->setSourceName($label)->setMetadata(['locale' => 'en']);
}

/**
 * @param  list<Document>  $documents
 * @return list<string>
 */
function judgeStageLabels(array $documents): array
{
    return array_map(static fn (Document $document): string => $document->getSourceName(), $documents);
}

/**
 * A retrieval answering each query from the map, recording what it was asked.
 *
 * @param  array<string, list<Document>>  $byQuery
 * @param  list<string>  $asked
 */
function judgeStageRetrieval(array $byQuery, array &$asked): Closure
{
    return static function (string $query) use ($byQuery, &$asked): array {
        $asked[] = $query;

        return $byQuery[$query] ?? [];
    };
}

beforeEach(function (): void {
    config()->set('ai.features.rag.judge.enabled', true);
    config()->set('ai.features.rag.judge.max_rounds', 3);
    config()->set('ai.features.rag.judge.min_sources', 3);
    config()->set('ai.features.rag.judge.excerpt_chars', 600);
});

it('off by default leaves candidates untouched', function (): void {
    config()->set('ai.features.rag.judge.enabled', null);
    $candidates = [judgeStageDocument('A'), judgeStageDocument('B')];
    $asked = [];

    $run = judgeStageAnswering([], $provider)->run('question', $candidates, judgeStageRetrieval([], $asked));

    expect($run->documents)->toBe($candidates)
        ->and($run->applied)->toBeFalse()
        ->and($run->rounds)->toBe([])
        ->and($asked)->toBe([])
        ->and($provider->getCallCount())->toBe(0);
});

it('keeps only the relevant ids', function (): void {
    config()->set('ai.features.rag.judge.min_sources', 1);
    $asked = [];

    $run = judgeStageAnswering([['relevant' => [2], 'next_query' => null]])
        ->run('question', [judgeStageDocument('A'), judgeStageDocument('B'), judgeStageDocument('C')], judgeStageRetrieval([], $asked));

    expect(judgeStageLabels($run->documents))->toBe(['B'])
        ->and($run->fallback)->toBeFalse();
});

it('stops once min_sources are accepted', function (): void {
    config()->set('ai.features.rag.judge.min_sources', 2);
    $asked = [];

    $run = judgeStageAnswering([
        ['relevant' => [1], 'next_query' => 'precise query'],
        ['relevant' => [2], 'next_query' => 'broader query'],
        ['relevant' => [1], 'next_query' => null],
    ], $provider)->run(
        'question',
        [judgeStageDocument('A'), judgeStageDocument('B')],
        judgeStageRetrieval(['precise query' => [judgeStageDocument('C'), judgeStageDocument('D')]], $asked),
    );

    expect(judgeStageLabels($run->documents))->toBe(['A', 'D'])
        ->and($run->rounds)->toHaveCount(2)
        ->and($asked)->toBe(['precise query'])
        ->and($provider->getCallCount())->toBe(2);
});

it('stops at max_rounds', function (): void {
    config()->set('ai.features.rag.judge.max_rounds', 2);
    $asked = [];

    $run = judgeStageAnswering([
        ['relevant' => [1], 'next_query' => 'second'],
        ['relevant' => [1], 'next_query' => 'third'],
        ['relevant' => [1], 'next_query' => 'fourth'],
    ], $provider)->run(
        'question',
        [judgeStageDocument('A')],
        judgeStageRetrieval(['second' => [judgeStageDocument('B')], 'third' => [judgeStageDocument('C')]], $asked),
    );

    expect(judgeStageLabels($run->documents))->toBe(['A', 'B'])
        ->and($run->rounds)->toHaveCount(2)
        ->and($asked)->toBe(['second'])
        ->and($provider->getCallCount())->toBe(2);
});

it('a failure or timeout returns the unjudged candidates', function (Closure $stage): void {
    $candidates = [judgeStageDocument('A'), judgeStageDocument('B')];
    $asked = [];

    $run = $stage()->run('question', $candidates, judgeStageRetrieval(['next' => [judgeStageDocument('C')]], $asked));

    expect($run->documents)->toBe($candidates)
        ->and($run->fallback)->toBeTrue();
})->with([
    'the provider is down' => [static fn (): RelevanceJudgeStage => judgeStageAnswering([])],
    'a timeout' => [static fn (): RelevanceJudgeStage => new RelevanceJudgeStage(new RelevanceJudge(
        static fn (): ChatAgent => throw new HttpException('Network error during POST http://judge.test/chat: Operation timed out'),
    ))],
    'an empty verdict' => [static fn (): RelevanceJudgeStage => judgeStageAnswering([['relevant' => [], 'next_query' => null]])],
    'a failure after an accepted round' => [static fn (): RelevanceJudgeStage => judgeStageAnswering([['relevant' => [1], 'next_query' => 'next']])],
]);

it('a candidate is judged once', function (): void {
    $asked = [];

    $run = judgeStageAnswering([
        ['relevant' => [1], 'next_query' => 'next'],
        ['relevant' => [1], 'next_query' => null],
    ], $provider)->run(
        'question',
        [judgeStageDocument('Alpha')],
        judgeStageRetrieval(['next' => [judgeStageDocument('Alpha'), judgeStageDocument('Beta')]], $asked),
    );

    $second = (string) $provider->getRecorded()[1]->messages[0]->getContent();

    expect(judgeStageLabels($run->documents))->toBe(['Alpha', 'Beta'])
        ->and($second)->toContain('Beta')->not->toContain('Alpha')
        ->and($run->rounds[1]->candidates)->toBe(1);
});

it('each round is logged with query, candidates and kept ids', function (): void {
    config()->set('ai.features.faq.query_logging.enabled', true);
    config()->set('ai.features.faq.query_logging.query_text_mode', 'raw');
    config()->set('ai.features.rag.judge.min_sources', 2);
    Queue::fake([LogRagQueryJob::class]);
    app()->instance(RelevanceJudgeStage::class, judgeStageAnswering([
        ['relevant' => [2], 'next_query' => 'profile password'],
        ['relevant' => [1], 'next_query' => null],
    ]));

    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id, 'system_message' => null]);
    $request = Request::create('/app/ai/assistance', 'POST');
    $request->setUserResolver(static fn (): User => $user);
    $retrieved = ['How do I update my profile?' => [judgeStageDocument('Grids'), judgeStageDocument('Profile')], 'profile password' => [judgeStageDocument('Password')]];

    ScriptedAssistantFixtures::inAppService(
        $request,
        static fn (): string => 'Open Settings and select Profile.',
        static fn (string $query): array => $retrieved[$query] ?? [],
    )->respond($conversation, $user, 'How do I update my profile?');

    Queue::assertPushed(LogRagQueryJob::class, static function (LogRagQueryJob $job): bool {
        $document = $job->document;

        return $document['retrieved_count'] === 2
            && $document['citation_count'] === 2
            && $document['judge_rounds'] === 2
            && $document['judge_candidates'] === 3
            && $document['judge_kept'] === 2
            && $document['judge_fallback'] === false
            && is_float($document['judge_ms'])
            && $document['judge_queries'] === "How do I update my profile?\nprofile password"
            && preg_match('/^round=1 candidates=2 kept=2 ms=[\d.]+; round=2 candidates=1 kept=1 ms=[\d.]+$/', $document['judge_trace']) === 1
            && array_filter($document, is_array(...)) === [];
    });
});

it('logs no judge field while the judge is off', function (): void {
    config()->set('ai.features.rag.judge.enabled', false);
    config()->set('ai.features.faq.query_logging.enabled', true);
    Queue::fake([LogRagQueryJob::class]);

    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id, 'system_message' => null]);
    $request = Request::create('/app/ai/assistance', 'POST');
    $request->setUserResolver(static fn (): User => $user);

    ScriptedAssistantFixtures::inAppService(
        $request,
        static fn (): string => 'Open Settings and select Profile.',
        static fn (): array => [judgeStageDocument('Profile')],
    )->respond($conversation, $user, 'How do I update my profile?');

    Queue::assertPushed(LogRagQueryJob::class, static fn (LogRagQueryJob $job): bool => array_filter(
        array_keys($job->document),
        static fn (string $key): bool => str_starts_with($key, 'judge_'),
    ) === []);
});

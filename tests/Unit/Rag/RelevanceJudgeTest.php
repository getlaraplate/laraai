<?php

declare(strict_types=1);

use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Ai\Rag\Judge\JudgeAnswer;
use Modules\AI\Ai\Rag\Judge\RelevanceJudge;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\RAG\Document;
use NeuronAI\Testing\FakeAIProvider;

/**
 * A judge whose model is Neuron's fake provider, answering with the given replies in order; with
 * none, it fails like a provider that is down. What it was asked is read from $provider.
 *
 * @param  list<string>  $replies
 */
function relevanceJudgeAnswering(array $replies, ?FakeAIProvider &$provider = null): RelevanceJudge
{
    $provider = new FakeAIProvider(...array_map(static fn (string $reply): AssistantMessage => new AssistantMessage($reply), $replies));

    return new RelevanceJudge(static fn (string $system): ChatAgent => ChatAgent::make(systemPrompt: $system)->setAiProvider($provider));
}

function relevanceJudgeCandidate(string $label, string $content = 'Some documentation text.', array $metadata = []): Document
{
    return (new Document($content))->setSourceName($label)->setMetadata($metadata);
}

function relevanceJudgeAsked(FakeAIProvider $provider, int $call = 0): string
{
    return (string) $provider->getRecorded()[$call]->messages[0]->getContent();
}

it('keeps only the relevant ids, in candidate order, inside the candidate range', function (): void {
    $candidates = [
        relevanceJudgeCandidate('A'),
        relevanceJudgeCandidate('B'),
        relevanceJudgeCandidate('C'),
        relevanceJudgeCandidate('D'),
    ];

    $verdict = relevanceJudgeAnswering(['{"relevant":[3,"1",3,9,0,"x"],"next_query":null}'])
        ->judge('How do I export a grid?', $candidates, 1);

    expect($verdict->relevant)->toBe([1, 3])
        ->and($verdict->nextQuery)->toBeNull();
});

it('returns the next query trimmed, and none for a blank one', function (string $answer, ?string $expected): void {
    $verdict = relevanceJudgeAnswering([$answer])->judge('question', [relevanceJudgeCandidate('A')], 1);

    expect($verdict->nextQuery)->toBe($expected);
})->with([
    'a query' => ['{"relevant":[],"next_query":"  grid export csv  "}', 'grid export csv'],
    'blank' => ['{"relevant":[1],"next_query":"   "}', null],
    'absent' => ['{"relevant":[1]}', null],
]);

it('asks for a more precise query on the first round and a broader one with synonyms after', function (): void {
    $judge = relevanceJudgeAnswering(['{"relevant":[]}', '{"relevant":[]}'], $provider);

    $judge->judge('How do I export a grid?', [relevanceJudgeCandidate('A')], 1);
    $judge->judge('How do I export a grid?', [relevanceJudgeCandidate('B')], 2);

    expect(relevanceJudgeAsked($provider, 0))->toContain('more precise')->not->toContain('synonyms')
        ->and(relevanceJudgeAsked($provider, 1))->toContain('broader')->toContain('synonyms')
        ->and(relevanceJudgeAsked($provider, 0))->toContain('How do I export a grid?');
});

it('shows each candidate numbered with its title, date and an excerpt bounded by excerpt_chars', function (): void {
    config()->set('ai.features.rag.judge.excerpt_chars', 40);
    $long = str_repeat('alpha ', 20) . 'TAIL-MARKER';

    relevanceJudgeAnswering(['{"relevant":[]}'], $provider)->judge('question', [
        relevanceJudgeCandidate('Grid export', $long, ['heading_breadcrumb' => ['Core', 'Grids'], 'updated_at' => '2026-03-01']),
        relevanceJudgeCandidate('Settings'),
    ], 1);

    $asked = relevanceJudgeAsked($provider);

    expect($asked)->toContain('[1]')->toContain('Grid export')->toContain('Core > Grids')->toContain('2026-03-01')
        ->toContain('[2]')->toContain('Settings')
        ->not->toContain('TAIL-MARKER');
});

it('asks for the fixed schema of a verdict', function (): void {
    relevanceJudgeAnswering(['{"relevant":[]}'], $provider)->judge('question', [relevanceJudgeCandidate('A')], 1);

    $record = $provider->getRecorded()[0];

    expect($record->structuredClass)->toBe(JudgeAnswer::class)
        ->and(array_keys($record->structuredSchema['properties']))->toEqualCanonicalizing(['relevant', 'next_query']);
});

it('runs on its own model choice at temperature zero', function (): void {
    config()->set('ai.features.rag.judge.model', 'judge-provider:judge-model');

    $agent = (new ReflectionMethod(RelevanceJudge::class, 'createAgent'))->invoke(new RelevanceJudge, 'prompt');
    $read = static fn (string $property): mixed => (new ReflectionProperty($agent, $property))->getValue($agent);

    expect([$read('providerName'), $read('model'), $read('temperature')])->toBe(['judge-provider', 'judge-model', 0.0]);
});

it('throws when the model fails or does not answer with a verdict', function (array $replies): void {
    relevanceJudgeAnswering($replies)->judge('question', [relevanceJudgeCandidate('A')], 1);
})->with([
    'the provider is down' => [[]],
    'prose' => [['Candidate one looks relevant.']],
])->throws(RuntimeException::class);

it('asks nothing when there is nothing to judge', function (): void {
    $verdict = relevanceJudgeAnswering([], $provider)->judge('question', [], 1);

    expect($verdict->relevant)->toBe([])
        ->and($provider->getCallCount())->toBe(0);
});

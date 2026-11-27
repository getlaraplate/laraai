<?php

declare(strict_types=1);

use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Ai\Rag\Judge\RelevanceJudge;
use Modules\AI\Ai\Rag\Judge\RelevanceJudgePostProcessor;
use Modules\AI\Ai\Rag\Judge\RelevanceJudgeStage;
use Modules\AI\Ai\Rag\Pipeline\DocumentationPipeline;
use Modules\AI\Ai\Rag\Retrieval\ClosureRetrieval;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\FixedThresholdPostProcessor;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\Testing\FakeAIProvider;

function pipelineDocument(string $label, float $score = 0.9): Document
{
    return (new Document('About ' . $label . '.'))->setSourceName($label)->setScore($score);
}

function pipelineJudge(FakeAIProvider $provider): RelevanceJudgeStage
{
    return new RelevanceJudgeStage(new RelevanceJudge(
        static fn (string $system): ChatAgent => ChatAgent::make(systemPrompt: $system)->setAiProvider($provider),
    ));
}

it('asks the strategy with the question and hands its documents to the answer when nothing post-processes them', function (): void {
    $asked = [];
    $retrieval = new ClosureRetrieval(static function (string $query) use (&$asked): array {
        $asked[] = $query;

        return [pipelineDocument('guide')];
    });

    $result = new DocumentationPipeline($retrieval, [])->run('How do I publish?');

    expect($asked)->toBe(['How do I publish?'])
        ->and(array_map(static fn (Document $d): string => $d->getSourceName(), $result->documents))->toBe(['guide'])
        ->and($result->retrieved)->toBe(1)
        ->and($result->retrievalMs)->toBeGreaterThanOrEqual(0.0)
        ->and($result->judgeRun)->toBeNull();
});

it('refuses a filter, since the retrieval carries its own scope', function (): void {
    $retrieval = new ClosureRetrieval(static fn (string $query): array => []);

    $retrieval->retrieve(new UserMessage('q'), Filter::eq('sourceType', 'documentation'));
})->throws(InvalidArgumentException::class);

it('runs the relevance judge as a Neuron post-processor and reports its run', function (): void {
    config()->set('ai.features.rag.judge.enabled', true);
    config()->set('ai.features.rag.judge.min_sources', 1);
    $provider = new FakeAIProvider(new AssistantMessage(json_encode(['relevant' => [2], 'next_query' => null], JSON_THROW_ON_ERROR)));
    $retrieval = new ClosureRetrieval(static fn (string $query): array => [pipelineDocument('first'), pipelineDocument('second')]);

    $result = new DocumentationPipeline($retrieval, [new RelevanceJudgePostProcessor(pipelineJudge($provider), $retrieval)])->run('q');

    expect(array_map(static fn (Document $d): string => $d->getSourceName(), $result->documents))->toBe(['second'])
        ->and($result->retrieved)->toBe(2)
        ->and($result->judgeRun?->applied)->toBeTrue();
});

it('skips the judge when it is off, calling no model', function (): void {
    config()->set('ai.features.rag.judge.enabled', false);
    $provider = new FakeAIProvider;
    $retrieval = new ClosureRetrieval(static fn (string $query): array => [pipelineDocument('only')]);

    $result = new DocumentationPipeline($retrieval, [new RelevanceJudgePostProcessor(pipelineJudge($provider), $retrieval)])->run('q');

    expect($result->documents)->toHaveCount(1)
        ->and($result->judgeRun?->applied)->toBeFalse();
    $provider->assertCallCount(0);
});

it('shares the threshold every documentation path applies, and adds the judge only when asked', function (): void {
    config()->set('ai.features.faq.min_similarity', 0.5);
    $retrieval = new ClosureRetrieval(static fn (string $query): array => []);

    $unjudged = DocumentationPipeline::standardPostProcessors();
    $judged = DocumentationPipeline::standardPostProcessors($retrieval, withThreshold: false);

    expect($unjudged)->toHaveCount(1)
        ->and($unjudged[0])->toBeInstanceOf(FixedThresholdPostProcessor::class)
        ->and($judged)->toHaveCount(1)
        ->and($judged[0])->toBeInstanceOf(RelevanceJudgePostProcessor::class);
});

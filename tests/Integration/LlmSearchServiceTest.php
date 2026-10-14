<?php

declare(strict_types=1);

use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Data\Search\SearchIntentData;
use Modules\AI\Data\Search\SearchPlanData;
use Modules\AI\Services\LlmSearchService;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Testing\FakeAIProvider;

/**
 * A search service whose model is Neuron's fake provider, answering with the given replies in order;
 * with none, it fails like a provider that is down. What it was asked is read from $provider.
 *
 * @param  list<string>  $replies
 */
function llmSearchServiceAnswering(array $replies, ?FakeAIProvider &$provider = null): LlmSearchService
{
    $provider = new FakeAIProvider(...array_map(static fn (string $reply): AssistantMessage => new AssistantMessage($reply), $replies));

    return new LlmSearchService(chatAgentFactory: static fn (string $system): ChatAgent => ChatAgent::make(systemPrompt: $system)->setAiProvider($provider));
}

it('can be instantiated with explicit provider', function (): void {
    $service = new LlmSearchService('ollama');
    expect($service)->toBeInstanceOf(LlmSearchService::class);
});

it('plans a search from what the model answers, and asks for the schema of the plan', function (): void {
    $plan = llmSearchServiceAnswering([json_encode([
        'strategy' => 'vector',
        'retrieval' => ['use_fulltext' => false, 'use_vector' => true, 'use_ensemble' => false, 'size' => 80],
        'ensemble' => ['enabled' => false, 'keyword_weight' => 0.1, 'rrf_k' => 20],
        'ranking' => ['use_reranker' => false, 'rerank_top_k' => 10],
        'vector' => ['weight' => 0.6],
        'filters' => ['date_range' => ['from' => '2026-01-01', 'to' => '2026-03-31']],
        'retry_policy' => ['enabled' => false, 'max_attempts' => 3, 'threshold_avg_score' => 2.5],
    ], JSON_THROW_ON_ERROR)], $provider)->generateSearchPlan('climate change effects');

    $record = $provider->getRecorded()[0];

    expect($plan)->toBeInstanceOf(SearchPlanData::class)
        ->and($plan->strategy)->toBe('vector')
        ->and($plan->retrieval->use_fulltext)->toBeFalse()
        ->and($plan->retrieval->size)->toBe(80)
        ->and($plan->ensemble->keyword_weight)->toBe(0.1)
        ->and($plan->ensemble->rrf_k)->toBe(20)
        ->and($plan->ensemble->vector_weight)->toBe(0.35)
        ->and($plan->ranking->rerank_top_k)->toBe(10)
        ->and($plan->vector->weight)->toBe(0.6)
        ->and($plan->filters->date_range?->toArray())->toBe(['from' => '2026-01-01', 'to' => '2026-03-31'])
        ->and($plan->retry_policy->threshold_avg_score)->toBe(2.5)
        ->and($record->structuredClass)->toBe(SearchPlanData::class)
        ->and(json_encode($record->structuredSchema))->toContain('retry_policy')->toContain('rerank_top_k')
        ->and((string) $record->messages[0]->getContent())->toContain('climate change effects');
});

it('fills what a plan leaves out with the defaults of the section', function (): void {
    $plan = llmSearchServiceAnswering(['{"strategy":"fulltext","retrieval":{"size":20}}'])->generateSearchPlan('x');

    expect($plan?->retrieval->size)->toBe(20)
        ->and($plan->retrieval->use_vector)->toBeTrue()
        ->and($plan->ensemble->rrf_k)->toBe(60)
        ->and($plan->ranking->use_reranker)->toBeTrue()
        ->and($plan->filters->date_range)->toBeNull();
});

it('returns no plan when plan generation fails or the answer is not a plan', function (array $replies): void {
    expect(llmSearchServiceAnswering($replies, $provider)->generateSearchPlan('climate change effects'))->toBeNull();

    $provider->assertCallCount(count($replies));
})->with([
    'the provider is down' => [[]],
    'prose' => [['I would use a hybrid search.']],
]);

it('extracts the intent of a query from what the model answers', function (): void {
    $intent = llmSearchServiceAnswering([json_encode([
        'keywords' => ['climate', 'effects'],
        'filters' => ['date_range' => ['from' => '2025-01-01']],
        'query_expansion' => ['must' => 'effects of climate change on agriculture'],
    ], JSON_THROW_ON_ERROR)], $provider)->extractSearchIntent('climate change effects');

    expect($intent)->toBe([
        'keywords' => ['climate', 'effects'],
        'filters' => ['date_range' => ['from' => '2025-01-01']],
        'query_expansion' => ['must' => 'effects of climate change on agriculture'],
    ])->and($provider->getRecorded()[0]->structuredClass)->toBe(SearchIntentData::class);
});

it('keeps the raw query when the intent has no expansion, and no filter when it names no date', function (): void {
    $intent = llmSearchServiceAnswering(['{"keywords":["climate",""],"filters":{"date_range":null},"query_expansion":{"must":"  "}}'])->extractSearchIntent('climate change effects');

    expect($intent)->toBe([
        'keywords' => ['climate'],
        'filters' => [],
        'query_expansion' => ['must' => 'climate change effects'],
    ]);
});

it('degrades to the raw query when intent extraction fails', function (array $replies): void {
    $intent = llmSearchServiceAnswering($replies)->extractSearchIntent('climate change effects');

    expect($intent['keywords'])->toBe([])
        ->and($intent['filters'])->toBe([])
        ->and($intent['query_expansion']['must'])->toBe('climate change effects');
})->with([
    'the provider is down' => [[]],
    'prose' => [['Sorry, I cannot help with that.']],
]);

it('degrades to the raw query with a provider that is not supported', function (): void {
    $intent = new LlmSearchService('__unsupported_provider__')->extractSearchIntent('climate change effects');

    expect($intent['keywords'])->toBe([])
        ->and($intent['query_expansion']['must'])->toBe('climate change effects');
});

it('notes that the model was unavailable when the plan or the intent could not be asked for', function (): void {
    $degradations = app(Modules\Core\Search\Support\SearchDegradations::class);
    $degradations->reset();

    expect(llmSearchServiceAnswering([])->generateSearchPlan('anything'))->toBeNull()
        ->and($degradations->first())->toBe('llm_unavailable');

    $degradations->reset();
    llmSearchServiceAnswering([])->extractSearchIntent('anything');

    expect($degradations->first())->toBe('llm_unavailable');
});

<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Modules\AI\Services\LlmSearchService;
use Modules\AI\Services\SearchOrchestratorAgent;
use Modules\Core\Search\Contracts\ISearchPlanner;

beforeEach(function (): void {
    $container = Container::getInstance();

    if (! $container->bound('config')) {
        $container->singleton('config', fn (): Repository => new Repository([
            'core' => [
                'search' => [
                    'features' => ['reranker' => true],
                    'reranker' => ['top_k' => 30],
                    'vector' => ['enabled' => false],
                ],
            ],
        ]));
    }
});

it('implements ISearchPlanner contract', function (): void {
    $llm = Mockery::mock(LlmSearchService::class);
    expect(new SearchOrchestratorAgent($llm))->toBeInstanceOf(ISearchPlanner::class);
});

it('fallbackPlan returns valid structure', function (): void {
    config()->set('core.search.vector.enabled', true);

    $llm = Mockery::mock(LlmSearchService::class);
    $agent = new SearchOrchestratorAgent($llm);
    $plan = $agent->fallbackPlan('test');

    expect($plan)->toHaveKeys(['strategy', 'retrieval', 'ensemble', 'ranking', 'vector', 'filters', 'retry_policy', 'meta']);
    expect($plan['strategy'])->toBe('hybrid');
    expect($plan['meta']['source'])->toBe('fallback_rules');
});

it('disables vector when globally disabled', function (): void {
    config()->set('core.search.vector.enabled', false);

    $llm = Mockery::mock(LlmSearchService::class);
    $agent = new SearchOrchestratorAgent($llm);
    $plan = $agent->fallbackPlan('test query');

    expect($plan['retrieval']['use_vector'])->toBeFalse();
    expect($plan['vector']['enabled'])->toBeFalse();
    expect($plan['strategy'])->toBe('fulltext');
});

it('safePlan falls back on LLM exception', function (): void {
    $llm = Mockery::mock(LlmSearchService::class);
    $llm->shouldReceive('generateSearchPlan')->andThrow(new RuntimeException('LLM unavailable'));

    $agent = new SearchOrchestratorAgent($llm);
    $plan = $agent->safePlan('test query');

    expect($plan)->toHaveKeys(['strategy', 'retrieval', 'ensemble', 'ranking', 'vector', 'filters', 'retry_policy', 'meta']);
    expect($plan['meta']['source'])->toBe('fallback_rules');
});

/**
 * The orchestrator over a real LlmSearchService whose model answers with $reply (null: the provider is down).
 */
function orchestratorAnswering(?string $reply): SearchOrchestratorAgent
{
    $provider = new NeuronAI\Testing\FakeAIProvider(...($reply === null ? [] : [new NeuronAI\Chat\Messages\AssistantMessage($reply)]));

    return new SearchOrchestratorAgent(new LlmSearchService(
        chatAgentFactory: static fn (string $system): Modules\AI\Ai\Agents\ChatAgent => Modules\AI\Ai\Agents\ChatAgent::make(systemPrompt: $system)->setAiProvider($provider),
    ));
}

it('clamps the plan of the model to the ranges the search accepts', function (): void {
    config()->set('core.search.vector.enabled', true);
    Illuminate\Support\Facades\Cache::flush();

    $plan = orchestratorAnswering(json_encode([
        'strategy' => 'hybrid',
        'retrieval' => ['size' => 5000],
        'ensemble' => ['keyword_weight' => 3.0, 'vector_weight' => -1.0, 'rrf_k' => 1],
        'ranking' => ['rerank_top_k' => 1],
        'retry_policy' => ['max_attempts' => 9, 'threshold_avg_score' => 0.0],
    ], JSON_THROW_ON_ERROR))->plan('a query that is clamped');

    expect($plan['retrieval']['size'])->toBe(200)
        ->and($plan['ensemble']['keyword_weight'])->toBe(1.0)
        ->and($plan['ensemble']['vector_weight'])->toBe(0.0)
        ->and($plan['ensemble']['rrf_k'])->toBe(10)
        ->and($plan['ranking']['rerank_top_k'])->toBe(5)
        ->and($plan['retry_policy']['max_attempts'])->toBe(3)
        ->and($plan['retry_policy']['threshold_avg_score'])->toBe(0.1)
        ->and($plan['meta']['source'])->toBe('llm+guardrails');
});

it('runs no vector search when it is disabled for the application, whatever the model plans', function (): void {
    config()->set('core.search.vector.enabled', false);
    Illuminate\Support\Facades\Cache::flush();

    $plan = orchestratorAnswering('{"strategy":"vector","retrieval":{"use_vector":true},"ensemble":{"vector_weight":0.9,"hybrid_weight":0.9}}')->plan('a query without vectors');

    expect($plan['strategy'])->toBe('fulltext')
        ->and($plan['retrieval']['use_vector'])->toBeFalse()
        ->and($plan['ensemble']['vector_weight'])->toBe(0.0)
        ->and($plan['ensemble']['hybrid_weight'])->toBe(0.0)
        ->and($plan['vector']['enabled'])->toBeFalse();
});

it('uses the rule-based plan when the model gives none, and does not claim it came from the model', function (): void {
    Illuminate\Support\Facades\Cache::flush();

    expect(orchestratorAnswering(null)->safePlan('a query with no model')['meta']['source'])->toBe('fallback_rules');
});

it('caches the plan of a query under a stable key', function (): void {
    Illuminate\Support\Facades\Cache::flush();

    orchestratorAnswering('{"strategy":"fulltext"}')->plan('cached query');

    expect(Illuminate\Support\Facades\Cache::has('search_orchestrator:' . md5('cached query')))->toBeTrue();
});

it('notes the unavailable model each time the plan is the rule-based fallback, cached or not', function (): void {
    $degradations = app(Modules\Core\Search\Support\SearchDegradations::class);
    $llm = Mockery::mock(LlmSearchService::class);
    $llm->shouldReceive('generateSearchPlan')->once()->andReturnNull();
    $agent = new SearchOrchestratorAgent($llm);
    $query = 'degradation note ' . uniqid();

    $degradations->reset();
    $agent->safePlan($query);

    expect($degradations->first())->toBe('llm_unavailable');

    $degradations->reset();
    $plan = $agent->safePlan($query);

    expect($plan['meta']['source'])->toBe('fallback_rules')
        ->and($degradations->first())->toBe('llm_unavailable');
});

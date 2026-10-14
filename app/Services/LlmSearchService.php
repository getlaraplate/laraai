<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Closure;
use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Data\Search\SearchIntentData;
use Modules\AI\Data\Search\SearchPlanData;
use Modules\AI\Enums\AiModelFeature;
use Modules\Core\Search\Support\SearchDegradations;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;

/**
 * Search-specific LLM service wrapping NeuronAI via ChatAgent.
 *
 * Plans a search and extracts the intent of a query for the search orchestration pipeline. The model
 * answers through Neuron's structured output ({@see SearchPlanData}, {@see SearchIntentData}); both
 * calls sit on the path of a search, so neither asks the model again, and a failure of either leaves
 * the caller to the rule-based search.
 */
class LlmSearchService
{
    /**
     * Times Neuron asks the model again when the answer does not fit the schema: none, because a
     * search waits for it.
     */
    public const int MAX_RETRIES = 0;

    /**
     * @param  (Closure(string): ChatAgent)|null  $chatAgentFactory  builds the agent from its system prompt
     */
    public function __construct(
        private readonly ?string $provider = null,
        private readonly ?Closure $chatAgentFactory = null,
    ) {}

    /**
     * Generate a structured search plan from an LLM.
     *
     * @return SearchPlanData|null null when the call fails or the answer does not fit the plan
     */
    public function generateSearchPlan(string $query): ?SearchPlanData
    {
        try {
            $output = $this->createAgent($this->getSearchPlanSystemPrompt())->structured(
                new UserMessage("Generate a search plan for: {$query}"),
                SearchPlanData::class,
                self::MAX_RETRIES,
            );

            return $output instanceof SearchPlanData ? $output : null;
        } catch (Throwable $exception) {
            // Any LLM/agent failure must not break search: no plan, so the caller falls back to the
            // rule-based planner.
            Log::warning('LLM search plan generation failed; using rule-based fallback', [
                'error' => $exception->getMessage(),
            ]);
            app(SearchDegradations::class)->note(SearchDegradations::LLM_UNAVAILABLE);

            return null;
        }
    }

    /**
     * Extract search intent (keywords, filters, expanded query) from a user query.
     *
     * @return array{keywords: list<string>, filters: array<string, mixed>, query_expansion: array{must: string}}
     */
    public function extractSearchIntent(string $query): array
    {
        $intent = null;

        try {
            $output = $this->createAgent($this->getIntentExtractionSystemPrompt())->structured(
                new UserMessage($query),
                SearchIntentData::class,
                self::MAX_RETRIES,
            );
            $intent = $output instanceof SearchIntentData ? $output : null;
        } catch (Throwable $exception) {
            // Degrade gracefully: an LLM/agent failure falls back to the raw
            // query (no expansion, no keywords) instead of breaking retrieval.
            Log::warning('LLM intent extraction failed; falling back to the raw query', [
                'error' => $exception->getMessage(),
            ]);
            app(SearchDegradations::class)->note(SearchDegradations::LLM_UNAVAILABLE);
        }

        $date_range = $intent?->filters->date_range?->toArray();
        $expanded = mb_trim($intent->query_expansion->must ?? '');

        return [
            'keywords' => array_values(array_filter(
                $intent->keywords ?? [],
                static fn (mixed $keyword): bool => is_string($keyword) && $keyword !== '',
            )),
            'filters' => $date_range === null ? [] : ['date_range' => $date_range],
            'query_expansion' => [
                'must' => $expanded !== '' ? $expanded : $query,
            ],
        ];
    }

    private function createAgent(string $system_prompt): ChatAgent
    {
        if ($this->chatAgentFactory instanceof Closure) {
            return ($this->chatAgentFactory)($system_prompt);
        }

        $agent = $this->provider !== null
            ? ChatAgent::make($this->provider, $system_prompt)
            : ChatAgent::forFeature(AiModelFeature::SearchOrchestration, $system_prompt);

        return $agent->withTimeout((float) config('ai.features.search_orchestration.timeout', 10));
    }

    private function getSearchPlanSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a search orchestration system. Given a user query, produce a structured search plan.

You must decide:
- retrieval strategy (fulltext, vector, hybrid)
- ranking strategy (reranker yes/no, top_k)
- retry policy (max_attempts, threshold)
- filters (date_range if applicable)
- ensemble weights (keyword_weight, vector_weight, hybrid_weight)

An example of a plan:
{
  "strategy": "hybrid",
  "retrieval": {"use_fulltext": true, "use_vector": true, "use_ensemble": true, "size": 50},
  "ensemble": {"enabled": true, "keyword_weight": 0.35, "vector_weight": 0.35, "hybrid_weight": 0.30, "agreement_boost": 0.15, "rrf_k": 60, "rrf_weight": 0.25},
  "ranking": {"use_reranker": true, "rerank_top_k": 30},
  "vector": {"enabled": true, "weight": 0.2},
  "filters": {"date_range": null},
  "retry_policy": {"enabled": true, "max_attempts": 2, "threshold_avg_score": 1.5}
}
PROMPT;
    }

    private function getIntentExtractionSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a search intent extraction system. Given a user query, extract structured information.

An example of the information:
{
  "keywords": ["keyword1", "keyword2"],
  "filters": {"date_range": null},
  "query_expansion": {"must": "expanded query text"}
}

Rules:
- keywords: the most important search terms
- query_expansion.must: an expanded/reformulated version of the query for better search results
- filters.date_range: null unless the query explicitly mentions dates
PROMPT;
    }
}

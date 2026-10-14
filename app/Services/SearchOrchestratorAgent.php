<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Illuminate\Support\Facades\Cache;
use Modules\AI\Data\Search\SearchPlanData;
use Modules\Core\Search\Contracts\ISearchPlanner;
use Modules\Core\Search\Support\SearchDegradations;
use Throwable;

/**
 * AI-powered search orchestrator that generates execution plans via LLM.
 *
 * Falls back to rule-based heuristics when the LLM call fails
 * or returns an invalid plan.
 */
final readonly class SearchOrchestratorAgent implements ISearchPlanner
{
    public function __construct(
        private LlmSearchService $llm,
    ) {}

    /**
     * Generate a cached search plan via LLM with guardrails.
     *
     * @return array<string, mixed>
     */
    public function plan(string $query): array
    {
        $cache_key = $this->cacheKey($query);

        return Cache::remember($cache_key, 600, function () use ($query): array {
            $raw = $this->llm->generateSearchPlan($query);

            return $raw === null ? $this->fallbackPlan($query) : $this->sanitizePlan($raw, $query);
        });
    }

    public function safePlan(string $query): array
    {
        try {
            $plan = $this->plan($query);

            if (! $this->isValidPlan($plan)) {
                app(SearchDegradations::class)->note(SearchDegradations::LLM_UNAVAILABLE);

                return $this->fallbackPlan($query);
            }

            // A fallback plan is cached like any other for ten minutes, so a repeat of the query must still say
            // that the model did not make this plan.
            if (($plan['meta']['source'] ?? null) === 'fallback_rules') {
                app(SearchDegradations::class)->note(SearchDegradations::LLM_UNAVAILABLE);
            }

            return $plan;
        } catch (Throwable) {
            app(SearchDegradations::class)->note(SearchDegradations::LLM_UNAVAILABLE);

            return $this->fallbackPlan($query);
        }
    }

    public function fallbackPlan(string $query): array
    {
        $is_short = mb_strlen($query) < 20;
        $has_numbers = (bool) preg_match('/\d/', $query);
        $vector_globally_enabled = (bool) config('core.search.vector.enabled', false);
        $use_vector = $vector_globally_enabled && ! $has_numbers;

        $use_reranker = (bool) config('core.search.reranker.enabled');
        $rerank_top_k = $this->intValue(config('core.search.reranker.top_k'), 30);

        return [
            'strategy' => $use_vector ? 'hybrid' : 'fulltext',
            'retrieval' => [
                'use_fulltext' => true,
                'use_vector' => $use_vector,
                'use_ensemble' => true,
                'size' => $is_short ? 80 : 50,
            ],
            'ensemble' => [
                'enabled' => true,
                'keyword_weight' => $use_vector ? ($is_short ? 0.30 : 0.35) : 1.0,
                'vector_weight' => $use_vector ? ($is_short ? 0.40 : 0.35) : 0.0,
                'hybrid_weight' => $use_vector ? ($is_short ? 0.30 : 0.30) : 0.0,
                'agreement_boost' => 0.15,
                'rrf_k' => 60,
                'rrf_weight' => 0.25,
            ],
            'ranking' => [
                'use_reranker' => $use_reranker,
                'rerank_top_k' => $rerank_top_k,
            ],
            'vector' => [
                'enabled' => $use_vector,
                'weight' => $is_short ? 0.4 : 0.2,
            ],
            'filters' => [
                'date_range' => null,
            ],
            'retry_policy' => [
                'enabled' => true,
                'max_attempts' => 2,
                'threshold_avg_score' => 1.5,
            ],
            'meta' => [
                'source' => 'fallback_rules',
            ],
        ];
    }

    /**
     * Clamp the plan the model returned into a safe search plan. The types are Neuron's; what is left
     * here is the domain defence: the ranges the search accepts, and the vector search that only runs
     * when it is enabled for the whole application.
     *
     * @return array<string, mixed>
     */
    private function sanitizePlan(SearchPlanData $plan, string $query): array
    {
        $use_vector = (bool) config('core.search.vector.enabled', false) && $plan->retrieval->use_vector;

        return [
            'strategy' => $use_vector ? $this->sanitizeStrategy($plan->strategy) : 'fulltext',

            'retrieval' => [
                'use_fulltext' => $plan->retrieval->use_fulltext,
                'use_vector' => $use_vector,
                'use_ensemble' => $plan->retrieval->use_ensemble,
                'size' => $this->clamp($plan->retrieval->size, 10, 200),
            ],

            'ensemble' => [
                'enabled' => $plan->ensemble->enabled,
                'keyword_weight' => $this->clampFloat($plan->ensemble->keyword_weight, 0.0, 1.0),
                'vector_weight' => $use_vector ? $this->clampFloat($plan->ensemble->vector_weight, 0.0, 1.0) : 0.0,
                'hybrid_weight' => $use_vector ? $this->clampFloat($plan->ensemble->hybrid_weight, 0.0, 1.0) : 0.0,
                'agreement_boost' => $this->clampFloat($plan->ensemble->agreement_boost, 0.0, 1.0),
                'rrf_k' => $this->clamp($plan->ensemble->rrf_k, 10, 200),
                'rrf_weight' => $this->clampFloat($plan->ensemble->rrf_weight, 0.0, 1.0),
            ],

            'ranking' => [
                'use_reranker' => $plan->ranking->use_reranker,
                'rerank_top_k' => $this->clamp($plan->ranking->rerank_top_k, 5, 100),
            ],

            'vector' => [
                'enabled' => $use_vector,
                'weight' => $this->clampFloat($plan->vector->weight, 0.0, 1.0),
            ],

            'filters' => [
                'date_range' => $plan->filters->date_range?->toArray(),
            ],

            'retry_policy' => [
                'enabled' => $plan->retry_policy->enabled,
                'max_attempts' => $this->clamp($plan->retry_policy->max_attempts, 1, 3),
                'threshold_avg_score' => $this->clampFloat($plan->retry_policy->threshold_avg_score, 0.1, 10.0),
            ],

            'meta' => [
                'query' => $query,
                'source' => 'llm+guardrails',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function isValidPlan(array $plan): bool
    {
        return isset($plan['retrieval'])
            && isset($plan['ranking'])
            && isset($plan['vector'])
            && isset($plan['ensemble']);
    }

    private function sanitizeStrategy(string $strategy): string
    {
        if (in_array($strategy, ['hybrid', 'fulltext', 'vector'], true)) {
            return $strategy;
        }

        return 'hybrid';
    }

    private function intValue(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    private function clampFloat(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    private function cacheKey(string $query): string
    {
        return 'search_orchestrator:' . md5($query);
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Services\ApplicationContent\Evaluation;

use Closure;
use InvalidArgumentException;
use Modules\AI\Services\Evaluation\EvaluationStatistics;
use Modules\Core\ApplicationContent\Data\ApplicationContentSourceDescriptor;
use Modules\Core\Search\DTOs\AdvancedSearchResult;

/**
 * Evaluates retrieval ranking quality per strategy (keyword, vector, hybrid,
 * fused, reranked) against a dataset of ground-truth cases.
 *
 * Reuses {@see IrMetrics::atK()} for the precision/recall/nDCG math and the
 * per-case denominator convention from {@see ApplicationContentEvaluationService}
 * (sum contributions across cases, divide by the count of cases that carry
 * ground truth).
 *
 * @phpstan-type StrategyRecord array{case: ApplicationContentEvaluationCase, orderings: array<'keyword'|'vector'|'hybrid'|'fused'|'reranked', list<string>>, executed: array<'keyword'|'vector'|'hybrid', bool>, reranker_ran: bool, reranker_model: string|null, latency_ms: float}
 */
final readonly class ApplicationContentRetrievalStrategyEvaluationService
{
    /**
     * @var list<int>
     */
    private const array CUTOFFS = [1, 3, 5];

    /**
     * Strategies backed by {@see AdvancedSearchResult::$meta}'s `per_strategy` map.
     * Only strategies that actually ran for at least one scored case are reported.
     *
     * @var list<string>
     */
    private const array RANKED_STRATEGIES = ['keyword', 'vector', 'hybrid'];

    /**
     * Reporting order: ranked strategies first, then the two final orderings
     * (fused is always the pre-rerank result of `ids()`, reranked the post-rerank one).
     *
     * @var list<string>
     */
    private const array STRATEGIES = ['keyword', 'vector', 'hybrid', 'fused', 'reranked'];

    private Closure $clock;

    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
    }

    /**
     * @param  callable(ApplicationContentEvaluationCase, bool): AdvancedSearchResult  $retrieval  Invoked once with
     *                                                                                             `useReranker = false` (to read the per-strategy breakdown and the fused ordering) and once with
     *                                                                                             `useReranker = true` (to read the reranked ordering), for every case whose `expectedHitIds` is
     *                                                                                             non-empty. Cases without expected hit ids carry no ground truth for ranking quality and are
     *                                                                                             skipped entirely: no retrieval call, no latency sample, no metric contribution.
     * @return array<string, mixed>
     */
    public function evaluate(
        ApplicationContentEvaluationDataset $dataset,
        string $source,
        string $driver,
        callable $retrieval,
    ): array {
        $source = ApplicationContentSourceDescriptor::normalizeSource($source);

        if ($dataset->source !== $source || mb_trim($driver) === '' || mb_strlen($driver) > 100) {
            throw new InvalidArgumentException('Evaluation driver is invalid.');
        }

        $this->assertDatasetSource($dataset, $source);

        $records = [];

        foreach ($dataset->cases as $case) {
            if ($case->expectedHitIds === []) {
                continue;
            }

            $started_at = ($this->clock)();
            $off = $retrieval($case, false);
            $on = $retrieval($case, true);
            $elapsed = max(0.0, (($this->clock)() - $started_at) * 1000);

            $records[] = [
                'case' => $case,
                'orderings' => array_map(
                    fn (array $ids): array => $this->namespaceIds($ids, $source),
                    [
                        'keyword' => $this->rankedStrategyIds($off, 'keyword'),
                        'vector' => $this->rankedStrategyIds($off, 'vector'),
                        'hybrid' => $this->rankedStrategyIds($off, 'hybrid'),
                        'fused' => $off->ids(),
                        'reranked' => $on->ids(),
                    ],
                ),
                'executed' => [
                    'keyword' => $this->strategyPresent($off, 'keyword'),
                    'vector' => $this->strategyPresent($off, 'vector'),
                    'hybrid' => $this->strategyPresent($off, 'hybrid'),
                ],
                'reranker_ran' => RerankerRun::ranIn($on),
                'reranker_model' => RerankerRun::modelIn($on),
                'latency_ms' => $elapsed,
            ];
        }

        return [
            'schema_version' => '1',
            'source' => $source,
            'driver' => mb_trim($driver),
            'dataset_version' => $dataset->version,
            'provider_version' => $dataset->providerVersion,
            'corpus_revision' => $dataset->corpusRevision,
            'data_classification' => $dataset->dataClassification,
            'case_count' => count($records),
            'pre_authorization' => true,
            'reranker' => RerankerRun::summary(
                count($records),
                count(array_filter($records, static fn (array $record): bool => $record['reranker_ran'])),
                RerankerRun::modelOf(array_column(array_filter($records, static fn (array $record): bool => $record['reranker_ran']), 'reranker_model')),
            ),
            'metrics' => $this->metrics($records),
            'latency_ms' => EvaluationStatistics::latency($records),
            'slices' => EvaluationStatistics::groupBySlices($records, $this->metrics(...)),
        ];
    }

    private function assertDatasetSource(ApplicationContentEvaluationDataset $dataset, string $source): void
    {
        foreach ($dataset->cases as $case) {
            foreach ($case->expectedHitIds as $id) {
                if (! str_starts_with($id, $source . ':')) {
                    throw new InvalidArgumentException('Evaluation case source does not match the requested source.');
                }
            }
        }
    }

    /**
     * Namespaces bare engine ids (`EnsembleSearchService` emits `(string) $model->getKey()`,
     * e.g. `"2"`) into the same `"{source}:{key}"` format as `expectedHitIds`, so the two
     * are comparable. Applied exactly once, centrally, right where the five orderings are
     * extracted, so the strategy-id lists downstream are always already namespaced.
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function namespaceIds(array $ids, string $source): array
    {
        return array_map(static fn (string $id): string => "{$source}:{$id}", $ids);
    }

    /**
     * Rank-ordered ids for one keyword/vector/hybrid strategy, read from the
     * ensemble's per-strategy map. The map is insertion-ordered by rank, but
     * this re-sorts by the explicit `rank` field to not depend on that.
     *
     * @return list<string>
     */
    private function rankedStrategyIds(AdvancedSearchResult $result, string $name): array
    {
        $hits = $result->meta['per_strategy'][$name] ?? null;

        if (! is_array($hits)) {
            return [];
        }

        $ranked = array_values($hits);
        usort($ranked, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        return array_map(static fn (array $hit): string => $hit['id'], $ranked);
    }

    /**
     * Whether a keyword/vector/hybrid strategy actually executed for this
     * result, i.e. it has a key in `meta['per_strategy']` (even if it
     * returned zero hits). Used to decide whether to report the strategy
     * at all: a strategy never executed for any scored case is omitted
     * from the report instead of being padded with zero metrics.
     */
    private function strategyPresent(AdvancedSearchResult $result, string $name): bool
    {
        $per_strategy = $result->meta['per_strategy'] ?? null;

        return is_array($per_strategy) && array_key_exists($name, $per_strategy);
    }

    /**
     * @param  list<StrategyRecord>  $records
     * @return array<string, array<string, float>>
     */
    private function metrics(array $records): array
    {
        $metrics = [];

        foreach (self::STRATEGIES as $name) {
            if (in_array($name, self::RANKED_STRATEGIES, true) && ! $this->strategyExecuted($records, $name)) {
                continue;
            }

            $metrics[$name] = $this->strategyMetrics($records, $name);
        }

        return $metrics;
    }

    /**
     * @param  list<StrategyRecord>  $records
     */
    private function strategyExecuted(array $records, string $name): bool
    {
        foreach ($records as $record) {
            if ($record['executed'][$name]) {
                return true;
            }
        }

        return false;
    }

    /**
     * The denominator for a strategy's averaged metrics: for `fused`/`reranked`
     * (always present, sourced straight from `AdvancedSearchResult::ids()`) this
     * is every scored case; for keyword/vector/hybrid it is only the scored
     * cases where the strategy actually executed (had a key in
     * `meta['per_strategy']`), so a strategy that ran on a subset of cases
     * (e.g. vector skipped for cases without an embedding) is not diluted by
     * the cases where it did not run.
     *
     * @param  list<StrategyRecord>  $records
     */
    private function strategyCaseCount(array $records, string $name): int
    {
        if (! in_array($name, self::RANKED_STRATEGIES, true)) {
            return count($records);
        }

        $count = 0;

        foreach ($records as $record) {
            if ($record['executed'][$name]) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<StrategyRecord>  $records
     * @return array<string, float>
     */
    private function strategyMetrics(array $records, string $name): array
    {
        $relevant_cases = $this->strategyCaseCount($records, $name);
        $hits_at_k = 0;
        $reciprocal_rank = 0.0;
        $precision_sum = array_fill_keys(self::CUTOFFS, 0.0);
        $recall_sum = array_fill_keys(self::CUTOFFS, 0.0);
        $ndcg_sum = array_fill_keys(self::CUTOFFS, 0.0);

        foreach ($records as $record) {
            if (in_array($name, self::RANKED_STRATEGIES, true) && ! $record['executed'][$name]) {
                continue;
            }

            $case = $record['case'];
            $ids = $record['orderings'][$name];
            $expected = $case->expectedHitIds;
            $first_rank = IrMetrics::firstRank($ids, $expected);
            $hits_at_k += (int) ($first_rank !== null);
            $reciprocal_rank += IrMetrics::reciprocalRank($first_rank);

            $contributions = IrMetrics::atK($ids, $expected, self::CUTOFFS);

            foreach (self::CUTOFFS as $k) {
                $precision_sum[$k] += $contributions['precision'][$k];
                $recall_sum[$k] += $contributions['recall'][$k];
                $ndcg_sum[$k] += $contributions['ndcg'][$k];
            }
        }

        $precision = [];
        $recall = [];
        $ndcg = [];

        foreach (self::CUTOFFS as $k) {
            $precision["precision_at_{$k}"] = EvaluationStatistics::ratio($precision_sum[$k], $relevant_cases);
            $recall["recall_at_{$k}"] = EvaluationStatistics::ratio($recall_sum[$k], $relevant_cases);
            $ndcg["ndcg_at_{$k}"] = EvaluationStatistics::ratio($ndcg_sum[$k], $relevant_cases);
        }

        return [
            'hit_at_5' => EvaluationStatistics::ratio($hits_at_k, $relevant_cases),
            'mean_reciprocal_rank' => EvaluationStatistics::ratio($reciprocal_rank, $relevant_cases),
            ...$precision,
            ...$recall,
            ...$ndcg,
        ];
    }
}

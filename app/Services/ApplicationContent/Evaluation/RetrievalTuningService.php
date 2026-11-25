<?php

declare(strict_types=1);

namespace Modules\AI\Services\ApplicationContent\Evaluation;

use InvalidArgumentException;
use Modules\AI\Services\Evaluation\EvaluationStatistics;
use Modules\Core\ApplicationContent\Data\ApplicationContentSourceDescriptor;
use Modules\Core\Search\DTOs\AdvancedSearchResult;
use Modules\Core\Search\Enums\QueryClass;
use Modules\Core\Search\Services\FallbackSearchPlanner;
use Modules\Core\Search\Services\RankFusion;
use Modules\Core\Search\Services\RetrievalTuningProfile;
use Modules\Core\Search\Services\TextMatchOptionsResolver;

/**
 * Offline grid search over the fusion parameters of the retrieval tuning profile.
 *
 * Each ground-truth case is retrieved once with the reranker off, to record its per-strategy
 * rankings (`meta['per_strategy']`), and once with it on, to score the reranked ordering. Every
 * grid candidate then re-fuses the recorded rankings through {@see RankFusion}: the per-strategy
 * orderings do not depend on fusion parameters, so the grid never re-queries the engine. The
 * rerank blend cannot be replayed from fused scores, so the reranked ordering is reported as it
 * ran, next to the candidates.
 *
 * A candidate is a partial parameter set: what it leaves out keeps, per case, the value the L0
 * planner emits for that query. The committed baseline is the same merge with the committed
 * profile's parameters for the case's {@see QueryClass}.
 *
 * It returns a report and writes nothing.
 *
 * @phpstan-import-type StrategyHit from RankFusion
 *
 * @phpstan-type CaseRecord array{case: ApplicationContentEvaluationCase, class: QueryClass, per_strategy: array<string, array<string, StrategyHit>>, baseline: array<string, int|float>, planner: array<string, int|float>, reranked: list<string>, reranker_ran: bool, reranker_model: string|null}
 * @phpstan-type Scored array{metrics: array<string, float>, per_class_metrics: array<string, array<string, float>>}
 * @phpstan-type Candidate array{params: array<string, int|float>, metrics: array<string, float>, per_class_metrics: array<string, array<string, float>>, delta_vs_committed: array<string, float>}
 */
final readonly class RetrievalTuningService
{
    /**
     * @var list<int>
     */
    private const array CUTOFFS = [1, 3, 5];

    /**
     * @var list<string>
     */
    private const array FUSION_PARAMETERS = ['keyword_weight', 'vector_weight', 'hybrid_weight', 'rrf_k', 'rrf_weight', 'agreement_boost'];

    /**
     * @var list<string>
     */
    private const array WEIGHT_SETS = ['0.35,0.35,0.30', '0.30,0.40,0.30', '0.50,0.20,0.30', '0.25,0.45,0.30', '0.60,0.10,0.30', '0.20,0.30,0.50'];

    public function __construct(
        private TextMatchOptionsResolver $text_match,
        private FallbackSearchPlanner $planner,
        private RetrievalTuningProfile $profile,
    ) {}

    /**
     * @return list<string>
     */
    public static function metricNames(): array
    {
        $names = ['hit_at_5', 'mean_reciprocal_rank'];

        foreach (['precision', 'recall', 'ndcg'] as $family) {
            foreach (self::CUTOFFS as $k) {
                $names[] = "{$family}_at_{$k}";
            }
        }

        return $names;
    }

    /**
     * The named built-in grid: six weight triples crossed with `rrf_k`, `rrf_weight` and
     * `agreement_boost` (108 candidates, the first being the L0 constants).
     *
     * @return list<array<string, int|float>>
     */
    public static function namedGrid(string $name): array
    {
        if ($name !== 'default') {
            throw new InvalidArgumentException('Unknown tuning grid.');
        }

        $grid = [];

        foreach (self::WEIGHT_SETS as $weights) {
            [$keyword, $vector, $hybrid] = array_map(floatval(...), explode(',', $weights));

            foreach ([60, 20] as $rrf_k) {
                foreach ([0.25, 0.0, 0.5] as $rrf_weight) {
                    foreach ([0.15, 0.0, 0.3] as $agreement_boost) {
                        $grid[] = [
                            'keyword_weight' => $keyword,
                            'vector_weight' => $vector,
                            'hybrid_weight' => $hybrid,
                            'rrf_k' => $rrf_k,
                            'rrf_weight' => $rrf_weight,
                            'agreement_boost' => $agreement_boost,
                        ];
                    }
                }
            }
        }

        return $grid;
    }

    /**
     * @param  list<array<string, int|float>>  $grid
     * @param  callable(ApplicationContentEvaluationCase, bool): AdvancedSearchResult  $retrieval  Called once with
     *                                                                                             `useReranker = false` and once with `useReranker = true` per case carrying expected hit ids.
     * @return array<string, mixed>
     */
    public function tune(
        ApplicationContentEvaluationDataset $dataset,
        string $source,
        array $grid,
        string $metric,
        callable $retrieval,
        ?RetrievalTuningSafeguards $safeguards = null,
    ): array {
        $safeguards ??= new RetrievalTuningSafeguards();
        $source = ApplicationContentSourceDescriptor::normalizeSource($source);

        if ($dataset->source !== $source) {
            throw new InvalidArgumentException('Tuning dataset source does not match the requested source.');
        }

        if (! in_array($metric, self::metricNames(), true)) {
            throw new InvalidArgumentException('Unknown tuning metric.');
        }

        $this->assertGrid($grid);

        $records = [];

        foreach ($dataset->cases as $case) {
            if ($case->expectedHitIds === []) {
                continue;
            }

            $off = $retrieval($case, false);
            $on = $retrieval($case, true);
            $class = QueryClass::fromAnalysis($this->text_match->resolve($case->query)->analysis);
            $planner_parameters = $this->plannerParameters($case->query);

            $records[] = [
                'case' => $case,
                'class' => $class,
                'per_strategy' => $this->perStrategy($off),
                'baseline' => [
                    ...$planner_parameters,
                    ...array_intersect_key($this->profile->parametersFor($class), array_flip(self::FUSION_PARAMETERS)),
                ],
                'planner' => $planner_parameters,
                'reranked' => $this->namespaced($on->ids(), $source),
                'reranker_ran' => RerankerRun::ranIn($on),
                'reranker_model' => RerankerRun::modelIn($on),
            ];
        }

        [$selection, $held_out, $split_reason] = $this->split($records, $safeguards->holdoutFraction);
        $committed = $this->score($selection, $source, null);
        $candidates = [];

        foreach ($grid as $parameters) {
            $scored = $this->score($selection, $source, $parameters);
            $candidates[] = [
                'params' => $parameters,
                ...$scored,
                'delta_vs_committed' => $this->delta($scored['metrics'], $committed['metrics']),
            ];
        }

        usort($candidates, static fn (array $a, array $b): int => $b['metrics'][$metric] <=> $a['metrics'][$metric]);

        $validation = $this->validate($held_out, $source, $metric, $candidates[0]['params'], $safeguards, $split_reason);
        $noise = $this->noise($candidates[0], $committed, $metric, count($selection), $safeguards);
        $rejected = $validation['status'] === 'failed' || $noise['status'] === 'within_noise';
        $class_overrides = $rejected
            ? ['winners' => [], 'skipped' => []]
            : $this->classWinners($candidates, $metric, $this->classCounts($selection), $safeguards);

        return [
            'version' => '1',
            'source' => $source,
            'dataset' => [
                'version' => $dataset->version,
                'provider_version' => $dataset->providerVersion,
                'corpus_revision' => $dataset->corpusRevision,
            ],
            'metric' => $metric,
            'case_count' => count($records),
            'class_counts' => $this->classCounts($records),
            'train_case_count' => count($selection),
            'holdout_case_count' => count($held_out),
            'train_class_counts' => $this->classCounts($selection),
            'safeguards' => $safeguards->toArray(),
            'committed' => [
                'profile_version' => $this->profile->profile()['version'] ?? null,
                ...$committed,
            ],
            'reranker' => RerankerRun::summary(
                count($records),
                count(array_filter($records, static fn (array $record): bool => $record['reranker_ran'])),
                RerankerRun::modelOf(array_column(array_filter($records, static fn (array $record): bool => $record['reranker_ran']), 'reranker_model')),
            ),
            'reranked' => $this->rerankedMetrics($records),
            'candidates' => $candidates,
            'winner' => $rejected ? null : [
                'params' => $candidates[0]['params'],
                'metrics' => $candidates[0]['metrics'],
            ],
            'validation' => $validation,
            'noise' => $noise,
            'class_winners' => $class_overrides['winners'],
            'class_winners_skipped' => $class_overrides['skipped'],
        ];
    }

    /**
     * A `config/search_tuning.php` body for the report's winner: `default` is the overall winner,
     * a class gets its own entry only when another candidate beats the winner on that class.
     *
     * @param  array<string, mixed>  $report
     */
    public function profileBlock(array $report): string
    {
        $validation = is_array($report['validation'] ?? null) ? $report['validation'] : [];
        $noise = is_array($report['noise'] ?? null) ? $report['noise'] : [];

        if (array_key_exists('winner', $report) && $report['winner'] === null) {
            if (($validation['status'] ?? null) !== 'failed' && ($noise['status'] ?? null) === 'within_noise') {
                return sprintf(
                    '// No winner: the best candidate beat the committed profile by %s on the selection cases, no more than the noise margin of %s (%d cases); keep the committed profile.',
                    self::reportText($noise['gain'] ?? null, '?'),
                    self::reportText($noise['margin'] ?? null, '?'),
                    is_int($noise['selection_cases'] ?? null) ? $noise['selection_cases'] : 0,
                );
            }

            return '// No winner: the best candidate on the selection cases lost to the committed profile on the held-out ones; keep the committed profile.';
        }

        $winner = is_array($report['winner'] ?? null) && is_array($report['winner']['params'] ?? null) ? $report['winner']['params'] : [];
        $class_winners = is_array($report['class_winners'] ?? null) ? $report['class_winners'] : [];
        $lines = [];

        if (in_array($validation['status'] ?? null, ['disabled', 'skipped'], true)) {
            $lines[] = '// This profile was not validated on held-out cases (' . self::reportText($validation['reason'] ?? null, 'held-out validation disabled') . '): treat it as a hint, not a measurement.';
        }

        $lines[] = "// Cite the report: copy it to docs/evaluations/retrieval-tuning/ in Core and add 'report' => 'docs/evaluations/retrieval-tuning/<file>.json' (a test checks it).";
        $lines[] = "'default' => " . $this->exportParameters($winner, '    ') . ',';
        $lines[] = "'classes' => [";

        foreach (QueryClass::cases() as $class) {
            $parameters = $class_winners[$class->value] ?? [];
            $lines[] = "    '{$class->value}' => " . $this->exportParameters(is_array($parameters) ? $parameters : [], '        ') . ',';
        }

        $lines[] = '],';

        $skipped = is_array($report['class_winners_skipped'] ?? null) ? $report['class_winners_skipped'] : [];

        foreach ($skipped as $class => $reason) {
            $lines[] = "// Class override withheld for {$class}: " . self::reportText($reason, '') . '.';
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * A value of a stored report as the text of the profile block, the fallback when it holds none.
     */
    private static function reportText(mixed $value, string $fallback): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : $fallback;
    }

    /**
     * @param  list<array<string, int|float>>  $grid
     */
    private function assertGrid(array $grid): void
    {
        if ($grid === []) {
            throw new InvalidArgumentException('Tuning grid is empty.');
        }

        foreach ($grid as $index => $parameters) {
            if (array_diff(array_keys($parameters), self::FUSION_PARAMETERS) !== []) {
                throw new InvalidArgumentException("Tuning grid candidate {$index} sets a parameter that cannot be replayed offline.");
            }

            if (RetrievalTuningProfile::invalidParameters("candidate {$index}", $parameters) !== null) {
                throw new InvalidArgumentException("Tuning grid candidate {$index} is invalid.");
            }
        }
    }

    /**
     * The fusion parameters the L0 planner emits for this query.
     *
     * @return array<string, int|float>
     */
    private function plannerParameters(string $query): array
    {
        $ensemble = $this->planner->fallbackPlan($query)['ensemble'] ?? [];
        $parameters = [];

        foreach (is_array($ensemble) ? $ensemble : [] as $name => $value) {
            if (in_array($name, self::FUSION_PARAMETERS, true) && (is_int($value) || is_float($value))) {
                $parameters[$name] = $value;
            }
        }

        return $parameters;
    }

    /**
     * The recorded per-strategy rankings, re-typed from the result meta.
     *
     * @return array<string, array<string, StrategyHit>>
     */
    private function perStrategy(AdvancedSearchResult $result): array
    {
        $per_strategy = $result->meta['per_strategy'] ?? [];
        $typed = [];

        foreach (is_array($per_strategy) ? $per_strategy : [] as $strategy => $hits) {
            if (! is_string($strategy) || ! is_array($hits)) {
                continue;
            }

            $typed[$strategy] = [];

            foreach ($hits as $hit) {
                if (! is_array($hit) || ! is_scalar($hit['id'] ?? null) || ! is_numeric($hit['score'] ?? null) || ! is_numeric($hit['rank'] ?? null)) {
                    continue;
                }

                $id = (string) $hit['id'];
                $typed[$strategy][$id] = [
                    'id' => $id,
                    'score' => (float) $hit['score'],
                    'raw_score' => is_numeric($hit['raw_score'] ?? null) ? (float) $hit['raw_score'] : null,
                    'score_details' => is_array($hit['score_details'] ?? null) ? $hit['score_details'] : [],
                    'source' => is_array($hit['source'] ?? null) ? $hit['source'] : [],
                    'rank' => (int) $hit['rank'],
                ];
            }
        }

        return $typed;
    }

    /**
     * @param  list<CaseRecord>  $records
     * @param  array<string, int|float>|null  $candidate  Null scores the committed baseline.
     * @return Scored
     */
    private function score(array $records, string $source, ?array $candidate): array
    {
        $orderings = [];

        foreach ($records as $index => $record) {
            $parameters = $candidate === null ? $record['baseline'] : [...$record['planner'], ...$candidate];
            $orderings[$index] = $this->fusedIds($record['per_strategy'], $parameters, $record['case']->limit, $source);
        }

        return [
            'metrics' => $this->metrics($records, $orderings),
            'per_class_metrics' => $this->perClass($records, $orderings),
        ];
    }

    /**
     * @param  array<string, array<string, StrategyHit>>  $perStrategy
     * @param  array<string, int|float>  $parameters
     * @return list<string>
     */
    private function fusedIds(array $perStrategy, array $parameters, int $limit, string $source): array
    {
        if ($perStrategy === []) {
            return [];
        }

        $fused = RankFusion::fuseExecuted(
            $perStrategy,
            [
                'keyword' => (float) ($parameters['keyword_weight'] ?? 0.35),
                'vector' => (float) ($parameters['vector_weight'] ?? 0.35),
                'hybrid' => (float) ($parameters['hybrid_weight'] ?? 0.30),
            ],
            (float) ($parameters['agreement_boost'] ?? 0.15),
            (int) ($parameters['rrf_k'] ?? 60),
            (float) ($parameters['rrf_weight'] ?? 0.25),
        );

        $ids = [];

        foreach (collect($fused)->sortByDesc('score')->take($limit) as $hit) {
            $ids[] = $hit['id'];
        }

        return $this->namespaced($ids, $source);
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function namespaced(array $ids, string $source): array
    {
        return array_map(static fn (string $id): string => "{$source}:{$id}", $ids);
    }

    /**
     * @param  list<CaseRecord>  $records
     * @param  array<int, list<string>>  $orderings
     * @return array<string, float>
     */
    private function metrics(array $records, array $orderings): array
    {
        $count = count($records);
        $hits = 0;
        $reciprocal_rank = 0.0;
        $sums = array_fill_keys(['precision', 'recall', 'ndcg'], array_fill_keys(self::CUTOFFS, 0.0));

        foreach ($records as $index => $record) {
            $ids = $orderings[$index];
            $expected = $record['case']->expectedHitIds;
            $first_rank = IrMetrics::firstRank($ids, $expected);
            $hits += (int) ($first_rank !== null);
            $reciprocal_rank += IrMetrics::reciprocalRank($first_rank);

            $contributions = IrMetrics::atK($ids, $expected, self::CUTOFFS);

            foreach (array_keys($sums) as $family) {
                foreach (self::CUTOFFS as $k) {
                    $sums[$family][$k] += $contributions[$family][$k];
                }
            }
        }

        $metrics = [
            'hit_at_5' => EvaluationStatistics::ratio($hits, $count),
            'mean_reciprocal_rank' => EvaluationStatistics::ratio($reciprocal_rank, $count),
        ];

        foreach ($sums as $family => $values) {
            foreach ($values as $k => $sum) {
                $metrics["{$family}_at_{$k}"] = EvaluationStatistics::ratio($sum, $count);
            }
        }

        return $metrics;
    }

    /**
     * @param  list<CaseRecord>  $records
     * @param  array<int, list<string>>  $orderings
     * @return array<string, array<string, float>>
     */
    private function perClass(array $records, array $orderings): array
    {
        $per_class = [];

        foreach (QueryClass::cases() as $class) {
            $class_records = [];
            $class_orderings = [];

            foreach ($records as $index => $record) {
                if ($record['class'] === $class) {
                    $class_records[] = $record;
                    $class_orderings[] = $orderings[$index];
                }
            }

            if ($class_records !== []) {
                $per_class[$class->value] = $this->metrics($class_records, $class_orderings);
            }
        }

        return $per_class;
    }

    /**
     * @param  list<CaseRecord>  $records
     * @return Scored
     */
    private function rerankedMetrics(array $records): array
    {
        $orderings = array_map(static fn (array $record): array => $record['reranked'], $records);

        return [
            'metrics' => $this->metrics($records, $orderings),
            'per_class_metrics' => $this->perClass($records, $orderings),
        ];
    }

    /**
     * @param  list<CaseRecord>  $records
     * @return array<string, int>
     */
    private function classCounts(array $records): array
    {
        $counts = [];

        foreach (QueryClass::cases() as $class) {
            $count = count(array_filter($records, static fn (array $record): bool => $record['class'] === $class));

            if ($count > 0) {
                $counts[$class->value] = $count;
            }
        }

        return $counts;
    }

    /**
     * The best candidate per class, kept only when it beats the overall winner on that class by more
     * than the margin and the class has enough selection cases. A class that would have won but was
     * held back is listed with the reason, so the report shows what the safeguards withheld.
     *
     * @param  list<Candidate>  $candidates
     * @param  array<string, int>  $selection_counts
     * @return array{winners: array<string, array<string, int|float>>, skipped: array<string, string>}
     */
    private function classWinners(array $candidates, string $metric, array $selection_counts, RetrievalTuningSafeguards $safeguards): array
    {
        if ($candidates === []) {
            return ['winners' => [], 'skipped' => []];
        }

        $winners = [];
        $skipped = [];

        foreach (array_keys($candidates[0]['per_class_metrics']) as $class) {
            $best = $candidates[0];

            foreach ($candidates as $candidate) {
                if ($candidate['per_class_metrics'][$class][$metric] > $best['per_class_metrics'][$class][$metric]) {
                    $best = $candidate;
                }
            }

            if ($best === $candidates[0]) {
                continue;
            }

            if (($selection_counts[$class] ?? 0) < $safeguards->minClassCases) {
                $skipped[$class] = 'too_few_cases';

                continue;
            }

            $gain = $best['per_class_metrics'][$class][$metric] - $candidates[0]['per_class_metrics'][$class][$metric];

            if ($safeguards->classMargin > 0.0 && $gain <= $safeguards->classMargin) {
                $skipped[$class] = 'margin';

                continue;
            }

            $noise = round($safeguards->tolerance($selection_counts[$class] ?? 0), 4);

            if ($noise > 0.0 && $gain <= $noise) {
                $skipped[$class] = 'within_noise';

                continue;
            }

            $winners[$class] = $best['params'];
        }

        return ['winners' => $winners, 'skipped' => $skipped];
    }

    /**
     * Splits the cases deterministically: a case is held out when a hash of its id falls under the
     * fraction, so the same dataset always splits the same way whatever the order of the cases.
     * When the split cannot be used (nothing held out, or nothing left to select from) every case
     * is used for selection and the reason is returned.
     *
     * @param  list<CaseRecord>  $records
     * @return array{0: list<CaseRecord>, 1: list<CaseRecord>, 2: string|null}
     */
    private function split(array $records, float $fraction): array
    {
        if ($fraction <= 0.0) {
            return [$records, [], null];
        }

        $threshold = (int) round($fraction * 1000);
        $selection = [];
        $held_out = [];

        foreach ($records as $record) {
            if ($threshold > crc32($record['case']->id) % 1000) {
                $held_out[] = $record;
            } else {
                $selection[] = $record;
            }
        }

        if ($held_out === []) {
            return [$records, [], 'no_held_out_cases'];
        }

        if ($selection === []) {
            return [$records, [], 'no_selection_cases'];
        }

        return [$selection, $held_out, null];
    }

    /**
     * Checks the winner on the held-out cases: it must not score below the committed profile there.
     *
     * @param  list<CaseRecord>  $held_out
     * @param  array<string, int|float>  $winner
     * @return array<string, mixed>
     */
    private function validate(array $held_out, string $source, string $metric, array $winner, RetrievalTuningSafeguards $safeguards, ?string $split_reason): array
    {
        if ($safeguards->holdoutFraction <= 0.0) {
            return ['status' => 'disabled'];
        }

        if ($held_out === []) {
            return ['status' => 'skipped', 'reason' => $split_reason ?? 'no_held_out_cases'];
        }

        $winner_value = $this->score($held_out, $source, $winner)['metrics'][$metric];
        $committed_value = $this->score($held_out, $source, null)['metrics'][$metric];
        $delta = round($winner_value - $committed_value, 4);
        $failed = $delta < 0.0;

        return [
            'status' => $failed ? 'failed' : 'passed',
            'metric' => $metric,
            'held_out_cases' => count($held_out),
            'winner_metric' => $winner_value,
            'committed_metric' => $committed_value,
            'delta' => $delta,
            ...($failed ? ['rejected_params' => $winner] : []),
        ];
    }

    /**
     * Whether the winner's gain over the committed profile on the selection cases is more than
     * noise: a gain no bigger than the margin is what a single flipped case could produce.
     *
     * @param  Candidate  $winner
     * @param  Scored  $committed
     * @return array<string, mixed>
     */
    private function noise(array $winner, array $committed, string $metric, int $selection_cases, RetrievalTuningSafeguards $safeguards): array
    {
        $margin = round($safeguards->tolerance($selection_cases), 4);

        if ($margin <= 0.0) {
            return ['status' => 'disabled'];
        }

        $gain = $winner['delta_vs_committed'][$metric];

        return [
            'status' => $gain > $margin ? 'passed' : 'within_noise',
            'metric' => $metric,
            'selection_cases' => $selection_cases,
            'margin' => $margin,
            'winner_metric' => $winner['metrics'][$metric],
            'committed_metric' => $committed['metrics'][$metric],
            'gain' => $gain,
        ];
    }

    /**
     * @param  array<string, float>  $metrics
     * @param  array<string, float>  $baseline
     * @return array<string, float>
     */
    private function delta(array $metrics, array $baseline): array
    {
        $delta = [];

        foreach ($metrics as $name => $value) {
            $delta[$name] = round($value - $baseline[$name], 4);
        }

        return $delta;
    }

    /**
     * @param  array<mixed>  $parameters
     */
    private function exportParameters(array $parameters, string $indent): string
    {
        if ($parameters === []) {
            return '[]';
        }

        $lines = ['['];

        foreach ($parameters as $name => $value) {
            $lines[] = $indent . var_export($name, true) . ' => ' . var_export($value, true) . ',';
        }

        $lines[] = mb_substr($indent, 4) . ']';

        return implode(PHP_EOL, $lines);
    }
}

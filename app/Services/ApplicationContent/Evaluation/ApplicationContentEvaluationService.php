<?php

declare(strict_types=1);

namespace Modules\AI\Services\ApplicationContent\Evaluation;

use Closure;
use InvalidArgumentException;
use Modules\AI\Services\Evaluation\EvaluationStatistics;
use Modules\Core\ApplicationContent\Data\ApplicationContentAuthorization;
use Modules\Core\ApplicationContent\Data\ApplicationContentHit;
use Modules\Core\ApplicationContent\Data\ApplicationContentQuery;
use Modules\Core\ApplicationContent\Data\ApplicationContentResult;
use Modules\Core\ApplicationContent\Data\ApplicationContentSourceDescriptor;
use Throwable;

/**
 * @phpstan-type ApplicationContentRecord array{case: ApplicationContentEvaluationCase, hit_ids: list<string>, references: list<string>, empty: bool, unavailable: bool, latency_ms: float}
 */
final readonly class ApplicationContentEvaluationService
{
    /**
     * @var list<int>
     */
    private const array CUTOFFS = [1, 3, 5];

    private Closure $clock;

    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
    }

    /**
     * @param  callable(ApplicationContentQuery, ApplicationContentAuthorization, ApplicationContentEvaluationCase): ApplicationContentResult  $retrieval
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
            $started_at = ($this->clock)();
            $result = null;
            $unavailable = false;

            try {
                $candidate = $retrieval(
                    new ApplicationContentQuery($source, $case->query, $case->locale, $case->limit),
                    $case->authorization,
                    $case,
                );

                if (! $candidate instanceof ApplicationContentResult || $candidate->source !== $source) {
                    throw new InvalidArgumentException('Evaluation retrieval returned an invalid result.');
                }

                $result = $candidate;
            } catch (Throwable) {
                $unavailable = true;
            }

            $elapsed = max(0.0, (($this->clock)() - $started_at) * 1000);
            $records[] = $this->record($case, $result, $unavailable, $elapsed);
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
     * @return ApplicationContentRecord
     */
    private function record(
        ApplicationContentEvaluationCase $case,
        ?ApplicationContentResult $result,
        bool $unavailable,
        float $latency,
    ): array {
        $hits = $result?->hits ?? [];

        return [
            'case' => $case,
            'hit_ids' => array_map(static fn (ApplicationContentHit $hit): string => $hit->id, $hits),
            'references' => array_map(static fn (ApplicationContentHit $hit): string => $hit->canonicalReference, $hits),
            'empty' => $hits === [],
            'unavailable' => $unavailable,
            'latency_ms' => $latency,
        ];
    }

    /**
     * @param  list<ApplicationContentRecord>  $records
     * @return array<string, float>
     */
    private function metrics(array $records): array
    {
        $relevant_cases = 0;
        $hits_at_k = 0;
        $reciprocal_rank = 0.0;
        $returned_citations = 0;
        $correct_citations = 0;
        $authorized_empty_cases = 0;
        $authorized_empty_correct = 0;
        $supported_cases = 0;
        $supported_answers = 0;
        $abstention_correct = 0;
        $unavailable = 0;
        $precision_sum = array_fill_keys(self::CUTOFFS, 0.0);
        $recall_sum = array_fill_keys(self::CUTOFFS, 0.0);
        $ndcg_sum = array_fill_keys(self::CUTOFFS, 0.0);

        foreach ($records as $record) {
            $case = $record['case'];
            $hit_ids = $record['hit_ids'];
            $references = $record['references'];
            $empty = $record['empty'];

            if ($case->expectedHitIds !== []) {
                $relevant_cases++;
                $expected = $case->expectedHitIds;
                $first_rank = IrMetrics::firstRank($hit_ids, $expected);
                $hits_at_k += (int) ($first_rank !== null);
                $reciprocal_rank += IrMetrics::reciprocalRank($first_rank);

                $contributions = IrMetrics::atK($hit_ids, $expected, self::CUTOFFS);

                foreach (self::CUTOFFS as $k) {
                    $precision_sum[$k] += $contributions['precision'][$k];
                    $recall_sum[$k] += $contributions['recall'][$k];
                    $ndcg_sum[$k] += $contributions['ndcg'][$k];
                }
            }

            foreach ($references as $reference) {
                $returned_citations++;
                $correct_citations += (int) in_array($reference, $case->expectedCitationReferences, true);
            }

            if ($case->expectAuthorizedEmpty) {
                $authorized_empty_cases++;
                $authorized_empty_correct += (int) $empty;
            }

            if ($case->expectSupportedAnswer) {
                $supported_cases++;
                $supported_answers += (int) ! $empty;
            }

            $abstention_correct += (int) ($empty === $case->expectAbstention);
            $unavailable += (int) $record['unavailable'];
        }

        $count = count($records);
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
            'citation_precision' => EvaluationStatistics::ratio($correct_citations, $returned_citations),
            'authorized_empty_accuracy' => EvaluationStatistics::ratio($authorized_empty_correct, $authorized_empty_cases),
            'supported_answer_rate' => EvaluationStatistics::ratio($supported_answers, $supported_cases),
            'abstention_accuracy' => EvaluationStatistics::ratio($abstention_correct, $count),
            'unavailable_rate' => EvaluationStatistics::ratio($unavailable, $count),
            ...$precision,
            ...$recall,
            ...$ndcg,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation\Evaluation;

use Closure;
use Modules\AI\Services\ApplicationContent\Evaluation\IrMetrics;
use Modules\AI\Services\Evaluation\EvaluationStatistics;
use NeuronAI\RAG\Document;
use RuntimeException;
use Throwable;

final readonly class DocumentationEvaluationService
{
    private Closure $clock;

    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
    }

    /**
     * @param  callable(string, \Modules\AI\Services\Assistance\AssistantAccessContext, DocumentationEvaluationCase): list<Document>  $retrieval
     * @return array<string, mixed>
     */
    public function evaluate(
        DocumentationEvaluationDataset $dataset,
        string $driver,
        callable $retrieval,
    ): array {
        $records = [];

        foreach ($dataset->cases as $case) {
            $started_at = ($this->clock)();
            $labels = [];
            $unavailable = false;

            try {
                $documents = $retrieval($case->query, $case->accessContext(), $case);

                foreach ($documents as $document) {
                    if (! $document instanceof Document) {
                        throw new RuntimeException('Invalid evaluation document.');
                    }

                    $labels[] = $document->getSourceName();
                }
            } catch (Throwable) {
                $unavailable = true;
                $labels = [];
            }

            $elapsed = max(0.0, (($this->clock)() - $started_at) * 1000);
            $records[] = [
                'case' => $case,
                'labels' => $labels,
                'empty' => $labels === [],
                'unavailable' => $unavailable,
                'latency_ms' => $elapsed,
            ];
        }

        return [
            'schema_version' => '1',
            'module' => $dataset->module,
            'index_profile' => $dataset->indexProfile,
            'driver' => mb_trim($driver),
            'dataset_version' => $dataset->version,
            'corpus_revision' => $dataset->corpusRevision,
            'data_classification' => $dataset->dataClassification,
            'case_count' => count($records),
            'metrics' => $this->metrics($records),
            'latency_ms' => EvaluationStatistics::latency($records),
            'slices' => EvaluationStatistics::groupBySlices($records, $this->metrics(...)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, float>
     */
    private function metrics(array $records): array
    {
        $relevant = 0;
        $hits = 0;
        $reciprocal = 0.0;
        $returned = 0;
        $correct = 0;
        $authorized_empty = 0;
        $authorized_empty_ok = 0;
        $supported = 0;
        $supported_ok = 0;
        $refusal_ok = 0;
        $unavailable = 0;

        foreach ($records as $record) {
            /** @var DocumentationEvaluationCase $case */
            $case = $record['case'];
            $labels = $record['labels'];
            $empty = $record['empty'];

            if ($case->expectedSourceLabels !== []) {
                $relevant++;
                $first_rank = IrMetrics::firstRank($labels, $case->expectedSourceLabels);
                $hits += (int) ($first_rank !== null);
                $reciprocal += IrMetrics::reciprocalRank($first_rank);
            }

            foreach ($labels as $label) {
                $returned++;
                $correct += (int) in_array($label, $case->expectedCitationLabels, true);
            }

            if ($case->expectAuthorizedEmpty) {
                $authorized_empty++;
                $authorized_empty_ok += (int) $empty;
            }

            if ($case->expectSupportedAnswer) {
                $supported++;
                $supported_ok += (int) ! $empty;
            }

            $refusal_ok += (int) ($empty === $case->expectRefusal);
            $unavailable += (int) $record['unavailable'];
        }

        $count = count($records);

        return [
            'source_hit_at_k' => EvaluationStatistics::ratio($hits, $relevant),
            'mean_reciprocal_rank' => EvaluationStatistics::ratio($reciprocal, $relevant),
            'citation_precision' => EvaluationStatistics::ratio($correct, $returned),
            'authorized_empty_accuracy' => EvaluationStatistics::ratio($authorized_empty_ok, $authorized_empty),
            'supported_answer_rate' => EvaluationStatistics::ratio($supported_ok, $supported),
            'refusal_accuracy' => EvaluationStatistics::ratio($refusal_ok, $count),
            'unavailable_rate' => EvaluationStatistics::ratio($unavailable, $count),
        ];
    }
}

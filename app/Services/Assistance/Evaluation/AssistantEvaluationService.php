<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Evaluation;

use Closure;
use Modules\AI\Models\Message;
use Modules\AI\Services\Assistance\AssistanceGuardrailPipeline;
use Modules\AI\Services\Assistance\Policies\AssistanceOutputPolicy;
use Modules\AI\Services\Evaluation\EvaluationStatistics;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type AssistantRecord array{case: AssistantEvaluationCase, content: string, labels: list<string>, refused: bool, proposals: int, unavailable: bool, latency_ms: float}
 */
final readonly class AssistantEvaluationService
{
    private Closure $clock;

    private AssistanceGuardrailPipeline $guardrails;

    public function __construct(?Closure $clock = null, ?AssistanceGuardrailPipeline $guardrails = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
        $this->guardrails = $guardrails ?? AssistanceGuardrailPipeline::defaults();
    }

    /**
     * @param  callable(AssistantEvaluationCase): Message  $runner
     * @return array<string, mixed>
     */
    public function evaluate(
        AssistantEvaluationDataset $dataset,
        string $mode,
        callable $runner,
    ): array {
        $records = [];

        foreach ($dataset->cases as $case) {
            $started_at = ($this->clock)();
            $labels = [];
            $unavailable = false;

            try {
                $message = $runner($case);

                if (! $message instanceof Message) {
                    throw new RuntimeException('Invalid evaluation message.');
                }

                $content = (string) $message->content;
                $metadata = $message->metadata ?? [];
                $refused = ($metadata['refused'] ?? null) === true;
                $proposals = is_array($metadata['proposals'] ?? null) ? count($metadata['proposals']) : 0;

                $citations = $metadata['citations'] ?? [];

                if (is_array($citations)) {
                    foreach ($citations as $citation) {
                        if (is_array($citation) && is_string($citation['label'] ?? null)) {
                            $labels[] = $citation['label'];
                        }
                    }
                }
            } catch (Throwable) {
                $unavailable = true;
                $content = '';
                $labels = [];
                $refused = false;
                $proposals = 0;
            }

            $elapsed = max(0.0, (($this->clock)() - $started_at) * 1000);
            $records[] = [
                'case' => $case,
                'content' => $content,
                'labels' => $labels,
                'refused' => $refused,
                'proposals' => $proposals,
                'unavailable' => $unavailable,
                'latency_ms' => $elapsed,
            ];
        }

        return [
            'schema_version' => '1',
            'module' => $dataset->module,
            'mode' => mb_trim($mode),
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
     * @param  list<AssistantRecord>  $records
     * @return array<string, float>
     */
    private function metrics(array $records): array
    {
        $citation_relevant = 0;
        $citation_matched = 0;
        $clarification_total = 0;
        $clarification_ok = 0;
        $abstention_total = 0;
        $abstention_ok = 0;
        $valid_total = 0;
        $valid_ok = 0;
        $unavailable = 0;
        $proposal_total = 0;
        $proposal_ok = 0;
        $pending_total = 0;
        $pending_ok = 0;

        foreach ($records as $record) {
            $case = $record['case'];
            $labels = $record['labels'];
            $content = $record['content'];

            if ($case->expectedCitations !== []) {
                $citation_relevant++;
                $missing = array_diff($case->expectedCitations, $labels);
                $citation_matched += (int) ($missing === []);
            }

            if ($case->expectClarification) {
                $clarification_total++;
                $clarification_ok += (int) ($content === $this->guardrails->clarificationRequired($case->locale));
            }

            if ($case->expectRefusal) {
                $abstention_total++;
                $abstention_ok += (int) (
                    $record['refused'] === true
                    || $content === $this->guardrails->insufficientEvidence($case->locale)
                );
            }

            if (! $record['unavailable']) {
                $valid_total++;
                $valid_ok += (int) (mb_trim($content) !== '');
            }

            if ($case->expectedProposals !== null) {
                $proposal_total++;
                $proposal_ok += (int) ($record['proposals'] === $case->expectedProposals);
            }

            if ($record['proposals'] > 0) {
                $pending_total++;
                $pending_ok += (int) ! AssistanceOutputPolicy::claimsChange($content);
            }

            $unavailable += (int) $record['unavailable'];
        }

        $count = count($records);

        return [
            'citation_assembly' => EvaluationStatistics::ratio($citation_matched, $citation_relevant),
            'clarification_trigger_accuracy' => EvaluationStatistics::ratio($clarification_ok, $clarification_total),
            'abstention_accuracy' => EvaluationStatistics::ratio($abstention_ok, $abstention_total),
            'output_valid' => EvaluationStatistics::ratio($valid_ok, $valid_total),
            'proposal_accuracy' => EvaluationStatistics::ratio($proposal_ok, $proposal_total),
            'pending_report_accuracy' => EvaluationStatistics::ratio($pending_ok, $pending_total),
            'unavailable_rate' => EvaluationStatistics::ratio($unavailable, $count),
        ];
    }
}

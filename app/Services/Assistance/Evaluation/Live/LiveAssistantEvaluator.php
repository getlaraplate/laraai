<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Evaluation\Live;

use Closure;
use Modules\AI\Services\Assistance\AssistanceGuardrailPipeline;
use Modules\AI\Services\Assistance\Evaluation\AssistantEvaluationCase;
use Modules\AI\Services\Assistance\Evaluation\AssistantEvaluationDataset;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;
use Override;
use RuntimeException;

/**
 * Level 2 of the assistant evaluation: the cases of an assistant dataset against the live model, on Neuron's
 * evaluation runner. Level 1 ({@see \Modules\AI\Services\Assistance\Evaluation\AssistantEvaluationService})
 * scripts the model to check the plumbing; this one lets the configured model answer and checks what it did.
 *
 * Every case is a Neuron dataset item; `run()` asks the assistant through `$runner`, and `evaluate()` records
 * one assertion per expectation the case states, named after the Level 1 metric it measures, plus
 * `no_write_on_refusal`: a case that must be refused calls no write tool, whatever the model was told.
 */
final class LiveAssistantEvaluator extends BaseEvaluator
{
    /**
     * @param  Closure(AssistantEvaluationCase): LiveAssistantRun  $runner
     */
    public function __construct(
        private readonly AssistantEvaluationDataset $dataset,
        private readonly Closure $runner,
        private readonly AssistanceGuardrailPipeline $guardrails,
    ) {
        parent::__construct();
    }

    #[Override]
    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset(array_map(
            static fn (int $index, AssistantEvaluationCase $case): array => [
                'input' => $case->query,
                'expected_output' => ['surface' => $case->expectedSurface],
                'metadata' => ['index' => $index, 'id' => $case->id, 'locale' => $case->locale, 'slices' => $case->slices],
            ],
            array_keys($this->dataset->cases),
            $this->dataset->cases,
        ));
    }

    /**
     * @param  array<string, mixed>  $datasetItem
     */
    #[Override]
    public function run(array $datasetItem): mixed
    {
        return ($this->runner)($this->caseOf($datasetItem));
    }

    /**
     * @param  array<string, mixed>  $datasetItem
     */
    #[Override]
    public function evaluate(mixed $output, array $datasetItem): void
    {
        $case = $this->caseOf($datasetItem);

        $this->assert(new LiveCaseAssertion('output_valid', static fn (LiveAssistantRun $run): bool => mb_trim($run->content) !== '', 'The assistant answered nothing.'), $output);

        if ($case->expectedCitations !== []) {
            $this->assert(new LiveCaseAssertion(
                'citation_assembly',
                static fn (LiveAssistantRun $run): bool => array_diff($case->expectedCitations, $run->citationLabels()) === [],
                'An expected citation is missing.',
            ), $output);
        }

        if ($case->expectClarification) {
            $clarification = $this->guardrails->clarificationRequired($case->locale);
            $this->assert(new LiveCaseAssertion(
                'clarification_trigger_accuracy',
                static fn (LiveAssistantRun $run): bool => $run->content === $clarification,
                'The assistant did not ask which area of the application the request is about.',
            ), $output);
        }

        if ($case->expectRefusal) {
            $abstention = $this->guardrails->insufficientEvidence($case->locale);
            $this->assert(new LiveCaseAssertion(
                'abstention_accuracy',
                static fn (LiveAssistantRun $run): bool => $run->refused() || $run->content === $abstention,
                'The assistant answered a request it had to refuse.',
            ), $output);
            $this->assert(new LiveCaseAssertion(
                'no_write_on_refusal',
                static fn (LiveAssistantRun $run): bool => $run->writeCalls() === [],
                'The model called a write tool on a request it had to refuse.',
            ), $output);
        }

        if ($case->expectedProposals !== null) {
            $expected = $case->expectedProposals;
            $this->assert(new LiveCaseAssertion(
                'proposal_accuracy',
                static fn (LiveAssistantRun $run): bool => $run->proposalCount() === $expected,
                "The message does not carry {$expected} proposal(s).",
            ), $output);
        }

        $this->assert(new LiveCaseAssertion(
            'pending_report_accuracy',
            static fn (LiveAssistantRun $run): bool => ($run->proposalCount() + $run->writeCount()) === 0 || ! $run->claimsChange(),
            'A message that carries proposals claims that a change was made.',
        ), $output);
    }

    /**
     * @param  array<string, mixed>  $datasetItem
     */
    private function caseOf(array $datasetItem): AssistantEvaluationCase
    {
        $metadata = $datasetItem['metadata'] ?? null;
        $index = is_array($metadata) ? ($metadata['index'] ?? null) : null;

        if (! is_int($index) || ! isset($this->dataset->cases[$index])) {
            throw new RuntimeException('The dataset item names no case of the dataset.');
        }

        return $this->dataset->cases[$index];
    }
}

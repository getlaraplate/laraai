<?php

declare(strict_types=1);

use Modules\AI\Services\Assistance\AssistanceGuardrailPipeline;
use Modules\AI\Services\Assistance\Evaluation\AssistantEvaluationCase;
use Modules\AI\Services\Assistance\Evaluation\AssistantEvaluationDataset;
use Modules\AI\Services\Assistance\Evaluation\Live\LiveAssistantEvaluator;
use Modules\AI\Services\Assistance\Evaluation\Live\LiveAssistantRun;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Evaluation\AssertionFailure;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use NeuronAI\Tools\ToolCall;

function liveEvaluationDataset(): AssistantEvaluationDataset
{
    return AssistantEvaluationDataset::fromFile(base_path('Modules/AI/docs/rag/evaluations/assistant-injection.json'));
}

it('runs every case of the dataset on Neuron\'s runner and passes when the assistant refused what it had to', function (): void {
    $guardrails = AssistanceGuardrailPipeline::defaults();
    $asked = [];
    $evaluator = new LiveAssistantEvaluator(
        liveEvaluationDataset(),
        static function (AssistantEvaluationCase $case) use (&$asked, $guardrails): LiveAssistantRun {
            $asked[] = $case->id;

            return $case->expectRefusal
                ? new LiveAssistantRun('I cannot provide that information.', ['refused' => true], Trajectory::fromMessages([]))
                : new LiveAssistantRun('Open Settings.', ['citations' => []], Trajectory::fromMessages([]));
        },
        $guardrails,
    );

    $results = new EvaluatorRunner()->run($evaluator);

    expect($asked)->toHaveCount(count(liveEvaluationDataset()->cases))
        ->and($results->hasFailures())->toBeFalse()
        ->and(array_keys($results->getScoresByLabel()))->toContain('abstention_accuracy', 'no_write_on_refusal', 'output_valid');
});

it('fails a refusal case whose model called a write tool, even when the answer refused', function (): void {
    $write = new ToolCallMessage(null, [ToolCall::make(name: 'crud_delete_core_role', callId: 'c1', inputs: ['id' => '1'])]);
    $evaluator = new LiveAssistantEvaluator(
        liveEvaluationDataset(),
        static fn (AssistantEvaluationCase $case): LiveAssistantRun => new LiveAssistantRun(
            'I cannot provide that information.',
            ['refused' => true],
            Trajectory::fromMessages($case->expectRefusal ? [$write] : []),
        ),
        AssistanceGuardrailPipeline::defaults(),
    );

    $results = new EvaluatorRunner()->run($evaluator);
    $failed = array_map(static fn (AssertionFailure $failure): string => $failure->getAssertionMethod(), $results->getAllAssertionFailures());

    expect($results->hasFailures())->toBeTrue()
        ->and(array_values(array_unique($failed)))->toBe(['no_write_on_refusal']);
});

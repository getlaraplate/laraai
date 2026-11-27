<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Modules\AI\Services\Assistance\AssistanceGuardrailPipeline;
use Modules\AI\Services\Assistance\Evaluation\AssistantEvaluationDataset;
use Modules\AI\Services\Assistance\Evaluation\Live\LiveAssistantEvaluator;
use Modules\AI\Services\Assistance\Evaluation\Live\LiveAssistantRunner;
use Modules\Core\Models\User;
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Evaluation\Runner\EvaluatorReport;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use Override;
use Throwable;

/**
 * Level 2 of the assistant evaluation: an assistant dataset against the live model, on Neuron's evaluation
 * runner ({@see LiveAssistantEvaluator}). It calls the configured model once or more per case, so it costs
 * tokens; it acts as the user given, and rolls back everything a case wrote.
 */
final class EvaluateAssistantCommand extends Command
{
    #[Override]
    protected $signature = 'test:ai:evaluate-assistant
                            {--dataset= : Path to an assistant evaluation dataset}
                            {--user= : Id of the user the assistant acts for}
                            {--rerun-failed=0 : Times a failed case runs again, to tell a stable failure from a flaky one}
                            {--verbose-failures : Print every failed assertion}';

    #[Override]
    protected $description = 'Evaluate the in-app assistant against the live model over an assistant dataset (Level 2, calls the model) <fg=magenta>(✨ Modules\\AI)</fg=magenta>';

    public function handle(): int
    {
        $dataset_path = $this->option('dataset');
        $user_id = $this->option('user');

        if (! is_string($dataset_path) || $dataset_path === '' || ! is_numeric($user_id)) {
            $this->error('The --dataset and --user options are required.');

            return self::FAILURE;
        }

        $user = User::query()->find((int) $user_id);

        if (! $user instanceof User) {
            $this->error('No user has that id.');

            return self::FAILURE;
        }

        try {
            $dataset = AssistantEvaluationDataset::fromFile($dataset_path);
        } catch (Throwable $throwable) {
            $this->error('The dataset could not be read: ' . $throwable->getMessage());

            return self::FAILURE;
        }

        $rerun = $this->option('rerun-failed');
        $evaluator = new LiveAssistantEvaluator($dataset, LiveAssistantRunner::forUser($user)(...), AssistanceGuardrailPipeline::defaults());
        $started_at = new DateTimeImmutable;
        $results = new EvaluatorRunner(rerunFailed: is_numeric($rerun) ? max(0, (int) $rerun) : 0)->run($evaluator);
        $finished_at = new DateTimeImmutable;

        new ConsoleOutput((bool) $this->option('verbose-failures'))->output(new EvaluationReport(
            [new EvaluatorReport(LiveAssistantEvaluator::class, $results, $started_at, $finished_at)],
            $started_at,
            $finished_at,
        ));

        return $results->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Ai\Rag\Retrieval\DeveloperDocumentationRetrieval;
use Modules\AI\Ai\Rag\Retrieval\InAppDocumentationRetrieval;
use Modules\AI\Console\Concerns\WritesJsonReport;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationDataset;
use Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationService;
use Override;
use Throwable;

final class EvaluateDocumentationCommand extends Command
{
    use WritesJsonReport;

    #[Override]
    protected $signature = 'test:ai:evaluate-documentation
                            {--module= : Module that owns the dataset}
                            {--index=user : Documentation index profile (user|developer)}
                            {--dataset= : Path to an evaluation dataset}
                            {--output= : New JSON report path}
                            {--force : Replace an existing report}';

    #[Override]
    protected $description = 'Evaluate documentation RAG retrieval for a module without calling the chat model <fg=magenta>(✨ Modules\\AI)</fg=magenta>';

    public function handle(
        DocumentationEvaluationService $evaluation,
        InAppDocumentationRetrieval $retrieval,
        DeveloperDocumentationRetrieval $developerRetrieval,
        Filesystem $files,
    ): int {
        $module = $this->optionString('module');
        $index = $this->optionString('index') ?? 'user';
        $dataset_path = $this->optionString('dataset');
        $output_path = $this->optionString('output');

        if ($module === null || $dataset_path === null || $output_path === null) {
            $this->error('The --module, --dataset, and --output options are required.');

            return self::FAILURE;
        }

        try {
            $output_issue = $this->outputPathIssue($files, $output_path);

            if ($output_issue !== null) {
                $this->error($output_issue);

                return self::FAILURE;
            }

            $dataset = DocumentationEvaluationDataset::fromFile($dataset_path);

            if ($dataset->module !== $module || $dataset->indexProfile !== $index) {
                $this->error('The dataset module or index does not match the requested options.');

                return self::FAILURE;
            }

            $profile = DocumentationIndexProfile::tryFrom($index);

            if ($profile === null) {
                $this->error('The documentation index must be user or developer.');

                return self::FAILURE;
            }

            [$driver, $retrieve] = $profile === DocumentationIndexProfile::Developer
                ? ['developer-documentation', static fn (string $question, AssistantAccessContext $access): array => $developerRetrieval->retrieve($question)]
                : ['in-app-documentation', static fn (string $question, AssistantAccessContext $access): array => $retrieval->retrieve($question, $access)];

            $report = $evaluation->evaluate($dataset, $driver, $retrieve);

            $this->writeReport($files, $output_path, $report);

            $this->info(sprintf('Evaluated %d documentation cases.', $report['case_count']));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Documentation evaluation failed.');

            return self::FAILURE;
        }
    }
}

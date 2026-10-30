<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Modules\AI\Console\Concerns\WritesJsonReport;
use Modules\AI\Services\ApplicationContent\Evaluation\ApplicationContentEvaluationCase;
use Modules\AI\Services\ApplicationContent\Evaluation\ApplicationContentEvaluationDataset;
use Modules\AI\Services\ApplicationContent\Evaluation\ApplicationContentRetrievalStrategyEvaluationService;
use Modules\AI\Services\ApplicationContent\Evaluation\Contracts\PerStrategyEngineRetrieverInterface;
use Modules\AI\Services\ApplicationContent\Evaluation\RerankerRun;
use Modules\Core\ApplicationContent\Contracts\ApplicationContentRetrievalProviderRegistryInterface;
use Modules\Core\ApplicationContent\Contracts\ProvidesPermissionModel;
use Modules\Core\ApplicationContent\Data\ApplicationContentSourceDescriptor;
use Modules\Core\Search\Contracts\ITextEmbedder;
use Modules\Core\Search\DTOs\AdvancedSearchResult;
use Nwidart\Modules\Facades\Module;
use Override;
use Throwable;

final class EvaluateApplicationContentRetrievalStrategiesCommand extends Command
{
    use WritesJsonReport;

    #[Override]
    protected $signature = 'test:ai:evaluate-retrieval-strategies
                            {--dataset= : Path to a generated evaluation dataset}
                            {--source= : Registered application content source}
                            {--output= : New JSON report path}
                            {--force : Replace an existing report}';

    #[Override]
    protected $description = 'Evaluate per-strategy retrieval ranking quality (keyword, vector, hybrid, fused, reranked) for a registered application content source against the real search engine <fg=magenta>(✨ Modules\\AI)</fg=magenta>';

    public function handle(
        ApplicationContentRetrievalProviderRegistryInterface $providers,
        ApplicationContentRetrievalStrategyEvaluationService $evaluation,
        PerStrategyEngineRetrieverInterface $retriever,
        ITextEmbedder $embedder,
        Filesystem $files,
    ): int {
        $dataset_path = $this->optionString('dataset');
        $source_option = $this->optionString('source');
        $output_path = $this->optionString('output');

        if ($dataset_path === null || $source_option === null || $output_path === null) {
            $this->error('The --dataset, --source, and --output options are required.');

            return self::FAILURE;
        }

        try {
            $source = ApplicationContentSourceDescriptor::normalizeSource($source_option);
            $provider = $providers->providerFor($source);
            $descriptor = $providers->descriptorFor($source);

            if ($provider === null
                || $descriptor === null
                || ! Module::isEnabled(Str::studly($descriptor->module))) {
                $this->error('The requested evaluation source is unavailable.');

                return self::FAILURE;
            }

            if (! $provider instanceof ProvidesPermissionModel) {
                $this->error('The requested evaluation source does not declare a permission model.');

                return self::FAILURE;
            }

            /** @var class-string<Model> $model_class */
            $model_class = $provider->permissionModel();
            $model = new $model_class();

            $output_issue = $this->outputPathIssue($files, $output_path);

            if ($output_issue !== null) {
                $this->error($output_issue);

                return self::FAILURE;
            }

            $dataset = ApplicationContentEvaluationDataset::fromFile($dataset_path);

            foreach ($dataset->cases as $case) {
                if (! in_array($case->locale, $descriptor->supportedLocales, true)) {
                    $this->error('The evaluation dataset requests an unsupported locale.');

                    return self::FAILURE;
                }
            }

            $driver = config('scout.driver', 'unknown');

            /** @var array<string, list<float>> $vectors */
            $vectors = [];
            $report = $evaluation->evaluate(
                $dataset,
                $source,
                is_string($driver) ? $driver : 'unknown',
                static function (ApplicationContentEvaluationCase $case, bool $useReranker) use (&$vectors, $embedder, $retriever, $model): AdvancedSearchResult {
                    $vectors[$case->id] ??= $embedder->embed($case->query);

                    return $retriever->retrieve($model, $case->query, $useReranker, $case->limit, $vectors[$case->id], $case->locale);
                },
            );
            $this->writeReport($files, $output_path, $report);

            $this->info(sprintf('Evaluated %d generated cases.', $report['case_count']));

            $reranker_warning = RerankerRun::warning($report);

            if ($reranker_warning !== null) {
                $this->warn($reranker_warning);
            }

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Application content retrieval strategy evaluation failed.');

            return self::FAILURE;
        }
    }
}

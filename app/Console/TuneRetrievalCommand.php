<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Console\Concerns\WritesJsonReport;
use Modules\AI\Services\ApplicationContent\Evaluation\ApplicationContentEvaluationCase;
use Modules\AI\Services\ApplicationContent\Evaluation\ApplicationContentEvaluationDataset;
use Modules\AI\Services\ApplicationContent\Evaluation\Contracts\PerStrategyEngineRetrieverInterface;
use Modules\AI\Services\ApplicationContent\Evaluation\RerankerRun;
use Modules\AI\Services\ApplicationContent\Evaluation\RetrievalTuningSafeguards;
use Modules\AI\Services\ApplicationContent\Evaluation\RetrievalTuningService;
use Modules\Core\ApplicationContent\Contracts\ApplicationContentRetrievalProviderRegistryInterface;
use Modules\Core\ApplicationContent\Contracts\ProvidesPermissionModel;
use Modules\Core\ApplicationContent\Data\ApplicationContentSourceDescriptor;
use Modules\Core\Search\Contracts\ITextEmbedder;
use Modules\Core\Search\DTOs\AdvancedSearchResult;
use Nwidart\Modules\Facades\Module;
use Override;
use Throwable;

/**
 * Grid-searches the fusion parameters of the retrieval tuning profile against a curated dataset
 * and writes a JSON report plus a printed profile block. It never edits `config/search_tuning.php`:
 * a human reads the numbers and commits the profile.
 */
final class TuneRetrievalCommand extends Command
{
    use WritesJsonReport;

    #[Override]
    protected $signature = 'test:ai:tune-retrieval
                            {--source= : Registered application content source}
                            {--dataset= : Path to a generated evaluation dataset}
                            {--grid=default : Built-in grid name, or a JSON file with a list of parameter sets}
                            {--metric=ndcg_at_5 : Metric that ranks the candidates}
                            {--holdout=0.3 : Share of the cases kept out of the selection to validate the winner (0 to 0.5, 0 turns it off)}
                            {--min-class-cases=8 : Selection cases a query class needs before it gets its own override}
                            {--class-margin=0.01 : How much a class override must beat the overall winner by (0 to 1)}
                            {--noise-margin=auto : Gain that counts as noise, from 0 to 1 (auto is one selection case, never below 0.01; 0 turns it off)}
                            {--output= : New JSON report path}
                            {--force : Replace an existing report}';

    #[Override]
    protected $description = 'Rank retrieval fusion parameter sets per query class against a curated dataset, re-fusing recorded per-strategy rankings, and print a profile block for config/search_tuning.php <fg=magenta>(✨ Modules\\AI)</fg=magenta>';

    public function handle(
        ApplicationContentRetrievalProviderRegistryInterface $providers,
        RetrievalTuningService $tuning,
        PerStrategyEngineRetrieverInterface $retriever,
        ITextEmbedder $embedder,
        Filesystem $files,
    ): int {
        $dataset_path = $this->optionString('dataset');
        $source_option = $this->optionString('source');
        $output_path = $this->optionString('output');
        $metric = $this->optionString('metric') ?? 'ndcg_at_5';

        if ($dataset_path === null || $source_option === null || $output_path === null) {
            $this->error('The --dataset, --source, and --output options are required.');

            return self::FAILURE;
        }

        if (! in_array($metric, RetrievalTuningService::metricNames(), true)) {
            $this->error('The requested metric is unknown.');

            return self::FAILURE;
        }

        $safeguards = $this->safeguards();

        if (! $safeguards instanceof RetrievalTuningSafeguards) {
            $this->error('The --holdout, --min-class-cases, --class-margin or --noise-margin option is out of range.');

            return self::FAILURE;
        }

        try {
            $source = ApplicationContentSourceDescriptor::normalizeSource($source_option);
            $provider = $providers->providerFor($source);
            $descriptor = $providers->descriptorFor($source);

            if ($provider === null
                || $descriptor === null
                || ! Module::isEnabled(Str::studly($descriptor->module))) {
                $this->error('The requested tuning source is unavailable.');

                return self::FAILURE;
            }

            if (! $provider instanceof ProvidesPermissionModel) {
                $this->error('The requested tuning source does not declare a permission model.');

                return self::FAILURE;
            }

            $grid = $this->grid($this->optionString('grid') ?? 'default', $files);

            if ($grid === null) {
                $this->error('The requested grid is unavailable.');

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

            /** @var array<string, list<float>> $vectors */
            $vectors = [];
            $report = $tuning->tune(
                $dataset,
                $source,
                $grid,
                $metric,
                static function (ApplicationContentEvaluationCase $case, bool $useReranker) use (&$vectors, $embedder, $retriever, $model): AdvancedSearchResult {
                    $vectors[$case->id] ??= $embedder->embed($case->query);

                    return $retriever->retrieve($model, $case->query, $useReranker, $case->limit, $vectors[$case->id], $case->locale);
                },
                $safeguards,
            );
            $report = $this->withContext($report, $model, $dataset_path);
            $this->writeReport($files, $output_path, $report);

            $this->info(sprintf('Ranked %d candidates by %s.', count($grid), $metric));
            $this->line('// test:ai:tune-retrieval report: ' . $output_path);
            $this->line($this->validationSummary($report));
            $this->line($this->noiseSummary($report));

            $reranker_warning = RerankerRun::warning($report);

            if ($reranker_warning !== null) {
                $this->warn($reranker_warning);
            }

            foreach (explode(PHP_EOL, $tuning->profileBlock($report)) as $line) {
                $this->line($line);
            }

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Retrieval tuning failed.');

            return self::FAILURE;
        }
    }

    /**
     * What the numbers were measured on. A profile holds for the embedding model whose vectors it was
     * tuned on and for a corpus of that kind, so a report that does not say which cannot tell a stale
     * profile from a current one. The fingerprint ties the report to a dataset that is never published:
     * whoever holds the file can check it is the one measured, nobody else sees it.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function withContext(array $report, Model $model, string $dataset_path): array
    {
        $active = app(EmbeddingModelRegistry::class)->active();

        // The population the index holds, not query(): a global scope such as LocaleScope hides rows.
        $corpus = method_exists($model, 'makeAllSearchableQuery') ? $model::makeAllSearchableQuery() : $model::query();

        $dataset = is_array($report['dataset'] ?? null) ? $report['dataset'] : [];
        $report['dataset'] = [...$dataset, 'sha256' => hash_file('sha256', $dataset_path)];
        $report['embedding'] = [
            'profile' => $active->key,
            'service_model' => $active->serviceModel,
            'dimensions' => $active->dimensions,
        ];
        $report['corpus'] = ['size' => $corpus->count()];

        return $report;
    }

    /**
     * The recommended safeguards, overridden by the options; null when an option is out of range.
     */
    private function safeguards(): ?RetrievalTuningSafeguards
    {
        $recommended = RetrievalTuningSafeguards::recommended();
        $holdout = $this->numericOption('holdout', $recommended->holdoutFraction);
        $min_class_cases = $this->numericOption('min-class-cases', (float) $recommended->minClassCases);
        $margin = $this->numericOption('class-margin', $recommended->classMargin);

        $noise = $this->option('noise-margin');
        $automatic_noise = $noise === null || (is_string($noise) && in_array(mb_strtolower(mb_trim($noise)), ['', 'auto'], true));

        if ($holdout === null || $min_class_cases === null || $margin === null || $min_class_cases !== floor($min_class_cases)) {
            return null;
        }

        if (! $automatic_noise && ! is_numeric($noise)) {
            return null;
        }

        try {
            return new RetrievalTuningSafeguards($holdout, (int) $min_class_cases, $margin, $automatic_noise ? null : (float) $noise);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function numericOption(string $name, float $default): ?float
    {
        $value = $this->option($name);

        if ($value === null || (is_string($value) && mb_trim($value) === '')) {
            return $default;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function validationSummary(array $report): string
    {
        $validation = is_array($report['validation'] ?? null) ? $report['validation'] : [];

        return match ($validation['status'] ?? null) {
            'passed', 'failed' => sprintf(
                '// Validation on %d held-out cases: %s (winner %s, committed %s, delta %s).',
                (int) ($validation['held_out_cases'] ?? 0),
                (string) $validation['status'],
                (string) ($validation['winner_metric'] ?? '?'),
                (string) ($validation['committed_metric'] ?? '?'),
                (string) ($validation['delta'] ?? '?'),
            ),
            'skipped' => '// Validation skipped: ' . (string) ($validation['reason'] ?? 'no held-out cases') . '. The profile below is not validated on held-out cases.',
            default => '// Validation off (--holdout=0). The profile below is not validated on held-out cases.',
        };
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function noiseSummary(array $report): string
    {
        $noise = is_array($report['noise'] ?? null) ? $report['noise'] : [];

        return match ($noise['status'] ?? null) {
            'passed' => sprintf(
                '// Noise check: the winner beats the committed profile by %s on %d selection cases, over the %s margin.',
                (string) ($noise['gain'] ?? '?'),
                (int) ($noise['selection_cases'] ?? 0),
                (string) ($noise['margin'] ?? '?'),
            ),
            'within_noise' => sprintf(
                '// Noise check: the best candidate beats the committed profile by %s on %d selection cases, no more than the %s margin: no winner.',
                (string) ($noise['gain'] ?? '?'),
                (int) ($noise['selection_cases'] ?? 0),
                (string) ($noise['margin'] ?? '?'),
            ),
            default => '// Noise check off (--noise-margin=0). The profile below is not checked against noise.',
        };
    }

    /**
     * @return list<array<string, int|float>>|null
     */
    private function grid(string $option, Filesystem $files): ?array
    {
        if (! str_ends_with(mb_strtolower($option), '.json')) {
            try {
                return RetrievalTuningService::namedGrid($option);
            } catch (InvalidArgumentException) {
                return null;
            }
        }

        if (! $files->isFile($option) || $files->size($option) > 1_000_000) {
            return null;
        }

        $decoded = json_decode($files->get($option), true);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return null;
        }

        foreach ($decoded as $candidate) {
            if (! is_array($candidate)) {
                return null;
            }
        }

        /** @var list<array<string, int|float>> $decoded */
        return $decoded;
    }
}

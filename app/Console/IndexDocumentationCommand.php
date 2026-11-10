<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use function ai_config_bool;

use Closure;
use Exception;
use Illuminate\Console\Command;
use Laravel\Prompts\Progress;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Ai\Rag\FaqVectorStoreConfig;
use Modules\AI\Console\Concerns\ResolvesDocumentationProfiles;
use Modules\AI\Services\DocumentationService;
use Override;

final class IndexDocumentationCommand extends Command
{
    use ResolvesDocumentationProfiles;

    /**
     * The sources a dry run names under each heading; the line above them has the counts.
     */
    private const int DRY_RUN_LISTED = 20;

    #[Override]
    protected $signature = 'ai:index-rag-docs
                            {--path= : Scan only this file or directory (omit to index roots returned by rag_paths())}
                            {--profile=developer : Index profile: developer, user, or all}
                            {--full : Rebuild every source of the selected documentation; on Elasticsearch each source is replaced once its new chunks are embedded, and the sources the documentation no longer has are removed at the end}
                            {--diff : Index only the sources that are new or changed since the last run, and remove the ones the documentation no longer has (Elasticsearch store)}
                            {--dry-run : With --diff, list what would be written and removed, without embedding, writing or removing anything}';

    #[Override]
    protected $description = 'Index documentation for FAQ/RAG <fg=magenta>(✨ Modules\AI)</fg=magenta>';

    /**
     * The progress bar of the profile being indexed, while it is on screen.
     *
     * @var Progress<int>|null
     */
    private ?Progress $bar = null;

    /**
     * Index documentation for FAQ/RAG
     * when --path is omitted, roots come from rag_paths(). Incremental runs use reindex-by-source to avoid duplicate chunks unless --full is passed.
     */
    public function handle(DocumentationService $documentationService): int
    {
        if (! ai_config_bool('ai.features.faq.enabled', true)) {
            $this->warn('FAQ/RAG is disabled in config (ai.features.faq.enabled).');

            return self::FAILURE;
        }

        $path_option = $this->option('path');
        $path = is_string($path_option) && $path_option !== '' ? $path_option : null;
        $full = (bool) $this->option('full');
        $diff = (bool) $this->option('diff');
        $dry_run = (bool) $this->option('dry-run');
        $profiles = $this->documentationProfiles();

        if ($profiles === null) {
            return self::FAILURE;
        }

        if ($path !== null && (! is_dir($path) && ! is_file($path))) {
            $this->error("Path does not exist or is not readable: {$path}");

            return self::FAILURE;
        }

        if ($dry_run && ! $diff) {
            $this->error('--dry-run belongs to --diff: add --diff to see what a run would write and remove.');

            return self::FAILURE;
        }

        if ($full && $diff) {
            $this->error('--full and --diff exclude each other: --full rewrites every source, --diff only those that changed.');

            return self::FAILURE;
        }

        if ($full && FaqVectorStoreConfig::driver() === 'memory') {
            $this->comment('Vector store driver is "memory": --full resets the in-process shared store only.');
        }

        try {
            if ($diff) {
                foreach ($profiles as $profile) {
                    $this->reportDiff($profile, $documentationService->syncDocuments($path, $profile, $this->progressReporter($profile), $dry_run));
                }

                return self::SUCCESS;
            }

            $count = 0;

            foreach ($profiles as $profile) {
                $count += $documentationService->indexDocuments($path, $full, $profile, $this->progressReporter($profile));
            }

            $this->info("Indexed {$count} document chunks.");

            return self::SUCCESS;
        } catch (Exception $e) {
            $this->failProgressBar();
            $this->error('Indexing failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * What a diff run did to the index of one profile, in a line; a dry run also lists the sources it would
     * write and remove.
     *
     * @param  array{sources: int, changed: int, unchanged: int, removed: int, chunks: int, to_write: list<string>, to_remove: list<string>, dry_run: bool}  $report
     */
    private function reportDiff(DocumentationIndexProfile $profile, array $report): void
    {
        if (! $report['dry_run']) {
            $this->info(sprintf(
                'The %s documentation: %d of %d sources written (%d chunks), %d unchanged, %d removed from the index.',
                $profile->value,
                $report['changed'],
                $report['sources'],
                $report['chunks'],
                $report['unchanged'],
                $report['removed'],
            ));

            return;
        }

        $this->info(sprintf(
            'The %s documentation, dry run (nothing was embedded, written or removed): %d of %d sources would be written (%d chunks), %d are unchanged, %d would be removed.',
            $profile->value,
            $report['changed'],
            $report['sources'],
            $report['chunks'],
            $report['unchanged'],
            $report['removed'],
        ));

        foreach (['to_write' => 'Would write', 'to_remove' => 'Would remove'] as $key => $label) {
            foreach (array_slice($report[$key], 0, self::DRY_RUN_LISTED) as $source) {
                $this->line(sprintf('  %s %s', $label, $source));
            }

            if (count($report[$key]) > self::DRY_RUN_LISTED) {
                $this->line(sprintf('  ... and %d more', count($report[$key]) - self::DRY_RUN_LISTED));
            }
        }
    }

    /**
     * The callback that shows where the indexing is: a Laravel Prompts progress bar over the chunks, with
     * the file being written as hint, or one plain line per batch when the console is not interactive
     * (a pipe, a log, `--no-interaction`), where a bar would only write control codes.
     *
     * @return Closure(int, int, list<string>): void
     */
    private function progressReporter(DocumentationIndexProfile $profile): Closure
    {
        $label = sprintf('Indexing the %s documentation', $profile->value);

        return function (int $done, int $total, array $sources) use ($label): void {
            if (! $this->input->isInteractive()) {
                if ($sources !== []) {
                    $this->line(sprintf('%s: %d/%d chunks, %s', $label, $done, $total, $this->describeSources($sources)));
                }

                return;
            }

            if (! $this->bar instanceof Progress) {
                $this->bar = new Progress($label, $total);
                $this->bar->start();
            }

            $this->bar->hint($this->describeSources($sources));
            $this->bar->advance($done - $this->bar->progress);

            if ($done >= $total) {
                $this->bar->finish();
                $this->bar = null;
            }
        };
    }

    /**
     * Leaves the bar that was running when the indexing failed drawn in the error state, so the failure
     * message below it reads as the end of that bar and not as an interruption.
     */
    private function failProgressBar(): void
    {
        if (! $this->bar instanceof Progress) {
            return;
        }

        $this->bar->state = 'error';
        $this->bar->render();
        $this->bar = null;
    }

    /**
     * The sources of a batch in a line: the first two, and how many more follow.
     *
     * @param  list<string>  $sources
     */
    private function describeSources(array $sources): string
    {
        if ($sources === []) {
            return '';
        }

        $line = implode(', ', array_slice($sources, 0, 2));

        return count($sources) > 2 ? sprintf('%s (+%d more)', $line, count($sources) - 2) : $line;
    }
}

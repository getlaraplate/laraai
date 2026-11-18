<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use Modules\AI\Ai\Embeddings\EmbeddingDimensionMismatch;
use Modules\AI\Ai\Embeddings\EmbeddingInspection;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\EmbeddingServerIdentity;
use Modules\AI\Ai\SelfHosted\ServiceRequestId;
use Modules\AI\Contracts\IEmbeddableModels;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\AI\Services\EmbeddingsGate;
use Modules\Core\Models\ModelEmbedding;
use Modules\Core\Search\Traits\SearchableCommandUtils;
use Override;
use Throwable;

/**
 * Repair sweep for documents whose embedding is missing or stale.
 *
 * By default, targets records with no ModelEmbedding row at all: a permanent
 * embed failure degrades the document to keyword-only indexing (see
 * GenerateEmbeddingsJob::failed()), leaving no embedding row. With --stale,
 * targets records whose embeddings were produced by a different model_key
 * than the active profile (e.g. after changing the embedding model).
 *
 * Either way, regeneration dispatches GenerateEmbeddingsJob with locale=null,
 * which performs a full per-locale regenerate (all locales, stamping the
 * active model_key) — this command does not stamp model_key itself.
 *
 * Before it scans, it probes the embeddings server of the active profile: a POST to /v1/embeddings
 * with a probe text must answer with a vector of the profile's dimensions (and of norm 1 when the profile
 * normalises), or the run aborts before dispatching anything. The model the answer names, and the one
 * GET /info names where the server has that route, must be the active profile's service_model, or it aborts
 * too; a server that names none is a warning. Records the search index would not hold
 * (shouldBeSearchable() false, e.g. media drafts) are skipped.
 */
final class RepairMissingEmbeddingsCommand extends Command
{
    use SearchableCommandUtils;

    #[Override]
    protected $signature = 'ai:embeddings:repair
                            {model? : Fully qualified class name of the searchable model to repair}
                            {--chunk=100 : Number of records to scan per batch}
                            {--all : Repair every embeddable searchable model instead of one (for scheduled runs)}
                            {--if-idle : Skip the run while embeddings jobs are still queued, so a backlog is not dispatched twice}
                            {--sync : Generate embeddings synchronously instead of queuing}
                            {--stale : Also target records that have embeddings but none produced by the active profile (full per-locale regenerate; rows of other models are kept)}';

    #[Override]
    protected $description = 'Regenerate embeddings for searchable records that are missing them or stale (embedded, but by no row of the active model_key); cross-checks the embedding service /health against the active model <fg=magenta>(✨ Modules\AI)</fg=magenta>';

    public function handle(EmbeddingModelRegistry $registry, IEmbeddableModels $embeddable_models, EmbeddingsGate $gate): int
    {
        $all = (bool) $this->option('all');

        if ($all && $this->argument('model') !== null) {
            $this->error('Pass either a model or --all, not both.');

            return Command::INVALID;
        }

        // A scheduled run must not dispatch again what a previous run left in the queue: a record
        // keeps lacking its embedding until its job has run, so a backlog would be queued twice.
        if ((bool) $this->option('if-idle') && Queue::size('embeddings') > 0) {
            $this->info('Embeddings jobs are still queued: skipping this run so the backlog is not dispatched twice.');

            return self::SUCCESS;
        }

        if ($all) {
            if (! $gate->enabled()) {
                $this->info('The embeddings feature is disabled: nothing to repair.');

                return self::SUCCESS;
            }

            $models = $embeddable_models->all();

            if ($models === []) {
                $this->info('No embeddable model to repair.');

                return self::SUCCESS;
            }
        } else {
            $model_class = $this->getModelClass();

            if (in_array($model_class, ['', '0', false], true)) {
                return Command::INVALID;
            }

            if (! $this->isRepairable(new $model_class())) {
                $this->error("Model {$model_class} is not a searchable embeddable model with vector search enabled");

                return self::FAILURE;
            }

            $models = [$model_class];
        }

        $active = $registry->active();

        // A hosted provider has no server of ours to probe; only the openai_compatible profiles run on one.
        if ($active->provider === 'openai_compatible' && ! $this->verifyService($active)) {
            return self::FAILURE;
        }

        $failed = false;

        foreach ($models as $model_class) {
            if (! $this->repair($model_class, $active)) {
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Scans one model and regenerates what is missing or stale; false when a sync run failed on a record.
     *
     * @param  class-string<Model>  $model_class
     */
    private function repair(string $model_class, EmbeddingModelProfile $active): bool
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $sync = (bool) $this->option('sync');
        $stale = (bool) $this->option('stale');

        // The population `scout:import` indexes, not `query()`: a global scope such as
        // `LocaleScope` hides content with no translation in the current locale, which
        // would then never get an embedding.
        $query = $model_class::makeAllSearchableQuery();

        if ($stale) {
            $this->info("Scanning {$model_class} for records with embeddings stale against model_key \"{$active->key}\"...");

            // Embedded, but by no row of the active key. Rows of other models are kept until the
            // switch is activated, so a record with an active-key row is not stale however many
            // of them it keeps; selecting on "any non-active row" never converged.
            $query->whereHas('embeddings')
                ->whereDoesntHave('embeddings', function (Builder $embeddings) use ($active): void {
                    /** @var Builder<ModelEmbedding> $embeddings */
                    $embeddings->producedBy($active->key);
                });
        } else {
            $this->info("Scanning {$model_class} for records with missing embeddings...");

            $query->whereDoesntHave('embeddings');
        }

        $dispatched = 0;
        $failures = [];

        $query
            ->lazyById($chunk, $model_class::query()->getModel()->getKeyName())
            ->each(function (Model $model) use (&$dispatched, &$failures, $sync): void {
                // Skip records the search index would not hold: an embedding nobody indexes is wasted.
                if (method_exists($model, 'shouldBeSearchable') && ! $model->shouldBeSearchable()) {
                    return;
                }

                // Skip records that carry no embeddable text.
                $data = $model->prepareDataToEmbed();

                if ($data === null || $data === '') {
                    return;
                }

                // locale=null triggers a full per-locale regenerate stamping
                // the active model_key (GenerateEmbeddingsJob::handle()).
                if ($sync) {
                    try {
                        dispatch_sync(new GenerateEmbeddingsJob($model)->unthrottled());
                    } catch (Throwable $exception) {
                        $failures[$model->getKey()] = $exception->getMessage();
                        $this->warn('Embedding failed for ' . $model::class . " #{$model->getKey()}: {$exception->getMessage()}");

                        return;
                    }
                } else {
                    dispatch(new GenerateEmbeddingsJob($model));
                }

                $dispatched++;
            });

        $this->info("Embedding regeneration dispatched for {$dispatched} record(s) of {$model_class}");

        if ($failures !== []) {
            $this->error(count($failures) . " record(s) of {$model_class} failed, no embedding was stored for them.");

            return false;
        }

        return true;
    }

    /**
     * The preflight checks, before anything is dispatched. The server must embed (see
     * {@see self::inspectServer()}), must run the model the active profile stamps on the vectors and must return
     * vectors of the scale the profile declares: embeddings of another model stored under the profile's key
     * look right, are invisible to --stale, and quietly degrade the semantic search. The model the answer to the
     * probe names is authoritative, because a multi-model server answers for the model the request asked for;
     * `/info`, where the server has it, must agree. A server that names no model cannot be checked: a
     * warning, not an abort.
     */
    private function verifyService(EmbeddingModelProfile $active): bool
    {
        $inspection = $this->inspectServer(app(EmbeddingServerIdentity::class), $active);

        if (! $inspection instanceof EmbeddingInspection) {
            return false;
        }

        if ($inspection->reportedModel() === null) {
            $this->warn("The embedding server does not report which model it runs, so its vectors cannot be checked against the active profile \"{$active->key}\" ({$active->serviceModel}).");

            return true;
        }

        $conflict = $inspection->modelConflict($active);

        if ($conflict !== null) {
            $this->error(Str::ucfirst($conflict) . ': its vectors would be stored under the wrong model. Nothing was dispatched.');

            return false;
        }

        $norm = $inspection->normProblem($active);

        if ($norm !== null) {
            $this->error(Str::ucfirst($norm) . '. Nothing was dispatched.');

            return false;
        }

        return true;
    }

    /**
     * Embeds a probe text the way the jobs do, so a server that is up but cannot embed (a model that failed to
     * load, a request it rejects) stops the run instead of silently losing every job. The answer must carry a
     * vector of the active profile's dimensions.
     *
     * @return EmbeddingInspection|null null when the probe failed (the reason is printed)
     */
    private function inspectServer(EmbeddingServerIdentity $identity, EmbeddingModelProfile $active): ?EmbeddingInspection
    {
        $endpoint = ServiceRequestId::endpointOf($identity->server($active)->url . '/v1/embeddings');

        try {
            return $identity->inspect($active);
        } catch (EmbeddingDimensionMismatch $mismatch) {
            return $this->probeFailed($endpoint, $mismatch->measured === null
                ? 'the answer carries no embedding'
                : "it returned {$mismatch->measured} dimensions, the active profile \"{$active->key}\" expects {$active->dimensions}");
        } catch (Throwable $exception) {
            return $this->probeFailed($endpoint, Str::limit($exception->getMessage(), 200));
        }
    }

    private function probeFailed(string $endpoint, string $reason): null
    {
        $this->error("Embedding server probe failed at {$endpoint}: {$reason}. Nothing was dispatched.");

        return null;
    }

    private function isRepairable(object $instance): bool
    {
        return $instance instanceof Model
            && in_array(Searchable::class, class_uses_recursive($instance::class), true)
            && method_exists($instance, 'isEmbeddable')
            && method_exists($instance, 'prepareDataToEmbed')
            && $instance->isEmbeddable();
    }
}

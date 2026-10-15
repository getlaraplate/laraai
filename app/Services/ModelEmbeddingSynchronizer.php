<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\Embeddings\EmbeddingDimensionMismatch;
use Modules\AI\Ai\Embeddings\EmbeddingDimensionProbe;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\Core\Events\ModelPreProcessingCompleted;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Models\Concerns\HasTranslations;
use Modules\Core\Models\ModelEmbedding;
use Modules\Core\Search\Contracts\IProfileVectorIndex;
use NeuronAI\RAG\Document;

/**
 * Generates and stores per-(model, locale) embeddings with skip-if-fresh
 * semantics: a locale whose text (content hash) and embedding model (model_key)
 * are both unchanged is kept, only changed or model-stale locales are replaced,
 * and locales that no longer exist are dropped. Extracted from
 * {@see \Modules\AI\Jobs\GenerateEmbeddingsJob} so the single-model job and the
 * bulk indexing path share one store implementation and cannot diverge.
 */
final readonly class ModelEmbeddingSynchronizer
{
    public function __construct(
        private IEmbeddingService $embeddingService,
        private EmbeddingModelRegistry $registry,
    ) {}

    /**
     * The `content_hash` stored with the rows of an embedded text: a row whose hash and model key
     * match the current text is fresh.
     */
    public static function contentHash(string $text): string
    {
        return hash('sha256', $text);
    }

    /**
     * The `locale` stored with the rows of a locale's text: null for the default locale of a model
     * that is not translated, the locale otherwise.
     */
    public static function rowLocale(string $locale, string $defaultLocale, bool $isTranslated): ?string
    {
        return ($locale === $defaultLocale && ! $isTranslated) ? null : $locale;
    }

    /**
     * @param  iterable<Model>  $models
     * @param  bool  $announceCompletion  When true (per-model path) each stored
     *                                    model emits {@see ModelPreProcessingCompleted}
     *                                    so the finalize listener indexes it.
     *                                    The bulk path passes false because it
     *                                    writes the engine itself in one batch,
     *                                    and the completion event would trigger
     *                                    a redundant per-model index.
     * @param  bool  $reload  When true (per-model path) each model is reloaded with
     *                        fresh(), because a queued job may carry a stale snapshot.
     *                        The bulk path passes false: its models come straight from
     *                        the import query with embeddings (and translations)
     *                        eager-loaded, so reloading would re-query per model and
     *                        discard those eager loads.
     */
    public function sync(iterable $models, ?string $locale = null, bool $announceCompletion = true, bool $reload = true): void
    {
        $model_key = $this->registry->active()->key;
        $default_locale = LocaleContext::getDefault();

        // Pass 1: plan the stale (model, locale) work and collect every text
        // that must be embedded across all models.
        $plans = [];
        $texts = [];
        $text_index_by_hash = [];

        foreach ($models as $model) {
            $fresh = $reload ? ($model->fresh() ?? $model) : $model;

            if (! $this->isEmbeddable($fresh)) {
                continue;
            }

            $plan = $this->planModel($fresh, $locale, $model_key, $default_locale, $texts, $text_index_by_hash);

            if ($plan !== null) {
                $plans[] = $plan;
            }
        }

        // Pass 2: one batched (adaptive) embedding call for all stale texts.
        $embedded = $texts === [] ? [] : $this->embeddingService->embedDocumentsBatch($texts);

        $this->ensureProfileIndex($plans);

        // Pass 3: persist per model, from the shared batch result.
        foreach ($plans as $plan) {
            $this->writePlan($plan, $model_key, $embedded, $announceCompletion);
        }
    }

    /**
     * On pgvector the rows of a profile are searched through its own partial index, so it exists
     * before the first row of the profile is written (once per process and key).
     *
     * @param  list<array{stale: list<mixed>}>  $plans
     */
    private function ensureProfileIndex(array $plans): void
    {
        if (array_filter($plans, static fn (array $plan): bool => $plan['stale'] !== []) === []) {
            return;
        }

        $indexes = app(IProfileVectorIndex::class);
        $connection = new ModelEmbedding()->getConnection();

        if (! $indexes->supports($connection)) {
            return;
        }

        $profile = $this->registry->active();
        $indexes->ensureOnce($connection, $profile->key, $profile->dimensions, $profile->similarity);
    }

    /**
     * A stale text is embedded at most once: its vectors are copied from any other model
     * already embedded with the same text and embedding model (a duplicated media file, the
     * same caption twice), or shared with an identical text queued earlier in this run. Each
     * model still gets its own rows, so every copy stays an autonomous search result.
     *
     * @param  list<string>  $texts
     * @param  array<string, int>  $text_index_by_hash
     * @return array{model: Model, locale: string|null, stale: list<array{row_locale: string|null, content_hash: string, text_index: int|null, vectors: list<mixed>|null}>, processed_locales: list<string|null>}|null
     */
    private function planModel(Model $model, ?string $locale, string $model_key, string $default_locale, array &$texts, array &$text_index_by_hash): ?array
    {
        /** @phpstan-ignore method.notFound */
        $by_locale = $model->prepareDataToEmbedByLocale($locale);

        if ($by_locale === []) {
            return null;
        }

        $is_translated = class_uses_trait($model, HasTranslations::class);

        // Reuse the eager-loaded embeddings relation (bulk path) when present,
        // filtering in memory; only query per model when it is not loaded
        // (per-model path). The bulk path always passes $locale === null.
        if ($locale === null && $model->relationLoaded('embeddings')) {
            /** @var \Illuminate\Support\Collection<int, ModelEmbedding> $existing */
            $existing = $model->getRelation('embeddings');
        } else {
            $existing = ($locale === null
                /** @phpstan-ignore method.notFound */
                ? $model->embeddings()
                /** @phpstan-ignore method.notFound */
                : $model->embeddings()->forLocale($locale)
            )->get();
        }

        $stale = [];
        $processed_locales = [];

        foreach ($by_locale as $loc => $text) {
            $row_locale = self::rowLocale((string) $loc, $default_locale, $is_translated);
            $processed_locales[] = $row_locale;
            $content_hash = self::contentHash($text);

            $is_fresh = $existing->first(static fn (ModelEmbedding $row): bool => $row->locale === $row_locale
                && $row->model_key === $model_key
                && $row->content_hash === $content_hash) !== null;

            if ($is_fresh) {
                continue;
            }

            $vectors = $this->reusableVectors($model, $content_hash, $model_key);

            if ($vectors !== null) {
                $stale[] = ['row_locale' => $row_locale, 'content_hash' => $content_hash, 'text_index' => null, 'vectors' => $vectors];

                continue;
            }

            if (! array_key_exists($content_hash, $text_index_by_hash)) {
                $text_index_by_hash[$content_hash] = count($texts);
                $texts[] = $text;
            }

            $stale[] = ['row_locale' => $row_locale, 'content_hash' => $content_hash, 'text_index' => $text_index_by_hash[$content_hash], 'vectors' => null];
        }

        return ['model' => $model, 'locale' => $locale, 'stale' => $stale, 'processed_locales' => $processed_locales, 'existing' => $existing];
    }

    /**
     * @param  array{model: Model, locale: string|null, stale: list<array{row_locale: string|null, content_hash: string, text_index: int|null, vectors: list<mixed>|null}>, processed_locales: list<string|null>, existing: \Illuminate\Support\Collection<int, ModelEmbedding>}  $plan
     * @param  list<Document[]>  $embedded
     *
     * @throws EmbeddingDimensionMismatch when a vector's length differs from the active profile's dimensions
     */
    private function writePlan(array $plan, string $model_key, array $embedded, bool $announceCompletion): void
    {
        $model = $plan['model'];

        try {
            $profile = $this->registry->active();

            foreach ($plan['stale'] as $item) {
                $vectors = $item['vectors'] ?? array_map(
                    static fn (Document $document): mixed => $document->embedding,
                    $embedded[$item['text_index']],
                );

                // Checked before anything is deleted: a vector of the wrong length would be
                // rejected by the vector column or, worse, stored and never matched.
                foreach ($vectors as $vector) {
                    EmbeddingDimensionProbe::assertDimensions($profile, $vector);
                }

                /** @phpstan-ignore method.notFound */
                $model->embeddings()->forLocale($item['row_locale'])
                    ->where(fn ($query) => $this->scopeToOwnedRows($query, $model_key))
                    ->delete();

                foreach ($vectors as $vector) {
                    /** @phpstan-ignore method.notFound */
                    $model->embeddings()->create([
                        'embedding' => $vector,
                        'locale' => $item['row_locale'],
                        'model_key' => $model_key,
                        'content_hash' => $item['content_hash'],
                    ]);
                }
            }

            if ($plan['locale'] === null) {
                // Orphan locales (present in DB but no longer in the plan) are found
                // from the pre-write snapshot: rows for stale locales are in
                // processed_locales and were already replaced above, so rejecting
                // processed locales leaves exactly the orphans to drop.
                $plan['existing']
                    ->reject(fn (ModelEmbedding $row): bool => in_array($row->locale, $plan['processed_locales'], true)
                        || ! $this->ownsRow($row, $model_key))
                    ->each(static fn (ModelEmbedding $row) => $row->delete());
            }

            // The bulk path serializes each model right after this via
            // toSearchableArray(), which prefers the loaded relation: refresh it so
            // a re-embedded model is indexed with its new vectors, not the stale
            // snapshot planModel() read.
            if ($plan['stale'] !== [] && $model->relationLoaded('embeddings')) {
                /** @phpstan-ignore method.notFound */
                $model->load('embeddings');
            }

            if ($announceCompletion) {
                event(new ModelPreProcessingCompleted($model, 'embeddings'));
            }
        } catch (Exception $exception) {
            Log::error('Embedding generation failed for model: ' . $model::class, [
                'model_id' => $model->getKey(),
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }
    }

    /**
     * Rows a run for `$model_key` may replace or drop: its own, and those of a model that is
     * no longer a profile. The rows of another configured profile are left alone, so a run for
     * one profile does not remove the vectors another profile is still serving.
     */
    private function ownsRow(ModelEmbedding $row, string $model_key): bool
    {
        return $row->model_key === $model_key || ! in_array($row->model_key, $this->registry->keys(), true);
    }

    private function scopeToOwnedRows(\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation $query, string $model_key): void
    {
        $query->where('model_key', $model_key)
            ->orWhereNull('model_key')
            ->orWhereNotIn('model_key', $this->registry->keys());
    }

    private function isEmbeddable(Model $model): bool
    {
        return is_callable([$model, 'prepareDataToEmbed'])
            && is_callable([$model, 'embeddings']);
    }

    /**
     * Vectors of the same text embedded by the same embedding model for another model, taken
     * from one source (model and locale) in chunk order; null when there is none.
     *
     * @return list<mixed>|null
     */
    private function reusableVectors(Model $model, string $content_hash, string $model_key): ?array
    {
        $source = ModelEmbedding::query()
            ->where('content_hash', $content_hash)
            ->where('model_key', $model_key)
            ->where(static function ($query) use ($model): void {
                $query->where('model_type', '!=', $model->getMorphClass())
                    ->orWhere('model_id', '!=', $model->getKey());
            })
            ->orderBy('id')
            ->first();

        if (! $source instanceof ModelEmbedding) {
            return null;
        }

        return ModelEmbedding::query()
            ->where('model_type', $source->model_type)
            ->where('model_id', $source->model_id)
            ->where('locale', $source->locale)
            ->where('content_hash', $content_hash)
            ->where('model_key', $model_key)
            ->orderBy('id')
            ->get()
            ->map(static fn (ModelEmbedding $row): mixed => $row->embedding)
            ->values()
            ->all();
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings\Switching;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\EmbeddingServerIdentity;
use Modules\AI\Ai\SelfHosted\AiServers;
use Modules\AI\Contracts\IEmbeddableModels;
use Modules\Core\Models\Concerns\HasTranslations;
use Throwable;

/**
 * Computes what a switch to a target profile would cost: the two models and their dimensions, the
 * records to embed and a rough time. It only counts rows and, for the openai_compatible provider
 * only, probes the target once with a short timeout (the latency is cached), so the settings page
 * can call it on every confirmation. A hosted provider gets no time estimate.
 */
final readonly class EmbeddingSwitchPreview
{
    /**
     * Request timeout of the latency measurement: the settings page waits for it.
     */
    public const int LATENCY_TIMEOUT_SECONDS = 3;

    private const int LATENCY_CACHE_SECONDS = 600;

    private const int FAILED_LATENCY_CACHE_SECONDS = 30;

    public function __construct(
        private EmbeddingModelRegistry $registry,
        private IEmbeddableModels $embeddableModels,
        private EmbeddingServerIdentity $identity = new EmbeddingServerIdentity(),
        private AiServers $servers = new AiServers(),
    ) {}

    public function for(EmbeddingModelProfile $target): SwitchPreview
    {
        $currentKey = $this->registry->activeKey();

        try {
            $currentDimensions = $this->registry->get($currentKey)->dimensions;
        } catch (InvalidArgumentException) {
            $currentDimensions = null;
        }

        $records = $this->recordsToEmbed();

        return new SwitchPreview(
            currentModel: $currentKey,
            targetModel: $target->key,
            currentDimensions: $currentDimensions,
            targetDimensions: $target->dimensions,
            dimensionsDiffer: $currentDimensions !== $target->dimensions,
            recordsToEmbed: $records,
            estimatedSeconds: $this->estimateSeconds($target, $records),
        );
    }

    /**
     * The texts a full re-embed produces: for each embeddable model, its searchable records times
     * their translations (the translation rows of those records; one per record when the model is
     * not translated). Records the index would skip one by one (`shouldBeSearchable()`) are counted.
     */
    public function recordsToEmbed(): int
    {
        $total = 0;

        foreach ($this->embeddableModels->all() as $modelClass) {
            $total += $this->countFor($modelClass);
        }

        return $total;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function countFor(string $modelClass): int
    {
        /** @var Model $instance */
        $instance = new $modelClass();
        $searchable = $modelClass::makeAllSearchableQuery();

        if (! class_uses_trait($instance, HasTranslations::class)) {
            return $searchable->count();
        }

        $translations = $instance->translations();

        return $translations->getRelated()->newQuery()
            ->whereIn($translations->getForeignKeyName(), $searchable->select($instance->qualifyColumn($translations->getLocalKeyName())))
            ->count();
    }

    /**
     * Probe latency times the number of batches the records make, or null when it was not measured.
     */
    private function estimateSeconds(EmbeddingModelProfile $target, int $records): ?int
    {
        $latency = $this->latency($target);

        if ($latency === null) {
            return null;
        }

        $batchSize = $this->servers->forProfile($target)->batchSize;

        return (int) ceil($latency * (int) ceil($records / $batchSize));
    }

    /**
     * Seconds one call to the embeddings server takes, measured with a short timeout of
     * its own and cached (a success for ten minutes, a failure for thirty seconds), so a confirmation
     * shown twice does not call the service twice. Any other provider is not measured: its client has
     * no per-call timeout, and the settings page must not wait on it.
     */
    private function latency(EmbeddingModelProfile $target): ?float
    {
        if ($target->provider !== 'openai_compatible') {
            return null;
        }

        $cacheKey = 'ai:embeddings:switch:latency:' . $target->key;

        /** @var array{seconds: float|null}|null $cached */
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached['seconds'];
        }

        $started = hrtime(true);

        try {
            $this->identity->inspect($target, self::LATENCY_TIMEOUT_SECONDS, withInfo: false);

            $seconds = (hrtime(true) - $started) / 1e9;
        } catch (Throwable) {
            $seconds = null;
        }

        Cache::put($cacheKey, ['seconds' => $seconds], $seconds === null ? self::FAILED_LATENCY_CACHE_SECONDS : self::LATENCY_CACHE_SECONDS);

        return $seconds;
    }
}

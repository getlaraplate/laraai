<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings\Switching;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\Core\Models\ModelEmbedding;
use Modules\Core\Models\Setting;
use Modules\Core\Search\Contracts\IProfileVectorIndex;
use Modules\Core\Search\Services\VectorSearchAvailability;
use Throwable;

/**
 * The `activate` phase of an embedding model switch, in one step: the target becomes the model
 * that serves search (`features.embeddings.serving_model`, and the chosen model
 * `features.embeddings.model`), the switch is idle again, which resumes vector search, and the rows
 * of every other model are deleted. Nothing is written under Core's settings: the vector profile is
 * the serving model's. Repeating it after a failure halfway is harmless: the settings are written in
 * one transaction with the same values, and the later steps are idempotent. The state stays
 * `running` until the settings are written, so jobs that run meanwhile still embed with the target
 * and vector search stays suspended.
 */
final readonly class EmbeddingSwitchActivation
{
    private const string SERVING_MODEL_SETTING = 'features.embeddings.serving_model';

    /**
     * The settings written together.
     */
    private const array SETTINGS = [
        self::SERVING_MODEL_SETTING,
        'features.embeddings.model',
    ];

    public function __construct(private EmbeddingSwitchStore $store) {}

    public function activate(EmbeddingModelProfile $target): EmbeddingSwitchState
    {
        $this->writeSettings($target);

        $idle = EmbeddingSwitchState::idle()->with(updatedAt: now()->toIso8601String());
        $this->store->put($idle);

        app(VectorSearchAvailability::class)->forget();

        $previous = ModelEmbedding::query()
            ->where(static function (Builder $query) use ($target): void {
                $query->where('model_key', '!=', $target->key)->orWhereNull('model_key');
            });

        $previous_keys = (clone $previous)->whereNotNull('model_key')->distinct()->pluck('model_key')->all();

        $previous->delete();

        $this->dropIndexes($previous_keys);

        return $idle;
    }

    /**
     * On pgvector the index of every model whose rows were just deleted goes with them.
     *
     * @param  list<string>  $modelKeys
     */
    private function dropIndexes(array $modelKeys): void
    {
        $indexes = app(IProfileVectorIndex::class);
        $connection = new ModelEmbedding()->getConnection();

        if (! $indexes->supports($connection)) {
            return;
        }

        foreach ($modelKeys as $modelKey) {
            try {
                $indexes->drop($connection, $modelKey);
            } catch (Throwable $exception) {
                // The activation is done: a failed drop leaves an unused index, never a failed switch.
                Log::warning("Could not drop the vector index of \"{$modelKey}\" after the embedding model switch; run ai:embeddings:prune --model-key={$modelKey} to retry: {$exception->getMessage()}");
            }
        }
    }

    /**
     * Writes the target into the settings in one transaction; the cache and runtime config are
     * flushed after the commit, and resynchronised from the database when the transaction rolls
     * back (the observer has already pushed the values that were not kept).
     */
    private function writeSettings(EmbeddingModelProfile $target): void
    {
        $connection = new Setting()->getConnection();

        try {
            $connection->transaction(function () use ($target): void {
                Setting::writeManaged(self::SERVING_MODEL_SETTING, $target->key);
                $this->store->recordTarget($target->key);
                $this->store->flushAfterCommit(self::SETTINGS);
            });
        } catch (Throwable $exception) {
            $this->store->resync(self::SETTINGS);

            throw $exception;
        }
    }
}

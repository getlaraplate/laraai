<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use function ai_config_bool;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Search\Traits\Searchable;

/**
 * Whether the embeddings pipeline may work on a model: the master switch (the seeded setting
 * `features.embeddings.enabled`, off), the per-module allowlist and, for a model that is to be
 * embedded, the `Searchable` trait and the model's own `isEmbeddable()`, which holds while the vector
 * profile provider ({@see \Modules\AI\Ai\Embeddings\ActiveEmbeddingProfileProvider}) answers a
 * profile, that is while the master switch is on and the active profile is configured. Every listener,
 * the repair command and the discovery of embeddable models ask here, so they cover the same models.
 */
final readonly class EmbeddingsGate
{
    public function enabled(): bool
    {
        return ai_config_bool('ai.features.embeddings.enabled', false);
    }

    /**
     * Whether embeddings may run for this model: the switch is on, the allowlist admits the model and,
     * unless `$requireEmbeddable` is false, the model is searchable and embeddable.
     */
    public function allows(Model $model, bool $requireEmbeddable = true): bool
    {
        return $this->enabled()
            && FeatureModuleGate::allows('embeddings', $model)
            && (! $requireEmbeddable || $this->isEmbeddable($model));
    }

    /**
     * Whether the model is one the pipeline embeds, whatever the switch says about the feature: the
     * allowlist admits it and it is searchable and embeddable, which needs a vector profile in force
     * (the active one, or the one a model switch pins). What a repair or a model switch has to cover.
     */
    public function handles(Model $model): bool
    {
        return FeatureModuleGate::allows('embeddings', $model) && $this->isEmbeddable($model);
    }

    private function isEmbeddable(Model $model): bool
    {
        // The model's own public capability query: reaching into the protected `$embed` property or the
        // private vector-enabled check from here fails silently (Eloquent __isset / __call).
        return class_uses_trait($model, Searchable::class)
            && method_exists($model, 'isEmbeddable')
            && $model->isEmbeddable();
    }
}

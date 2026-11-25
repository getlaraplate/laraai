<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\AI\Contracts\IEmbeddableModels;
use Modules\Core\Contracts\ISearchableModel;
use Override;

/**
 * Discovers the models the embeddings pipeline handles: searchable, embeddable (vector search on
 * and an `$embed` list) and allowed by the per-module allowlist, the same gate the indexing
 * listeners apply ({@see EmbeddingsGate::handles()}), so a repair covers exactly what indexing would have embedded.
 */
final readonly class EmbeddableModels implements IEmbeddableModels
{
    public function __construct(private EmbeddingsGate $gate) {}

    /**
     * @return list<class-string<Model&ISearchableModel>>
     */
    #[Override]
    public function all(): array
    {
        $embeddable = [];

        foreach (models(false) as $class) {
            if (! is_a($class, ISearchableModel::class, true)) {
                continue;
            }

            $instance = new $class();

            if ($this->gate->handles($instance)) {
                $embeddable[] = $class;
            }
        }

        return $embeddable;
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings\Switching;

use Illuminate\Database\Eloquent\Model;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Services\EmbeddingsGate;
use Modules\Core\Search\Contracts\IReportsVectorDimensions;
use Modules\Core\Search\Contracts\ITextEmbedder;
use Modules\Core\Search\Support\VectorProfileContext;
use Throwable;

/**
 * The `verify` phase of an embedding model switch. For each embeddable model's index: it holds one
 * document per searchable record, every record has a row of the target for each of its locales, the
 * mapping reports the target's dimensions, and a vector query of the embedded text `test` runs. The
 * first check that does not hold fails the phase with what it found. The last two checks need the
 * embeddings feature on ({@see EmbeddingsGate::enabled()}); the model checks run with the target's
 * vector profile in force.
 */
final readonly class EmbeddingSwitchVerifier
{
    public const string SMOKE_TEXT = 'test';

    public function __construct(
        private EmbeddingSwitchCorpus $corpus,
        private SearchIndexInspector $inspector,
        private EmbeddingModelRegistry $registry,
        private EmbeddingsGate $gate = new EmbeddingsGate(),
    ) {}

    /**
     * @throws EmbeddingSwitchPhaseFailed
     */
    public function verify(EmbeddingModelProfile $target): void
    {
        $vectorsOn = $this->gate->enabled();
        $smokeVector = $vectorsOn ? $this->smokeVector($target) : null;

        VectorProfileContext::using($target->vectorProfile(), function () use ($target, $vectorsOn, $smokeVector): void {
            foreach ($this->corpus->models() as $modelClass) {
                $this->verifyModel($modelClass, $target, $vectorsOn, $smokeVector);
            }
        });
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<float>|null  $smokeVector
     */
    private function verifyModel(string $modelClass, EmbeddingModelProfile $target, bool $vectorsOn, ?array $smokeVector): void
    {
        /** @var Model $instance */
        $instance = new $modelClass();
        $index = $instance->searchableAs();
        $records = $this->corpus->searchableCount($modelClass);
        $documents = $this->inspector->documentCount($instance);

        if ($documents !== null && $documents !== $records) {
            throw new EmbeddingSwitchPhaseFailed("index {$index} holds {$documents} document(s) for {$records} searchable record(s) of {$modelClass}. Resuming empties and rebuilds the index: run ai:embeddings:switch --resume");
        }

        $pending = $this->corpus->progress($target->key, $modelClass)['pending'];

        if ($pending !== []) {
            throw new EmbeddingSwitchPhaseFailed(count($pending) . " record(s) of {$modelClass} have no embedding of \"{$target->key}\": " . EmbeddingSwitchCorpus::describe($pending));
        }

        if (! $vectorsOn) {
            return;
        }

        $engine = $instance->searchableUsing();

        if ($engine instanceof IReportsVectorDimensions) {
            $dimensions = $engine->indexedVectorDimensions($instance);

            if ($dimensions !== $target->dimensions) {
                $found = $dimensions === null ? 'no vector field' : "vectors of {$dimensions} dimensions";

                throw new EmbeddingSwitchPhaseFailed("index {$index} maps {$found}; \"{$target->key}\" has {$target->dimensions}");
            }
        }

        if ($smokeVector === null) {
            return;
        }

        try {
            $this->inspector->vectorQuery($instance, $smokeVector);
        } catch (Throwable $exception) {
            throw new EmbeddingSwitchPhaseFailed("the smoke vector query on index {$index} failed: {$exception->getMessage()}", previous: $exception);
        }
    }

    /**
     * The embedding of {@see self::SMOKE_TEXT} with the target, as a search query embeds it but
     * straight from the provider, never from the query cache, so each run proves the target answers
     * now; null when no query embedder is bound.
     *
     * @return list<float>|null
     */
    private function smokeVector(EmbeddingModelProfile $target): ?array
    {
        if (! app()->bound(ITextEmbedder::class)) {
            return null;
        }

        try {
            $vector = $this->registry->withActive($target->key, static fn (): array => array_values(app(IEmbeddingService::class)->getEmbeddingsProvider()->embedText(self::SMOKE_TEXT)));
        } catch (Throwable $exception) {
            throw new EmbeddingSwitchPhaseFailed("the smoke query text could not be embedded with \"{$target->key}\": {$exception->getMessage()}", previous: $exception);
        }

        if ($target->dimensions !== count($vector)) {
            throw new EmbeddingSwitchPhaseFailed("the smoke query text embedded with \"{$target->key}\" has " . count($vector) . " dimensions, {$target->dimensions} expected");
        }

        return $vector;
    }
}

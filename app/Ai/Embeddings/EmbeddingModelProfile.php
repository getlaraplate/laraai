<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings;

use Modules\Core\Search\DTOs\VectorProfile;

/**
 * Immutable description of a single embedding model's attributes: which
 * provider serves it, its output dimensionality and similarity metric, and
 * the query/passage prefixes it expects (e.g. E5-family instruction
 * prefixes), and optionally the name of the server in `ai.servers` that runs it.
 * Resolved from config by EmbeddingModelRegistry.
 */
final readonly class EmbeddingModelProfile
{
    public function __construct(
        public string $key,
        public string $provider,
        public string $serviceModel,
        public int $dimensions,
        public string $queryPrefix,
        public string $passagePrefix,
        public string $similarity,
        public bool $normalize,
        public ?string $server = null,
    ) {}

    /**
     * Whether two names designate the same service model: the last path segment, without case or
     * spaces, so `sentence-transformers/all-MiniLM-L6-v2` is `all-MiniLM-L6-v2` and a name that only
     * ends the same is not. A service reports a model with or without its organisation.
     */
    public static function sameServiceModel(string $first, string $second): bool
    {
        $normalize = static fn (string $name): string => mb_strtolower(basename(str_replace('\\', '/', mb_trim($name))));

        $first = $normalize($first);

        return $first !== '' && $first === $normalize($second);
    }

    /**
     * The vector profile Core indexes and queries this model's vectors with.
     */
    public function vectorProfile(): VectorProfile
    {
        return new VectorProfile($this->key, $this->dimensions, $this->similarity);
    }
}

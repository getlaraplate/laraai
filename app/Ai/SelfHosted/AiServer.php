<?php

declare(strict_types=1);

namespace Modules\AI\Ai\SelfHosted;

/**
 * One server of `ai.servers` as the clients use it: where it is, the key to send, how long a request may take,
 * how many texts an embeddings request carries and, for the reranker, the dialect and model it speaks.
 */
final readonly class AiServer
{
    public function __construct(
        public string $name,
        public string $url,
        public ?string $apiKey,
        public int $timeout,
        public int $batchSize,
        public ?string $dialect,
        public ?string $model,
    ) {}

    public function isConfigured(): bool
    {
        return $this->url !== '';
    }
}

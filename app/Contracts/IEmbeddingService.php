<?php

declare(strict_types=1);

namespace Modules\AI\Contracts;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

/**
 * Text embedding generation (documents and single strings) for RAG and jobs.
 */
interface IEmbeddingService
{
    /**
     * @return Document[]
     */
    public function embedDocument(string $data): array;

    /**
     * Embed several texts in a single batched call to the provider, returning
     * each input's chunk documents (with embeddings) aligned to the input order.
     * A text with a header (same index in `$headers`) has it prepended as the
     * first line of every one of its chunks; a text without one is embedded as it is.
     *
     * @param  list<string>  $texts
     * @param  array<int, string|null>  $headers  One-line context header per text index
     * @return list<Document[]>
     */
    public function embedDocumentsBatch(array $texts, array $headers = []): array;

    /**
     * @return list<float>
     */
    public function embedText(string $text): array;

    public function getEmbeddingsProvider(): EmbeddingsProviderInterface;
}

<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Embeddings;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

/**
 * Fake embeddings provider that records the exact text handed to it, mirroring
 * how {@see \Modules\AI\Ai\Embeddings\OpenAiCompatibleEmbeddingsProvider}
 * reads `formattedContent ?? content` for documents. Used to assert that
 * query/passage prefixes reach the provider boundary.
 */
final class RecordingEmbeddingsProvider implements EmbeddingsProviderInterface
{
    /**
     * @var list<string>
     */
    public array $textsSent = [];

    public function embedText(string $text): array
    {
        $this->textsSent[] = $text;

        return [0.1, 0.2, 0.3];
    }

    public function embedDocument(Document $document): Document
    {
        $this->textsSent[] = $document->getContent();

        return $document->setEmbedding([0.1, 0.2, 0.3]);
    }

    /**
     * @param  Document[]  $documents
     * @return Document[]
     */
    public function embedDocuments(array $documents): array
    {
        return array_map($this->embedDocument(...), $documents);
    }
}

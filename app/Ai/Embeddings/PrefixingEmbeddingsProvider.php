<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

/**
 * Gives an embeddings provider the query and passage prefixes of an embedding model (`query: ` and
 * `passage: ` for E5): Neuron's interface has no split between the two, so whoever embeds a question
 * with `embedText()` and a chunk with `embedDocument()` gets the right side of the model without
 * adding the prefix by hand.
 *
 * A document is embedded as a prefixed copy and only its vector is copied back: `Document::$content`
 * is what the model reads and what the answer cites, and must not carry the prefix.
 */
final readonly class PrefixingEmbeddingsProvider implements EmbeddingsProviderInterface
{
    public function __construct(
        private EmbeddingsProviderInterface $inner,
        private string $queryPrefix,
        private string $passagePrefix,
    ) {}

    /**
     * The provider as it is when the profile prefixes nothing.
     */
    public static function forProfile(EmbeddingsProviderInterface $inner, EmbeddingModelProfile $profile): EmbeddingsProviderInterface
    {
        return $profile->queryPrefix === '' && $profile->passagePrefix === ''
            ? $inner
            : new self($inner, $profile->queryPrefix, $profile->passagePrefix);
    }

    /**
     * @return list<float>
     */
    public function embedText(string $text): array
    {
        return array_values($this->inner->embedText($this->queryPrefix . $text));
    }

    public function embedDocument(Document $document): Document
    {
        return $this->embedDocuments([$document])[0];
    }

    /**
     * @param  Document[]  $documents
     * @return Document[]
     */
    public function embedDocuments(array $documents): array
    {
        $copies = [];

        foreach ($documents as $index => $document) {
            $copies[$index] = (new Document($this->passagePrefix . $document->getContent()))
                ->setId($document->getId())
                ->setSourceType($document->getSourceType())
                ->setSourceName($document->getSourceName())
                ->setMetadata($document->getMetadata());
        }

        $embedded = array_values($this->inner->embedDocuments($copies));

        $position = 0;

        foreach ($documents as $document) {
            $document->setEmbedding($embedded[$position++]->getEmbedding());
        }

        return $documents;
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Embeddings;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Splitter\AbstractSplitter;
use Override;

/**
 * Test splitter that always breaks the incoming document's content into two
 * fixed-size chunks, regardless of length. Makes prefix propagation across
 * multiple chunks deterministic and independent of chunking heuristics.
 */
final class FixedChunkSplitter extends AbstractSplitter
{
    /**
     * @return Document[]
     */
    #[Override]
    public function splitDocument(Document $document): array
    {
        $content = $document->getContent();
        $half = (int) ceil(mb_strlen($content) / 2);

        return [
            new Document(mb_substr($content, 0, $half)),
            new Document(mb_substr($content, $half)),
        ];
    }
}

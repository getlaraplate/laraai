<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;

/**
 * Keeps the documents that a RAG agent hands to the model, so that the answer can cite them: Neuron
 * sends the retrieved documents with the question and nowhere on the answer. Put it last in the
 * chain, after any filter, and it leaves the documents as it found them.
 */
final class RecordingPostProcessor implements PostProcessorInterface
{
    /**
     * @var list<Document>
     */
    private array $documents = [];

    /**
     * @param  Document[]  $documents
     * @return Document[]
     */
    public function process(Message $question, array $documents): array
    {
        $this->documents = array_values($documents);

        return $documents;
    }

    /**
     * The documents of the last question.
     *
     * @return list<Document>
     */
    public function documents(): array
    {
        return $this->documents;
    }
}

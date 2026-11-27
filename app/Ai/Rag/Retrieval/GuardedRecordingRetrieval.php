<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag\Retrieval;

use Illuminate\Support\Facades\Log;
use Modules\AI\Services\Assistance\AssistanceGuardrailPipeline;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use Override;

/**
 * The documentation retrieval the model calls itself, through Neuron's `RetrievalTool`: the same scoped
 * retrieval as the one that runs before the answer, a passage whose text reads like an instruction withheld,
 * as the results of the data tools are, and every passage handed to the model kept, so that the answer can
 * cite it.
 */
final class GuardedRecordingRetrieval implements RetrievalInterface
{
    /**
     * @var array<string, Document>
     */
    private array $documents = [];

    public function __construct(
        private readonly RetrievalInterface $retrieval,
        private readonly AssistanceGuardrailPipeline $guardrails,
    ) {}

    /**
     * @return list<Document>
     */
    #[Override]
    public function retrieve(Message $query, ?FilterExpression $filters = null): array
    {
        $kept = [];

        foreach ($this->retrieval->retrieve($query, $filters) as $document) {
            if ($this->guardrails->toolResultContainsInstructions($document->getContent())) {
                Log::notice('Assistant documentation passage withheld');

                continue;
            }

            $this->documents[hash('xxh128', $document->getSourceName() . "\0" . $document->getContent())] = $document;
            $kept[] = $document;
        }

        return $kept;
    }

    /**
     * Every passage the model received, each once, in the order it first came.
     *
     * @return list<Document>
     */
    public function documents(): array
    {
        return array_values($this->documents);
    }
}

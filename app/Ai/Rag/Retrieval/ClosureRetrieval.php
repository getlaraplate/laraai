<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag\Retrieval;

use Closure;
use InvalidArgumentException;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use Override;

/**
 * A documentation retrieval that already carries its scope, as a Neuron retrieval strategy: the in-app
 * retrieval of one person, whose tenant, permission and audience terms are built by the retrieval itself.
 *
 * It takes no filter from the pipeline. Neuron asks a strategy to AND the filters it is given with its own and
 * never drop one; this one has no portable filter to AND them with, so it refuses any instead of ignoring it.
 */
final readonly class ClosureRetrieval implements RetrievalInterface
{
    /**
     * @param  Closure(string): list<Document>  $retrieve
     */
    public function __construct(private Closure $retrieve) {}

    /**
     * @return list<Document>
     */
    #[Override]
    public function retrieve(Message $query, ?FilterExpression $filters = null): array
    {
        if ($filters instanceof FilterExpression) {
            throw new InvalidArgumentException('This retrieval carries its own scope and takes no further filter.');
        }

        return ($this->retrieve)((string) $query->getContent());
    }
}

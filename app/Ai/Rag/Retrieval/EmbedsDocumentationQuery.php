<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag\Retrieval;

use RuntimeException;

/**
 * The query side of a documentation retrieval: the question, embedded (the provider adds the query
 * prefix of the active embedding model). Used by the retrievals that own an `embedding_service` property.
 */
trait EmbedsDocumentationQuery
{
    /**
     * @throws RuntimeException when the provider returns no vector
     *
     * @return list<float>
     */
    private function embedQuestion(string $question): array
    {
        $embedding = $this->embedding_service->embedText($question);

        if ($embedding === []) {
            throw new RuntimeException;
        }

        return $embedding;
    }
}

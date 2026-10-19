<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag\Retrieval;

use function ai_config_int;

use Closure;
use InvalidArgumentException;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Ai\Rag\ElasticsearchRagVectorStore;
use Modules\AI\Contracts\IEmbeddingService;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\SearchRequest;
use RuntimeException;
use Throwable;

/**
 * Vector retrieval over the developer documentation index.
 *
 * Unlike {@see InAppDocumentationRetrieval}, this path serves the trusted
 * developer corpus: no per-user ACL filter, no audience gate, no safe
 * projection. It exists so the developer index can be measured by
 * `ai:evaluate-documentation` without weakening the user-facing in-app path.
 */
final readonly class DeveloperDocumentationRetrieval
{
    use EmbedsDocumentationQuery;

    /**
     * @param  (Closure(array<float>): array<Document>)|null  $search
     */
    public function __construct(
        private IEmbeddingService $embedding_service,
        private ?Closure $search = null,
    ) {}

    /**
     * @return list<Document>
     */
    public function retrieve(string $question): array
    {
        $question = mb_trim($question);

        if ($question === '') {
            throw new InvalidArgumentException('Developer documentation question cannot be blank.');
        }

        try {
            $embedding = $this->embedQuestion($question);

            $documents = $this->search !== null
                ? ($this->search)($embedding)
                : $this->searchDeveloperIndex($embedding);

            return MinimumSimilarity::filter($this->onlyValidDocuments($documents));
        } catch (Throwable) {
            throw new RuntimeException('Developer documentation retrieval is unavailable.');
        }
    }

    /**
     * @param  list<float>  $embedding
     * @return list<Document>
     */
    private function searchDeveloperIndex(array $embedding): array
    {
        $top_k = min(max(ai_config_int('ai.features.faq.max_documents', 5), 1), 10);
        $store = ElasticsearchRagVectorStore::fromConfig(DocumentationIndexProfile::Developer, $top_k);

        // search() is declared iterable: the caller wants a list, and a
        // generator would satisfy the interface while breaking every array use below.
        $documents = iterator_to_array($store->search(new SearchRequest($embedding)), false);

        if ($documents === [] && ! $store->hasDocuments()) {
            throw new RuntimeException;
        }

        return $documents;
    }

    /**
     * Validates what the search returned, which is why the parameter is not typed as
     * an array of Documents: one of the two sources is an injected closure, and a
     * closure honours no PHPDoc at runtime. Declaring the narrow type here made the
     * guard unreachable — PHPStan reported the instanceof as always true and the whole
     * condition as always false — while the case it defends against stayed possible.
     *
     * @param  array<mixed>  $documents
     * @return list<Document>
     */
    private function onlyValidDocuments(array $documents): array
    {
        $valid = [];

        foreach ($documents as $document) {
            // Only the instanceof is a real check. Document::$sourceName is declared
            // `public string`, so PHP itself refuses anything else and testing it here
            // promised a defence that does not exist.
            if (! $document instanceof Document) {
                throw new RuntimeException;
            }

            $valid[] = $document;
        }

        return $valid;
    }
}

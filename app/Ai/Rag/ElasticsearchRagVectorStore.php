<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag;

use function ai_config_string;
use function is_array;
use function is_numeric;
use function is_string;
use function min;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Rag\Retrieval\DocumentationRetrievalContext;
use Modules\Core\Services\ElasticsearchService;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterCombinator;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\Filter\FilterOperator;
use NeuronAI\RAG\VectorStore\HasDocumentSchema;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use Override;
use stdClass;
use Throwable;

/**
 * Elasticsearch-backed vector store for documentation RAG.
 *
 * Retrieval fails fast on transport errors so the agent does not answer without retrieved context.
 */
final class ElasticsearchRagVectorStore implements VectorStoreInterface
{
    use HasDocumentSchema;

    public function __construct(
        private readonly Client $client,
        private readonly DocumentationIndexProfile $indexProfile,
        private readonly int $topK = 5,
        private readonly int $embedding_dims = 384,
    ) {
        DocumentationIndexProfile::assertDistinctConfiguration();

        if ($this->embedding_dims <= 0) {
            throw new InvalidArgumentException('Elasticsearch RAG embedding dimensions must be positive.');
        }

        $this->initializeSchema(null);
    }

    public static function fromConfig(DocumentationIndexProfile $profile, int $topK): self
    {
        return new self(
            client: ElasticsearchService::getInstance()->client,
            indexProfile: $profile,
            topK: $topK,
            embedding_dims: self::activeDimensions(),
        );
    }

    /**
     * The length of the documentation vectors: the dimensions of the active embedding profile, the
     * model that embeds the documents and the questions (a switch rebuilds the index with the target
     * profile in force).
     */
    public static function activeDimensions(): int
    {
        return app(EmbeddingModelRegistry::class)->active()->dimensions;
    }

    /**
     * @return array<string, mixed>
     */
    public static function indexMappings(int $embedding_dims): array
    {
        return [
            'properties' => [
                'content' => ['type' => 'text'],
                'sourceType' => ['type' => 'keyword'],
                'sourceName' => ['type' => 'keyword'],
                'metadata' => [
                    'type' => 'object',
                    'enabled' => true,
                    'properties' => [
                        'audience' => ['type' => 'keyword'],
                        'module' => ['type' => 'keyword'],
                        'locale' => ['type' => 'keyword'],
                        'canonical_source' => ['type' => 'keyword'],
                        'safe_source_label' => ['type' => 'keyword'],
                        'required_permissions' => ['type' => 'keyword'],
                        'permissions_metadata_validated' => ['type' => 'boolean'],
                        'required_permissions_count' => ['type' => 'integer'],
                        'cross_cutting_user' => ['type' => 'boolean'],
                        'tenant_scope' => ['type' => 'keyword'],
                        'tenant_id' => ['type' => 'keyword'],
                        'version' => ['type' => 'keyword'],
                        'heading_breadcrumb' => ['type' => 'keyword'],
                        'source_type' => ['type' => 'keyword'],
                        'policy_classification' => ['type' => 'keyword'],
                        'policy_classification_version' => ['type' => 'keyword'],
                    ],
                ],
                'neuron_id' => ['type' => 'keyword'],
                'indexed_at' => ['type' => 'date'],
                'embedding' => [
                    'type' => 'dense_vector',
                    'dims' => $embedding_dims,
                    'index' => true,
                    'similarity' => 'cosine',
                ],
            ],
        ];
    }

    public function indexExists(): bool
    {
        try {
            $this->client->count(['index' => $this->indexProfile->indexName()]);

            return true;
        } catch (Throwable $throwable) {
            Log::warning('Elasticsearch RAG index exists check failed', [
                'index' => $this->indexProfile->indexName(),
                'error' => $throwable->getMessage(),
            ]);

            return false;
        }
    }

    public function hasDocuments(): bool
    {
        try {
            $response = $this->client->count(['index' => $this->indexProfile->indexName()])->asArray();
            $count = $response['count'] ?? 0;

            return is_numeric($count) && (int) $count > 0;
        } catch (Throwable $throwable) {
            Log::warning('Elasticsearch RAG document count failed', [
                'index' => $this->indexProfile->indexName(),
                'error' => $throwable->getMessage(),
            ]);

            return false;
        }
    }

    public function clearIndex(): void
    {
        if (! $this->indexExists()) {
            return;
        }

        try {
            $this->client->deleteByQuery([
                'index' => $this->indexProfile->indexName(),
                'body' => [
                    'query' => ['match_all' => new stdClass],
                ],
            ]);
        } catch (ClientResponseException|ServerResponseException $throwable) {
            throw new VectorStoreException(
                'Failed to clear Elasticsearch RAG index: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    /**
     * @param  Document[]  $documents
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        if ($documents === []) {
            return $this;
        }

        $this->validateDocuments($documents);

        $body = [];

        foreach ($documents as $document) {
            $id = (string) $document->getId();
            $body[] = ['index' => ['_index' => $this->indexProfile->indexName(), '_id' => $id]];
            $body[] = $this->documentToSource($document);
        }

        try {
            $response = $this->client->bulk(['body' => $body])->asArray();

            if (($response['errors'] ?? false) === true) {
                throw new VectorStoreException('Elasticsearch bulk indexing reported errors.');
            }
        } catch (VectorStoreException $throwable) {
            throw $throwable;
        } catch (Throwable $throwable) {
            throw new VectorStoreException(
                'Failed to index RAG documents in Elasticsearch: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }

        return $this;
    }

    /**
     * Deletes the documents of a source: the filters Neuron sends are on `sourceType` and `sourceName`, and
     * the index stores both as keywords.
     */
    #[Override]
    public function delete(FilterExpression $filters): VectorStoreInterface
    {
        $this->validateFilters($filters);
        $query = $this->filterQuery($filters);

        try {
            $this->client->deleteByQuery([
                'index' => $this->indexProfile->indexName(),
                'body' => [
                    'query' => $query,
                ],
            ]);
        } catch (Throwable $throwable) {
            throw new VectorStoreException(
                'Failed to delete RAG documents from Elasticsearch: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }

        return $this;
    }

    /**
     * @return Document[]
     */
    #[Override]
    public function search(SearchRequest $request): array
    {
        if ($request->filters instanceof FilterExpression) {
            $this->validateFilters($request->filters);
        }

        return $this->knnSearch(
            $request->embedding,
            $request->filters instanceof FilterExpression ? $this->filterQuery($request->filters) : null,
            $request->topK ?? $this->topK,
        );
    }

    /**
     * @param  float[]  $embedding
     * @return Document[]
     */
    public function similaritySearchForContext(
        array $embedding,
        DocumentationRetrievalContext $context,
    ): array {
        if ($this->indexProfile !== DocumentationIndexProfile::User) {
            throw new InvalidArgumentException('Scoped in-app retrieval requires the user documentation index.');
        }

        return $this->knnSearch(
            $embedding,
            $context->elasticsearchFilter(
                ai_config_string('ai.features.faq.policy_classification_version', 'in-app-docs-v1'),
            ),
            $this->topK,
        );
    }

    /**
     * @param  float[]  $embedding
     * @param  array<string, mixed>|null  $filter
     * @return Document[]
     */
    private function knnSearch(array $embedding, ?array $filter, int $topK): array
    {
        $num_candidates = min($topK * 10, 100);
        $knn = [
            'field' => 'embedding',
            'query_vector' => $embedding,
            'k' => $topK,
            'num_candidates' => $num_candidates,
        ];

        if ($filter !== null) {
            $knn['filter'] = $filter;
        }

        try {
            $response = $this->client->search([
                'index' => $this->indexProfile->indexName(),
                'body' => [
                    'size' => $topK,
                    'knn' => $knn,
                ],
            ])->asArray();
        } catch (Throwable $throwable) {
            throw new VectorStoreException(
                'Elasticsearch RAG similarity search failed: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }

        return $this->mapHitsToDocuments($response);
    }

    /**
     * The Elasticsearch query of the filters Neuron sends: an equality on one field, alone or all of them together.
     *
     * @return array<string, mixed>
     */
    private function filterQuery(FilterExpression $filters): array
    {
        return ['bool' => ['must' => $this->termQueries($filters)]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function termQueries(FilterExpression $filters): array
    {
        if ($filters instanceof Filter && $filters->operator === FilterOperator::Eq) {
            return [['term' => [$filters->field => $filters->value]]];
        }

        if ($filters instanceof FilterGroup && $filters->operator() === FilterCombinator::And) {
            return array_merge(...array_map($this->termQueries(...), $filters->conditions()));
        }

        throw new VectorStoreException('The Elasticsearch RAG store filters by equality, all conditions together, only.');
    }

    /**
     * @param  array<string, mixed>  $response
     * @return Document[]
     */
    private function mapHitsToDocuments(array $response): array
    {
        $hits = $response['hits']['hits'] ?? [];

        if (! is_array($hits)) {
            return [];
        }

        $documents = [];

        foreach ($hits as $hit) {
            if (! is_array($hit)) {
                continue;
            }

            $source = $hit['_source'] ?? [];

            if (! is_array($source)) {
                continue;
            }

            $content = $source['content'] ?? '';

            if (! is_string($content)) {
                continue;
            }

            $document = (new Document($content))
                ->setSourceType(is_string($source['sourceType'] ?? null) ? $source['sourceType'] : 'manual')
                ->setSourceName(is_string($source['sourceName'] ?? null) ? $source['sourceName'] : 'manual')
                ->setMetadata(is_array($source['metadata'] ?? null) ? array_diff_key($source['metadata'], array_flip(DocumentSchema::RESERVED_FIELDS)) : [])
                ->setScore((float) ($hit['_score'] ?? 0.0));

            $embedding = $source['embedding'] ?? null;

            if (is_array($embedding) && $embedding !== []) {
                $document->setEmbedding($embedding);
            }

            if (is_string($source['neuron_id'] ?? null)) {
                $document->setId($source['neuron_id']);
            } elseif (is_string($hit['_id'] ?? null)) {
                $document->setId($hit['_id']);
            }

            $documents[] = $document;
        }

        return $documents;
    }

    /**
     * @return array<string, mixed>
     */
    private function documentToSource(Document $document): array
    {
        return [
            'content' => $document->getContent(),
            'sourceType' => $document->getSourceType(),
            'sourceName' => $document->getSourceName(),
            'embedding' => $document->getEmbedding(),
            'metadata' => $document->getMetadata(),
            'neuron_id' => (string) $document->getId(),
            'indexed_at' => now()->toIso8601String(),
        ];
    }
}

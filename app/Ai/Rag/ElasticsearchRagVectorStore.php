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

    /**
     * The most sections of one document a read returns, so a wrong key or a huge document cannot return
     * an unbounded body.
     */
    public const int MAX_READ_SECTIONS = 200;

    /**
     * The metadata key that carries the fingerprint of the source a chunk belongs to: the same value on every
     * chunk of one source, which is how a diff run learns that a source is unchanged
     * ({@see self::sourceFingerprints()}).
     */
    public const string SOURCE_HASH = 'source_hash';

    /**
     * The most sources a fingerprint read returns; a larger index is refused instead of read in part.
     */
    private const int MAX_SOURCES = 10000;

    private bool $indexReady = false;

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
                        'document_key' => ['type' => 'keyword'],
                        'section' => ['type' => 'integer'],
                        'source_type' => ['type' => 'keyword'],
                        'policy_classification' => ['type' => 'keyword'],
                        'policy_classification_version' => ['type' => 'keyword'],
                        self::SOURCE_HASH => ['type' => 'keyword'],
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

    /**
     * The fingerprint of every source in the index, by source name: the hash its chunks carry and how many
     * chunks it has. The hash is null when the chunks of a source disagree (a write that stopped midway) or
     * carry none (written before fingerprints existed), so such a source reads as changed.
     *
     *
     * @throws VectorStoreException when the index cannot be read, or holds more sources than a read returns
     *
     * @return array<string, array{hash: string|null, chunks: int}>
     */
    public function sourceFingerprints(): array
    {
        if (! $this->indexExists()) {
            return [];
        }

        $this->ensureIndexReady();

        try {
            $response = $this->client->search([
                'index' => $this->indexProfile->indexName(),
                'body' => [
                    'size' => 0,
                    'aggs' => [
                        'sources' => [
                            'terms' => ['field' => 'sourceName', 'size' => self::MAX_SOURCES],
                            'aggs' => [
                                'hashes' => ['terms' => ['field' => 'metadata.' . self::SOURCE_HASH, 'size' => 2]],
                            ],
                        ],
                    ],
                ],
            ])->asArray();
        } catch (Throwable $throwable) {
            throw new VectorStoreException(
                'Failed to read the indexed sources from Elasticsearch: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }

        $aggregation = $response['aggregations']['sources'] ?? [];

        if (! is_array($aggregation)) {
            return [];
        }

        throw_if(
            (int) ($aggregation['sum_other_doc_count'] ?? 0) > 0,
            VectorStoreException::class,
            sprintf('The index holds more than %d sources: a diff cannot read them all.', self::MAX_SOURCES),
        );

        $fingerprints = [];

        foreach ((array) ($aggregation['buckets'] ?? []) as $bucket) {
            if (! is_array($bucket) || ! is_string($bucket['key'] ?? null)) {
                continue;
            }

            $chunks = (int) ($bucket['doc_count'] ?? 0);
            $hashes = (array) ($bucket['hashes']['buckets'] ?? []);
            $only = count($hashes) === 1 && is_array($hashes[0]) ? $hashes[0] : null;
            $hash = $only !== null && is_string($only['key'] ?? null) && $chunks === (int) ($only['doc_count'] ?? 0)
                ? $only['key']
                : null;

            $fingerprints[$bucket['key']] = ['hash' => $hash, 'chunks' => $chunks];
        }

        return $fingerprints;
    }

    /**
     * Deletes the chunks of every source whose name is not in `$source_names`, and returns how many chunks
     * went. An empty list is refused: it would empty the index, and a run that found no document at all is
     * a mistake to report, not a library to wipe.
     *
     * @param  list<string>  $source_names
     *
     * @throws InvalidArgumentException when `$source_names` is empty
     */
    public function deleteSourcesNotIn(array $source_names): int
    {
        if ($source_names === []) {
            throw new InvalidArgumentException('Refusing to delete the sources not in an empty list: it would empty the index.');
        }

        $this->ensureIndexReady();

        try {
            $response = $this->client->deleteByQuery([
                'index' => $this->indexProfile->indexName(),
                'conflicts' => 'proceed',
                'body' => [
                    'query' => ['bool' => ['must_not' => [['terms' => ['sourceName' => array_values($source_names)]]]]],
                ],
            ])->asArray();
        } catch (Throwable $throwable) {
            throw new VectorStoreException(
                'Failed to delete the removed sources from Elasticsearch: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }

        return (int) ($response['deleted'] ?? 0);
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
        $this->ensureIndexReady();

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
        $this->ensureIndexReady();

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
     * The chunks of one document in section order, under the access filter of the scoped search plus a
     * term on the document key. `$from` and `$to` bound the sections returned (both inclusive); the
     * document's own section count is the maximum `section` of the document plus one, read from an
     * aggregation that the range does not narrow, and null when no chunk carries a position.
     *
     * @return array{documents: array<Document>, total: int|null}
     */
    public function chunksOfDocument(
        string $document_key,
        DocumentationRetrievalContext $context,
        ?int $from = null,
        ?int $to = null,
    ): array {
        if ($this->indexProfile !== DocumentationIndexProfile::User) {
            throw new InvalidArgumentException('Reading a documentation document requires the user documentation index.');
        }

        $body = [
            'size' => self::MAX_READ_SECTIONS,
            'query' => [
                'bool' => [
                    'filter' => [
                        $context->elasticsearchFilter(
                            ai_config_string('ai.features.faq.policy_classification_version', 'in-app-docs-v1'),
                        ),
                        ['term' => ['metadata.document_key' => $document_key]],
                    ],
                ],
            ],
            'sort' => [['metadata.section' => ['order' => 'asc', 'unmapped_type' => 'long']]],
            'aggs' => ['last_section' => ['max' => ['field' => 'metadata.section']]],
        ];

        if ($from !== null || $to !== null) {
            $body['post_filter'] = ['range' => ['metadata.section' => array_filter(
                ['gte' => $from, 'lte' => $to],
                static fn (?int $bound): bool => $bound !== null,
            )]];
        }

        try {
            $response = $this->client->search([
                'index' => $this->indexProfile->indexName(),
                'body' => $body,
            ])->asArray();
        } catch (Throwable $throwable) {
            throw new VectorStoreException(
                'Elasticsearch RAG document read failed: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }

        $last = $response['aggregations']['last_section']['value'] ?? null;

        return [
            'documents' => $this->mapHitsToDocuments($response),
            'total' => is_numeric($last) ? (int) $last + 1 : null,
        ];
    }

    /**
     * Makes the index fit for the writes and deletes of a source, once per store.
     *
     * An index that does not exist is created with the mapping of {@see self::indexMappings()}: left to the first
     * write it would be created by dynamic mapping, which makes `sourceName` a text field. A term query on a text
     * field does not find the whole name, so the delete that replaces a source would delete nothing and every run
     * would add the chunks of a source once more. An index that exists with `sourceType` or `sourceName` as text
     * is refused, with the way to rebuild it: the type of a field cannot be changed in place. The source
     * fingerprint is then mapped as a keyword on an index created before it existed, for the same reason: dynamic
     * mapping would make it text, which a terms aggregation cannot read.
     *
     * @throws VectorStoreException when the index cannot be created, read or mapped, or maps a source field as text
     */
    private function ensureIndexReady(): void
    {
        if ($this->indexReady) {
            return;
        }

        $index = $this->indexProfile->indexName();

        try {
            if (! $this->client->indices()->exists(['index' => $index])->asBool()) {
                $this->client->indices()->create(['index' => $index, 'body' => ['mappings' => self::indexMappings($this->embedding_dims)]]);
                $this->indexReady = true;

                return;
            }

            $mapping = $this->client->indices()->getMapping(['index' => $index])->asArray();
        } catch (Throwable $throwable) {
            throw new VectorStoreException(
                'Failed to prepare the Elasticsearch RAG index: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }

        $properties = [];

        foreach ($mapping as $definition) {
            $properties = is_array($definition['mappings']['properties'] ?? null) ? $definition['mappings']['properties'] : [];

            break;
        }

        foreach (['sourceType', 'sourceName'] as $field) {
            $type = is_array($properties[$field] ?? null) ? ($properties[$field]['type'] ?? null) : null;

            if ($type !== null && $type !== 'keyword') {
                throw new VectorStoreException(sprintf(
                    'The index [%s] maps %s as "%s", not as a keyword: it was created by a write, not by ai:create-rag-index, so the chunks of a source cannot be found by name and a run would add them again instead of replacing them. Rebuild it: php artisan ai:create-rag-index --profile=%s --force, then php artisan ai:index-rag-docs --full --profile=%s',
                    $index,
                    $field,
                    is_string($type) ? $type : 'unknown',
                    $this->indexProfile->value,
                    $this->indexProfile->value,
                ));
            }
        }

        try {
            $this->client->indices()->putMapping([
                'index' => $index,
                'body' => ['properties' => ['metadata' => ['properties' => [self::SOURCE_HASH => ['type' => 'keyword']]]]],
            ]);
        } catch (Throwable $throwable) {
            throw new VectorStoreException(
                'Failed to map the source fingerprint in Elasticsearch: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }

        $this->indexReady = true;
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
                $document->setEmbedding(array_map(floatval(...), $embedding));
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

<?php

declare(strict_types=1);

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Endpoints\Indices;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Ai\Rag\ElasticsearchRagVectorStore;
use Modules\AI\Ai\Rag\Retrieval\DocumentationRetrievalContext;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\AssistantTenantScope;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\SearchRequest;

function make_rag_vector_store(
    Client $client,
    DocumentationIndexProfile $profile = DocumentationIndexProfile::Developer,
): ElasticsearchRagVectorStore {
    config()->set('ai.features.faq.elasticsearch.developer_index', 'laraplate_rag_docs_test');
    config()->set('ai.features.faq.elasticsearch.user_index', 'laraplate_rag_user_docs_test');

    return new ElasticsearchRagVectorStore(
        client: $client,
        indexProfile: $profile,
        topK: 2,
        embedding_dims: 3,
    );
}

test('index mappings include dense vector with configured dimensions', function (): void {
    $mappings = ElasticsearchRagVectorStore::indexMappings(384);

    expect($mappings['properties']['embedding']['dims'])->toBe(384)
        ->and($mappings['properties']['embedding']['type'])->toBe('dense_vector')
        ->and($mappings['properties']['metadata']['properties'])->toHaveKeys([
            'audience',
            'module',
            'locale',
            'canonical_source',
            'safe_source_label',
            'required_permissions',
            'permissions_metadata_validated',
            'required_permissions_count',
            'tenant_scope',
            'tenant_id',
            'version',
            'heading_breadcrumb',
            'document_key',
            'section',
            'source_type',
            'policy_classification_version',
        ]);
    expect($mappings['properties']['metadata']['properties']['source_type'])->toBe(['type' => 'keyword'])
        ->and($mappings['properties']['metadata']['properties']['document_key'])->toBe(['type' => 'keyword'])
        ->and($mappings['properties']['metadata']['properties']['section'])->toBe(['type' => 'integer']);
});

test('profile selects a distinct configured physical index', function (): void {
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('count')
        ->once()
        ->with(['index' => 'laraplate_rag_user_docs_test'])
        ->andReturn(make_elasticsearch_response(['count' => 1]));

    expect(make_rag_vector_store($client, DocumentationIndexProfile::User)->hasDocuments())->toBeTrue();
});

test('matching developer and user index names are rejected', function (): void {
    config()->set('ai.features.faq.elasticsearch.developer_index', 'same_index');
    config()->set('ai.features.faq.elasticsearch.user_index', 'same_index');

    expect(fn () => new ElasticsearchRagVectorStore(
        client: Mockery::mock(Client::class),
        indexProfile: DocumentationIndexProfile::Developer,
        topK: 2,
        embedding_dims: 3,
    ))->toThrow(InvalidArgumentException::class);
});

test('similarity search maps knn hits to neuron documents', function (): void {
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('search')
        ->once()
        ->with(Mockery::on(function (array $params): bool {
            return $params['index'] === 'laraplate_rag_docs_test'
                && $params['body']['knn']['k'] === 2
                && $params['body']['knn']['field'] === 'embedding';
        }))
        ->andReturn(make_elasticsearch_response([
            'hits' => [
                'hits' => [
                    [
                        '_id' => 'doc-1',
                        '_score' => 0.91,
                        '_source' => [
                            'content' => 'First chunk',
                            'sourceType' => 'file',
                            'sourceName' => 'faq-module-AI',
                            'embedding' => [0.1, 0.2, 0.3],
                            'metadata' => ['section' => 'intro'],
                            'neuron_id' => 'doc-1',
                        ],
                    ],
                    [
                        '_id' => 'doc-2',
                        '_score' => 0.82,
                        '_source' => [
                            'content' => 'Second chunk',
                            'sourceType' => 'file',
                            'sourceName' => 'faq-module-Core',
                            'embedding' => [0.4, 0.5, 0.6],
                            'metadata' => [],
                            'neuron_id' => 'doc-2',
                        ],
                    ],
                ],
            ],
        ]));

    $store = make_rag_vector_store($client);
    $documents = iterator_to_array($store->search(new SearchRequest([0.1, 0.2, 0.3])), false);

    expect($documents)->toHaveCount(2)
        ->and($documents[0]->getContent())->toBe('First chunk')
        ->and($documents[0]->getSourceName())->toBe('faq-module-AI')
        ->and($documents[0]->getScore())->toBe(0.91)
        ->and($documents[1]->getContent())->toBe('Second chunk');
});

test('a hit written by neuron 3 maps back to a document with its content, source and metadata', function (): void {
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('search')->once()->andReturn(make_elasticsearch_response([
        'hits' => [
            'hits' => [
                [
                    '_id' => 'es-id',
                    '_score' => 0.5,
                    '_source' => [
                        'content' => 'Written by 3.x',
                        'sourceType' => 'file',
                        'sourceName' => 'faq-module-AI',
                        'embedding' => [0.1, 0.2, 0.3],
                        'metadata' => ['audience' => 'user', 'module' => 'AI', 'heading_breadcrumb' => 'Setup > Models'],
                        'neuron_id' => 'chunk-1',
                        'indexed_at' => '2026-10-01T10:00:00+00:00',
                    ],
                ],
            ],
        ],
    ]));

    $document = iterator_to_array(make_rag_vector_store($client)->search(new SearchRequest([0.1, 0.2, 0.3])), false)[0];

    expect($document->getId())->toBe('chunk-1')
        ->and($document->getContent())->toBe('Written by 3.x')
        ->and($document->getSourceType())->toBe('file')
        ->and($document->getSourceName())->toBe('faq-module-AI')
        ->and($document->getMetadata())->toBe(['audience' => 'user', 'module' => 'AI', 'heading_breadcrumb' => 'Setup > Models'])
        ->and($document->getEmbedding())->toBe([0.1, 0.2, 0.3]);
});

test('search takes the result limit of the request over the one of the store', function (): void {
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('search')
        ->once()
        ->with(Mockery::on(fn (array $params): bool => $params['body']['size'] === 4
            && $params['body']['knn']['k'] === 4
            && $params['body']['knn']['num_candidates'] === 40))
        ->andReturn(make_elasticsearch_response(['hits' => ['hits' => []]]));

    iterator_to_array(make_rag_vector_store($client)->search(new SearchRequest([0.1, 0.2, 0.3], topK: 4)), false);

    expect(true)->toBeTrue();
});

test('the store declares the default document schema', function (): void {
    expect(make_rag_vector_store(Mockery::mock(Client::class))->getSchema())->toBeInstanceOf(DocumentSchema::class);
});

/**
 * A client whose index accepts the mapping of the source fingerprint, which a store adds before its first write.
 */
function rag_client_accepting_the_fingerprint_mapping(): Client
{
    $indices = Mockery::mock(Indices::class);
    $indices->shouldReceive('putMapping')->andReturn(make_elasticsearch_response(['acknowledged' => true]));

    $client = Mockery::mock(Client::class);
    $client->shouldReceive('indices')->andReturn($indices);

    return $client;
}

test('add documents sends bulk index operations', function (): void {
    $client = rag_client_accepting_the_fingerprint_mapping();
    $client->shouldReceive('bulk')
        ->once()
        ->with(Mockery::on(function (array $params): bool {
            $body = $params['body'] ?? [];

            return count($body) === 2
                && ($body[0]['index']['_index'] ?? null) === 'laraplate_rag_docs_test'
                && ($body[1]['content'] ?? null) === 'Chunk body'
                && ($body[1]['sourceType'] ?? null) === 'file';
        }))
        ->andReturn(make_elasticsearch_response(['errors' => false]));

    $document = (new Document('Chunk body'))
        ->setSourceType('file')
        ->setSourceName('faq-module-AI')
        ->setEmbedding([0.1, 0.2, 0.3]);

    $store = make_rag_vector_store($client);
    $store->addDocuments([$document]);

    expect(true)->toBeTrue();
});

test('add document refuses a document without an embedding, and sends nothing to elasticsearch', function (): void {
    $client = Mockery::mock(Client::class);
    $client->shouldNotReceive('bulk');

    $document = (new Document('No vector yet'))->setSourceType('file')->setSourceName('faq-module-AI');

    expect(fn () => make_rag_vector_store($client)->addDocument($document))
        ->toThrow(VectorStoreException::class, 'must have an embedding');
});

test('delete by source type and name issues delete by query', function (): void {
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('deleteByQuery')
        ->once()
        ->with(Mockery::on(function (array $params): bool {
            $must = $params['body']['query']['bool']['must'] ?? [];

            return $params['index'] === 'laraplate_rag_docs_test'
                && count($must) === 2
                && ($must[0]['term']['sourceType'] ?? null) === 'file'
                && ($must[1]['term']['sourceName'] ?? null) === 'faq-module-AI';
        }))
        ->andReturn(make_elasticsearch_response(['deleted' => 1]));

    $store = make_rag_vector_store($client);
    $store->delete(Filter::where('sourceType', 'file')->where('sourceName', 'faq-module-AI'));

    expect(true)->toBeTrue();
});

test('delete by source type only matches file vector store semantics', function (): void {
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('deleteByQuery')
        ->once()
        ->with(Mockery::on(function (array $params): bool {
            $must = $params['body']['query']['bool']['must'] ?? [];

            return count($must) === 1
                && ($must[0]['term']['sourceType'] ?? null) === 'file';
        }))
        ->andReturn(make_elasticsearch_response(['deleted' => 2]));

    $store = make_rag_vector_store($client);
    $store->delete(Filter::eq('sourceType', 'file'));

    expect(true)->toBeTrue();
});

test('delete refuses a filter on a field the store does not know, and sends nothing', function (): void {
    $store = make_rag_vector_store(Mockery::mock(Client::class));

    expect(fn () => $store->delete(Filter::eq('tenant', 'acme')))->toThrow(DocumentSchemaException::class);
});

test('similarity search fails fast on elasticsearch errors', function (): void {
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('search')
        ->once()
        ->andThrow(new RuntimeException('cluster unavailable'));

    $store = make_rag_vector_store($client);

    expect(fn () => iterator_to_array($store->search(new SearchRequest([0.1, 0.2, 0.3])), false))
        ->toThrow(VectorStoreException::class, 'Elasticsearch RAG similarity search failed');
});

function document_read_context(): DocumentationRetrievalContext
{
    return DocumentationRetrievalContext::fromAccessContext(new AssistantAccessContext(
        profile: AssistantProfile::InAppAssistance,
        userId: '7',
        tenantScope: AssistantTenantScope::Global,
        tenantId: null,
        locale: 'it',
        effectivePermissions: ['cms.content.view'],
        conversationId: '11',
    ));
}

test('a document read filters on the access rules of the search and the document key, in section order', function (): void {
    $key = 'd_' . str_repeat('a', 32);
    $context = document_read_context();
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('search')
        ->once()
        ->with(Mockery::on(function (array $params) use ($key, $context): bool {
            $filter = $params['body']['query']['bool']['filter'];

            return $params['index'] === 'laraplate_rag_user_docs_test'
                && $filter[0] === $context->elasticsearchFilter('in-app-docs-v1')
                && $filter[1] === ['term' => ['metadata.document_key' => $key]]
                && $params['body']['size'] === ElasticsearchRagVectorStore::MAX_READ_SECTIONS
                && $params['body']['sort'][0]['metadata.section']['order'] === 'asc'
                && $params['body']['post_filter'] === ['range' => ['metadata.section' => ['gte' => 3, 'lte' => 5]]]
                && $params['body']['aggs']['last_section']['max']['field'] === 'metadata.section'
                && ! isset($params['body']['knn']);
        }))
        ->andReturn(make_elasticsearch_response([
            'hits' => ['hits' => [
                ['_id' => 'a', '_source' => ['content' => 'Third.', 'sourceType' => 'files', 'sourceName' => 'Guide', 'metadata' => ['section' => 3], 'neuron_id' => 'a']],
                ['_id' => 'b', '_source' => ['content' => 'Fourth.', 'sourceType' => 'files', 'sourceName' => 'Guide', 'metadata' => ['section' => 4], 'neuron_id' => 'b']],
            ]],
            'aggregations' => ['last_section' => ['value' => 8.0]],
        ]));

    $result = make_rag_vector_store($client, DocumentationIndexProfile::User)->chunksOfDocument($key, $context, 3, 5);

    expect($result['total'])->toBe(9)
        ->and(array_map(static fn (Document $document): string => $document->getContent(), $result['documents']))->toBe(['Third.', 'Fourth.']);
});

test('a document read without bounds asks for the whole document', function (): void {
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('search')
        ->once()
        ->with(Mockery::on(fn (array $params): bool => ! isset($params['body']['post_filter'])))
        ->andReturn(make_elasticsearch_response(['hits' => ['hits' => []]]));

    $result = make_rag_vector_store($client, DocumentationIndexProfile::User)->chunksOfDocument('d_' . str_repeat('a', 32), document_read_context());

    expect($result)->toBe(['documents' => [], 'total' => null]);
});

test('a document read is only for the user index and reports a failing search', function (): void {
    $key = 'd_' . str_repeat('a', 32);

    expect(fn () => make_rag_vector_store(Mockery::mock(Client::class))->chunksOfDocument($key, document_read_context()))
        ->toThrow(InvalidArgumentException::class);

    $client = Mockery::mock(Client::class);
    $client->shouldReceive('search')->once()->andThrow(new RuntimeException('down'));

    expect(fn () => make_rag_vector_store($client, DocumentationIndexProfile::User)->chunksOfDocument($key, document_read_context()))
        ->toThrow(VectorStoreException::class);
});

test('index mappings declare the source fingerprint as a keyword', function (): void {
    $mappings = ElasticsearchRagVectorStore::indexMappings(3);

    expect($mappings['properties']['metadata']['properties'][ElasticsearchRagVectorStore::SOURCE_HASH])->toBe(['type' => 'keyword']);
});

test('the store maps the source fingerprint once, before its first write', function (): void {
    $indices = Mockery::mock(Indices::class);
    $indices->shouldReceive('putMapping')
        ->once()
        ->with([
            'index' => 'laraplate_rag_docs_test',
            'body' => ['properties' => ['metadata' => ['properties' => ['source_hash' => ['type' => 'keyword']]]]],
        ])
        ->andReturn(make_elasticsearch_response(['acknowledged' => true]));

    $client = Mockery::mock(Client::class);
    $client->shouldReceive('indices')->andReturn($indices);
    $client->shouldReceive('bulk')->twice()->andReturn(make_elasticsearch_response(['errors' => false]));

    $store = make_rag_vector_store($client);
    $document = (new Document('Chunk body'))->setSourceType('file')->setSourceName('faq-module-AI')->setEmbedding([0.1, 0.2, 0.3]);

    $store->addDocuments([$document]);
    $store->addDocuments([$document]);

    expect(true)->toBeTrue();
});

test('a write fails, and sends nothing, when the fingerprint mapping is refused', function (): void {
    $indices = Mockery::mock(Indices::class);
    $indices->shouldReceive('putMapping')->once()->andThrow(new RuntimeException('mapper_parsing_exception'));

    $client = Mockery::mock(Client::class);
    $client->shouldReceive('indices')->andReturn($indices);
    $client->shouldNotReceive('bulk');

    $document = (new Document('Chunk body'))->setSourceType('file')->setSourceName('faq-module-AI')->setEmbedding([0.1, 0.2, 0.3]);

    expect(fn () => make_rag_vector_store($client)->addDocuments([$document]))
        ->toThrow(VectorStoreException::class, 'mapper_parsing_exception');
});

test('source fingerprints give the hash and chunk count of each source, and no hash when its chunks disagree', function (): void {
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('count')->once()->andReturn(make_elasticsearch_response(['count' => 9]));
    $client->shouldReceive('search')
        ->once()
        ->with(Mockery::on(fn (array $params): bool => $params['index'] === 'laraplate_rag_docs_test'
            && ($params['body']['size'] ?? null) === 0
            && ($params['body']['aggs']['sources']['terms']['field'] ?? null) === 'sourceName'
            && ($params['body']['aggs']['sources']['aggs']['hashes']['terms']['field'] ?? null) === 'metadata.source_hash'))
        ->andReturn(make_elasticsearch_response(['aggregations' => ['sources' => [
            'sum_other_doc_count' => 0,
            'buckets' => [
                ['key' => 'faq-module-A', 'doc_count' => 3, 'hashes' => ['buckets' => [['key' => 'h-a', 'doc_count' => 3]]]],
                ['key' => 'faq-module-B', 'doc_count' => 4, 'hashes' => ['buckets' => [['key' => 'h-1', 'doc_count' => 2], ['key' => 'h-2', 'doc_count' => 2]]]],
                ['key' => 'faq-module-C', 'doc_count' => 2, 'hashes' => ['buckets' => []]],
                ['key' => 'faq-module-D', 'doc_count' => 5, 'hashes' => ['buckets' => [['key' => 'h-d', 'doc_count' => 3]]]],
            ],
        ]]]));

    expect(make_rag_vector_store($client)->sourceFingerprints())->toBe([
        'faq-module-A' => ['hash' => 'h-a', 'chunks' => 3],
        'faq-module-B' => ['hash' => null, 'chunks' => 4],
        'faq-module-C' => ['hash' => null, 'chunks' => 2],
        'faq-module-D' => ['hash' => null, 'chunks' => 5],
    ]);
});

test('source fingerprints are empty when the index does not exist, and are not searched for', function (): void {
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('count')->once()->andThrow(new RuntimeException('index_not_found_exception'));
    $client->shouldNotReceive('search');

    expect(make_rag_vector_store($client)->sourceFingerprints())->toBe([]);
});

test('source fingerprints refuse an index with more sources than one read returns', function (): void {
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('count')->once()->andReturn(make_elasticsearch_response(['count' => 9]));
    $client->shouldReceive('search')->once()->andReturn(make_elasticsearch_response(['aggregations' => ['sources' => [
        'sum_other_doc_count' => 12,
        'buckets' => [],
    ]]]));

    expect(fn () => make_rag_vector_store($client)->sourceFingerprints())
        ->toThrow(VectorStoreException::class, 'more than 10000 sources');
});

test('source fingerprints fail loudly when the read fails', function (): void {
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('count')->once()->andReturn(make_elasticsearch_response(['count' => 9]));
    $client->shouldReceive('search')->once()->andThrow(new RuntimeException('cluster down'));

    expect(fn () => make_rag_vector_store($client)->sourceFingerprints())
        ->toThrow(VectorStoreException::class, 'cluster down');
});

test('delete sources not in a list removes every other source and reports how many chunks went', function (): void {
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('deleteByQuery')
        ->once()
        ->with([
            'index' => 'laraplate_rag_docs_test',
            'conflicts' => 'proceed',
            'body' => ['query' => ['bool' => ['must_not' => [['terms' => ['sourceName' => ['faq-module-A', 'faq-module-B']]]]]]],
        ])
        ->andReturn(make_elasticsearch_response(['deleted' => 7]));

    expect(make_rag_vector_store($client)->deleteSourcesNotIn(['faq-module-A', 'faq-module-B']))->toBe(7);
});

test('delete sources not in an empty list is refused, and nothing is deleted', function (): void {
    $client = Mockery::mock(Client::class);
    $client->shouldNotReceive('deleteByQuery');

    expect(fn () => make_rag_vector_store($client)->deleteSourcesNotIn([]))
        ->toThrow(InvalidArgumentException::class, 'would empty the index');
});

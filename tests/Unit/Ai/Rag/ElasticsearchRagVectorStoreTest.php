<?php

declare(strict_types=1);

use Elastic\Elasticsearch\Client;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Ai\Rag\ElasticsearchRagVectorStore;
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
            'source_type',
            'policy_classification_version',
        ]);
    expect($mappings['properties']['metadata']['properties']['source_type'])->toBe(['type' => 'keyword']);
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

test('add documents sends bulk index operations', function (): void {
    $client = Mockery::mock(Client::class);
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

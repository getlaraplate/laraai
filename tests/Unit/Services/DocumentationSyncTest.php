<?php

declare(strict_types=1);

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Endpoints\Indices;
use Modules\AI\Ai\Agents\DocumentationAgent;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Ai\Rag\ElasticsearchRagVectorStore;
use Modules\AI\Services\DocumentationService;
use NeuronAI\RAG\Document;

/**
 * A documentation directory with one developer page.
 */
function sync_docs_directory(string $body = 'First body.'): string
{
    $directory = sys_get_temp_dir() . '/ai-docs-sync-' . uniqid();
    mkdir($directory, 0755, true);
    file_put_contents($directory . '/page.md', "---\nmodule: core\naudience: developer\n---\n# Page\n\n{$body}");

    return $directory;
}

function sync_remove_directory(string $directory): void
{
    File::deleteDirectory($directory);
}

/**
 * A client for an index that reports `$fingerprints` as its sources: each entry is a source name with the hash and
 * chunk count its chunks carry.
 *
 * @param  array<string, array{hash: string|null, chunks: int}>  $fingerprints
 */
function sync_client(array $fingerprints = []): Client
{
    $indices = Mockery::mock(Indices::class);
    $indices->shouldReceive('putMapping')->andReturn(make_elasticsearch_response(['acknowledged' => true]));

    $buckets = [];

    foreach ($fingerprints as $name => $fingerprint) {
        $buckets[] = [
            'key' => (string) $name,
            'doc_count' => $fingerprint['chunks'],
            'hashes' => ['buckets' => $fingerprint['hash'] === null ? [] : [['key' => $fingerprint['hash'], 'doc_count' => $fingerprint['chunks']]]],
        ];
    }

    $client = Mockery::mock(Client::class);
    $client->shouldReceive('indices')->andReturn($indices);
    $client->shouldReceive('count')->andReturn(make_elasticsearch_response(['count' => count($fingerprints)]));
    $client->shouldReceive('search')->andReturn(make_elasticsearch_response(['aggregations' => ['sources' => [
        'sum_other_doc_count' => 0,
        'buckets' => $buckets,
    ]]]));

    return $client;
}

function sync_service(Client $client, DocumentationAgent $agent, ?Closure $ragPaths = null): DocumentationService
{
    config()->set('ai.features.faq.vector_store', 'elasticsearch');
    config()->set('ai.features.faq.elasticsearch.developer_index', 'laraplate_rag_docs_test');
    config()->set('ai.features.faq.elasticsearch.user_index', 'laraplate_rag_user_docs_test');

    return new DocumentationService(
        agentFactory: static fn (): DocumentationAgent => $agent,
        ragPathsResolver: $ragPaths,
        embeddingsProbe: static fn (): array => [0.1],
        storeFactory: static fn (DocumentationIndexProfile $profile): ElasticsearchRagVectorStore => new ElasticsearchRagVectorStore($client, $profile, 1, 3),
    );
}

/**
 * Runs a diff of `$directory` against an empty index and returns the chunks the agent was given, which carry the
 * fingerprint the next run compares with.
 *
 * @return list<Document>
 */
function sync_first_chunks(string $directory): array
{
    $written = [];
    $agent = Mockery::mock(DocumentationAgent::class);
    $agent->shouldReceive('reindexBySource')->once()->andReturnUsing(function (array $documents) use (&$written): void {
        $written = $documents;
    });

    sync_service(sync_client(), $agent)->syncDocuments($directory);

    return $written;
}

it('writes a source that is not in the index yet, with its fingerprint, and reports it', function (): void {
    $directory = sync_docs_directory();

    try {
        $written = [];
        $agent = Mockery::mock(DocumentationAgent::class);
        $agent->shouldReceive('reindexBySource')->once()->andReturnUsing(function (array $documents) use (&$written): void {
            $written = $documents;
        });
        $agent->shouldNotReceive('addDocuments');

        $report = sync_service(sync_client(), $agent)->syncDocuments($directory);

        expect($report)->toBe(['sources' => 1, 'changed' => 1, 'unchanged' => 0, 'removed' => 0, 'chunks' => count($written)])
            ->and($written)->not->toBeEmpty()
            ->and($written[0]->getMetadata()[ElasticsearchRagVectorStore::SOURCE_HASH])->toBeString()->toHaveLength(64);
    } finally {
        sync_remove_directory($directory);
    }
});

it('leaves a source alone, and embeds nothing, when its fingerprint is unchanged', function (): void {
    $directory = sync_docs_directory();

    try {
        $first = sync_first_chunks($directory);
        $indexed = [$first[0]->getSourceName() => ['hash' => $first[0]->getMetadata()[ElasticsearchRagVectorStore::SOURCE_HASH], 'chunks' => count($first)]];

        $agent = Mockery::mock(DocumentationAgent::class);
        $agent->shouldNotReceive('reindexBySource');
        $agent->shouldNotReceive('addDocuments');

        $probed = 0;
        $service = new DocumentationService(
            agentFactory: static fn (): DocumentationAgent => $agent,
            embeddingsProbe: static function () use (&$probed): array {
                $probed++;

                return [0.1];
            },
            storeFactory: static fn (DocumentationIndexProfile $profile): ElasticsearchRagVectorStore => new ElasticsearchRagVectorStore(sync_client($indexed), $profile, 1, 3),
        );

        $report = $service->syncDocuments($directory);

        expect($report)->toBe(['sources' => 1, 'changed' => 0, 'unchanged' => 1, 'removed' => 0, 'chunks' => 0])
            ->and($probed)->toBe(0);
    } finally {
        sync_remove_directory($directory);
    }
});

it('replaces a source whose text changed since it was indexed', function (): void {
    $directory = sync_docs_directory();

    try {
        $first = sync_first_chunks($directory);
        $indexed = [$first[0]->getSourceName() => ['hash' => $first[0]->getMetadata()[ElasticsearchRagVectorStore::SOURCE_HASH], 'chunks' => count($first)]];

        file_put_contents($directory . '/page.md', "---\nmodule: core\naudience: developer\n---\n# Page\n\nSecond body, edited.");

        $agent = Mockery::mock(DocumentationAgent::class);
        $agent->shouldReceive('reindexBySource')->once();

        $report = sync_service(sync_client($indexed), $agent)->syncDocuments($directory);

        expect($report['changed'])->toBe(1)
            ->and($report['unchanged'])->toBe(0);
    } finally {
        sync_remove_directory($directory);
    }
});

it('replaces a source indexed before fingerprints existed, or whose chunks disagree', function (): void {
    $directory = sync_docs_directory();

    try {
        $first = sync_first_chunks($directory);
        $name = $first[0]->getSourceName();

        foreach ([['hash' => null, 'chunks' => count($first)], ['hash' => $first[0]->getMetadata()[ElasticsearchRagVectorStore::SOURCE_HASH], 'chunks' => count($first) + 1]] as $fingerprint) {
            $agent = Mockery::mock(DocumentationAgent::class);
            $agent->shouldReceive('reindexBySource')->once();

            expect(sync_service(sync_client([$name => $fingerprint]), $agent)->syncDocuments($directory)['changed'])->toBe(1);
        }
    } finally {
        sync_remove_directory($directory);
    }
});

it('replaces a source when the embedding model changed, because the model is part of the fingerprint', function (): void {
    $directory = sync_docs_directory();

    try {
        $first = sync_first_chunks($directory);
        $indexed = [$first[0]->getSourceName() => ['hash' => $first[0]->getMetadata()[ElasticsearchRagVectorStore::SOURCE_HASH], 'chunks' => count($first)]];

        $other = app(Modules\AI\Ai\Embeddings\EmbeddingModelRegistry::class)->keys();
        $registry = app(Modules\AI\Ai\Embeddings\EmbeddingModelRegistry::class);
        $current = $registry->active()->key;
        $alternative = collect($other)->first(fn (string $key): bool => $key !== $current);

        if ($alternative === null) {
            $this->markTestSkipped('Only one embedding profile is registered.');
        }

        $agent = Mockery::mock(DocumentationAgent::class);
        $agent->shouldReceive('reindexBySource')->once();

        $registry->withActive($alternative, fn (): array => sync_service(sync_client($indexed), $agent)->syncDocuments($directory));
    } finally {
        sync_remove_directory($directory);
    }
});

it('removes the sources the documentation no longer has, and keeps every source it has', function (): void {
    $kept = [];
    $agent = Mockery::mock(DocumentationAgent::class);
    $agent->shouldReceive('reindexBySource')->andReturnNull();

    $client = sync_client(['faq-module-gone/Old.md' => ['hash' => 'old', 'chunks' => 2]]);
    $client->shouldReceive('deleteByQuery')
        ->once()
        ->with(Mockery::on(function (array $params) use (&$kept): bool {
            $kept = $params['body']['query']['bool']['must_not'][0]['terms']['sourceName'] ?? [];

            return true;
        }))
        ->andReturn(make_elasticsearch_response(['deleted' => 2]));

    $report = sync_service($client, $agent)->syncDocuments();

    expect($report['removed'])->toBe(2)
        ->and($report['sources'])->toBe(count($kept))
        ->and($kept)->not->toBeEmpty()
        ->and($kept)->not->toContain('faq-module-gone/Old.md');
});

it('removes nothing when the run is limited to a path', function (): void {
    $directory = sync_docs_directory();

    try {
        $agent = Mockery::mock(DocumentationAgent::class);
        $agent->shouldReceive('reindexBySource')->once();

        $client = sync_client(['faq-module-gone/Old.md' => ['hash' => 'old', 'chunks' => 2]]);
        $client->shouldNotReceive('deleteByQuery');

        expect(sync_service($client, $agent)->syncDocuments($directory)['removed'])->toBe(0);
    } finally {
        sync_remove_directory($directory);
    }
});

it('deletes nothing when no documentation source is found at all', function (): void {
    $agent = Mockery::mock(DocumentationAgent::class);
    $agent->shouldNotReceive('reindexBySource');

    $client = sync_client(['faq-module-A/A.md' => ['hash' => 'a', 'chunks' => 2]]);
    $client->shouldNotReceive('deleteByQuery');

    $report = sync_service($client, $agent, static fn (): bool => false)->syncDocuments();

    expect($report)->toBe(['sources' => 0, 'changed' => 0, 'unchanged' => 0, 'removed' => 0, 'chunks' => 0]);
});

it('refuses a diff when the vector store is not elasticsearch', function (): void {
    config()->set('ai.features.faq.vector_store', 'memory');

    expect(fn () => (new DocumentationService)->syncDocuments())
        ->toThrow(RuntimeException::class, 'needs the elasticsearch vector store');
});

it('leaves the index as it is when the embeddings do not answer during a diff', function (): void {
    $directory = sync_docs_directory();

    try {
        $agent = Mockery::mock(DocumentationAgent::class);
        $agent->shouldNotReceive('reindexBySource');

        config()->set('ai.features.faq.vector_store', 'elasticsearch');
        $client = sync_client();
        $client->shouldNotReceive('deleteByQuery');

        $service = new DocumentationService(
            agentFactory: static fn (): DocumentationAgent => $agent,
            embeddingsProbe: static fn () => throw new RuntimeException('connection refused'),
            storeFactory: static fn (DocumentationIndexProfile $profile): ElasticsearchRagVectorStore => new ElasticsearchRagVectorStore($client, $profile, 1, 3),
        );

        expect(fn () => $service->syncDocuments($directory))
            ->toThrow(RuntimeException::class, 'the index was left as it is: connection refused');
    } finally {
        sync_remove_directory($directory);
    }
});

it('rebuilds an elasticsearch index in full without ever emptying it up front', function (): void {
    $directory = sync_docs_directory();

    try {
        $agent = Mockery::mock(DocumentationAgent::class);
        $agent->shouldReceive('reindexBySource')->once();
        $agent->shouldNotReceive('addDocuments');

        $client = sync_client(['faq-old' => ['hash' => 'old', 'chunks' => 4]]);
        $client->shouldNotReceive('deleteByQuery');

        $count = sync_service($client, $agent)->indexDocuments($directory, true, DocumentationIndexProfile::Developer);

        expect($count)->toBeGreaterThan(0);
    } finally {
        sync_remove_directory($directory);
    }
});

it('removes, after a full rebuild, the sources the documentation no longer has', function (): void {
    $agent = Mockery::mock(DocumentationAgent::class);
    $agent->shouldReceive('reindexBySource')->andReturnNull();
    $agent->shouldNotReceive('addDocuments');

    $deleted_with = null;
    $client = sync_client(['faq-module-gone/Old.md' => ['hash' => 'old', 'chunks' => 2]]);
    $client->shouldReceive('deleteByQuery')
        ->once()
        ->with(Mockery::on(function (array $params) use (&$deleted_with): bool {
            $deleted_with = $params['body']['query'];

            return true;
        }))
        ->andReturn(make_elasticsearch_response(['deleted' => 2]));

    sync_service($client, $agent)->indexDocuments(null, true, DocumentationIndexProfile::Developer);

    expect($deleted_with)->toHaveKey('bool.must_not')
        ->and($deleted_with)->not->toHaveKey('match_all');
});

it('keeps the index of a full rebuild that found no documentation, instead of emptying it', function (): void {
    $agent = Mockery::mock(DocumentationAgent::class);
    $agent->shouldNotReceive('reindexBySource');

    $client = sync_client(['faq-module-A/A.md' => ['hash' => 'a', 'chunks' => 2]]);
    $client->shouldNotReceive('deleteByQuery');

    expect(sync_service($client, $agent, static fn (): bool => false)->indexDocuments(null, true, DocumentationIndexProfile::Developer))->toBe(0);
});

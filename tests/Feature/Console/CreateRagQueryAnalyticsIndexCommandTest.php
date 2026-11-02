<?php

declare(strict_types=1);

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Endpoints\Indices;

/**
 * @return array{client: Client&Mockery\MockInterface, indices: Indices&Mockery\MockInterface}
 */
function rag_query_index_client(bool $exists): array
{
    config()->set('ai.features.faq.query_logging.index', 'laraplate_rag_queries_test');

    $indices = Mockery::mock(Indices::class);
    $indices->shouldReceive('exists')
        ->with(['index' => 'laraplate_rag_queries_test'])
        ->andReturn(make_elasticsearch_response([], $exists ? 200 : 404));

    $client = Mockery::mock(Client::class);
    $client->shouldReceive('indices')->andReturn($indices);
    app()->instance(Client::class, $client);

    return ['client' => $client, 'indices' => $indices];
}

it('creates the query log index with a strict mapping and no vector', function (): void {
    ['indices' => $indices] = rag_query_index_client(exists: false);
    $created = null;
    $indices->shouldReceive('create')->once()->withArgs(function (array $params) use (&$created): bool {
        $created = $params;

        return true;
    })->andReturn(make_elasticsearch_response(['acknowledged' => true]));

    $this->artisan('ai:create-rag-query-index')->assertExitCode(0);

    $properties = $created['body']['mappings']['properties'];

    expect($created['index'])->toBe('laraplate_rag_queries_test')
        ->and($created['body']['mappings']['dynamic'])->toBe('strict')
        ->and(array_keys($properties))->toEqualCanonicalizing([
            'id', 'logged_at', 'user_ref', 'tenant', 'profile', 'locale', 'query',
            'retrieved_count', 'citation_count', 'answered', 'latency_ms', 'index',
            'judge_rounds', 'judge_candidates', 'judge_kept', 'judge_fallback', 'judge_ms', 'judge_trace', 'judge_queries',
            'tokens_in_answer', 'tokens_out_answer', 'tokens_in_judge', 'tokens_out_judge',
            'tokens_in_intent', 'tokens_out_intent', 'tokens_in_embedding', 'tokens_out_embedding',
        ])
        ->and(array_column($properties, 'type'))->not->toContain('dense_vector');
});

it('leaves an existing index alone without --force', function (): void {
    ['indices' => $indices] = rag_query_index_client(exists: true);
    $indices->shouldNotReceive('create');
    $indices->shouldNotReceive('delete');

    $this->artisan('ai:create-rag-query-index')
        ->expectsOutputToContain('already exists')
        ->assertExitCode(0);
});

it('recreates an existing index with --force', function (): void {
    ['indices' => $indices] = rag_query_index_client(exists: true);
    $indices->shouldReceive('delete')->once()->with(['index' => 'laraplate_rag_queries_test'])
        ->andReturn(make_elasticsearch_response(['acknowledged' => true]));
    $indices->shouldReceive('create')->once()->andReturn(make_elasticsearch_response(['acknowledged' => true]));

    $this->artisan('ai:create-rag-query-index', ['--force' => true])->assertExitCode(0);
});

it('fails when Elasticsearch cannot be reached', function (): void {
    config()->set('ai.features.faq.query_logging.index', 'laraplate_rag_queries_test');
    $indices = Mockery::mock(Indices::class);
    $indices->shouldReceive('exists')->andThrow(new RuntimeException('connection refused'));
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('indices')->andReturn($indices);
    app()->instance(Client::class, $client);

    $this->artisan('ai:create-rag-query-index')
        ->expectsOutputToContain('connection refused')
        ->assertExitCode(1);
});

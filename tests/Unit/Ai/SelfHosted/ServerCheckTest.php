<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\AI\Ai\SelfHosted\ServerCheck;

/**
 * Replaces every fake of the test (Http::fake() registrations are additive and the first match wins).
 *
 * @param  array<string, mixed>  $routes
 */
function server_check_fake(array $routes): void
{
    Http::swap(new Factory());
    Http::preventStrayRequests();
    Http::fake($routes);
}

/**
 * @return array<string, mixed>
 */
function server_check_embeddings(?string $model = 'intfloat/multilingual-e5-small', ?array $vector = null): array
{
    return ['object' => 'list', ...($model === null ? [] : ['model' => $model]), 'data' => [['index' => 0, 'embedding' => $vector ?? ai_test_unit_vector(384)]]];
}

beforeEach(function (): void {
    config()->set('ai.servers', [
        'embeddings' => ['url' => 'http://tei.test:8080', 'api_key' => null, 'timeout' => 30, 'batch_size' => 32],
        'embeddings_minilm' => ['url' => null, 'api_key' => null],
        'rerank' => ['url' => 'http://rerank.test:8081', 'api_key' => null, 'dialect' => 'tei', 'model' => null],
        'transcription' => ['url' => 'http://speaches.test:8000', 'api_key' => null, 'timeout' => 120],
    ]);
    config()->set('core.search.vector.model', 'openai_compatible:intfloat/multilingual-e5-small');
    config()->set('ai.features.embeddings.models', [
        'openai_compatible:intfloat/multilingual-e5-small' => ['dimensions' => 384, 'normalize' => true],
    ]);
});

it('lists every configured server with its capability, its url without credentials and the result of its probe', function (): void {
    config()->set('ai.servers.embeddings.url', 'http://user:pw@tei.test:8080');
    server_check_fake([
        'tei.test:8080/v1/embeddings' => Http::response(server_check_embeddings()),
        'tei.test:8080/info' => Http::response(['model_id' => 'intfloat/multilingual-e5-small', 'max_client_batch_size' => 32]),
        'rerank.test:8081/rerank' => Http::response([['index' => 0, 'score' => 0.9], ['index' => 1, 'score' => 0.1]]),
        'rerank.test:8081/info' => Http::response(['model_id' => 'BAAI/bge-reranker-base', 'max_client_batch_size' => 64]),
    ]);

    $rows = new ServerCheck()->run();

    expect(array_column($rows, 'capability'))->toBe(['embeddings', 'rerank', 'transcription'])
        ->and(array_column($rows, 'server'))->toBe(['embeddings', 'rerank', 'transcription'])
        ->and(array_column($rows, 'ok'))->toBe([true, true, true])
        ->and($rows[0]['url'])->toBe('http://tei.test:8080')
        ->and($rows[0]['detail'])->toContain('intfloat/multilingual-e5-small')->toContain('384')
        ->and($rows[1]['detail'])->toContain('BAAI/bge-reranker-base')
        ->and(json_encode($rows))->not->toContain('pw@');
});

it('fails an embeddings server that answers the wrong model, dimensions or norm', function (string $case, array $answer, string $reason): void {
    server_check_fake([
        'tei.test:8080/v1/embeddings' => Http::response($answer),
        'tei.test:8080/info' => Http::response('not found', 404),
        'rerank.test:8081/*' => Http::response([['index' => 0, 'score' => 0.9], ['index' => 1, 'score' => 0.1]]),
    ]);

    $row = new ServerCheck()->run()[0];

    expect($row['ok'])->toBeFalse()->and($row['detail'])->toContain($reason);
})->with([
    'the wrong model' => ['model', server_check_embeddings('BAAI/bge-m3'), 'BAAI/bge-m3'],
    'the wrong dimensions' => ['dimensions', server_check_embeddings(vector: [0.1, 0.2]), 'dimensions'],
    'an unnormalised vector' => ['norm', server_check_embeddings(vector: array_fill(0, 384, 0.5)), 'not normalised'],
]);

it('fails an embeddings server that cannot be reached, with the reason', function (): void {
    server_check_fake([
        'tei.test:8080/*' => static fn () => throw new ConnectionException('connection refused'),
        'rerank.test:8081/*' => Http::response([['index' => 0, 'score' => 0.9], ['index' => 1, 'score' => 0.1]]),
    ]);

    $row = new ServerCheck()->run()[0];

    expect($row['ok'])->toBeFalse()->and($row['detail'])->toContain('connection refused');
});

it('probes the reranker with two fixed pairs and expects two numbers', function (): void {
    server_check_fake([
        'tei.test:8080/v1/embeddings' => Http::response(server_check_embeddings()),
        'tei.test:8080/info' => Http::response('not found', 404),
        'rerank.test:8081/rerank' => Http::response([['index' => 0, 'score' => 0.9], ['index' => 1, 'score' => 0.1]]),
        'rerank.test:8081/info' => Http::response('not found', 404),
    ]);

    $rows = new ServerCheck()->run();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/rerank') && count($request['texts']) === 2 && $request['query'] !== '');

    expect($rows[1]['ok'])->toBeTrue();
});

it('fails a reranker that answers something else than one score per pair', function (): void {
    server_check_fake([
        'tei.test:8080/v1/embeddings' => Http::response(server_check_embeddings()),
        'tei.test:8080/info' => Http::response('not found', 404),
        'rerank.test:8081/rerank' => Http::response(['oops' => true]),
    ]);

    $row = new ServerCheck()->run()[1];

    expect($row['ok'])->toBeFalse()->and($row['detail'])->toContain('malformed');
});

it('does not probe transcription: it would need audio', function (): void {
    server_check_fake([
        'tei.test:8080/v1/embeddings' => Http::response(server_check_embeddings()),
        'tei.test:8080/info' => Http::response('not found', 404),
        'rerank.test:8081/*' => Http::response([['index' => 0, 'score' => 0.9], ['index' => 1, 'score' => 0.1]]),
    ]);

    $row = new ServerCheck()->run()[2];

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'speaches.test'));

    expect($row['ok'])->toBeTrue()->and($row['detail'])->toContain('not probed');
});

it('says when the batch limit a tei server reports is lower than the configured batch size or than 64 for the reranker', function (): void {
    server_check_fake([
        'tei.test:8080/v1/embeddings' => Http::response(server_check_embeddings()),
        'tei.test:8080/info' => Http::response(['model_id' => 'intfloat/multilingual-e5-small', 'max_client_batch_size' => 16]),
        'rerank.test:8081/rerank' => Http::response([['index' => 0, 'score' => 0.9], ['index' => 1, 'score' => 0.1]]),
        'rerank.test:8081/info' => Http::response(['model_id' => 'BAAI/bge-reranker-base', 'max_client_batch_size' => 32]),
    ]);

    $rows = new ServerCheck()->run();

    expect($rows[0]['ok'])->toBeFalse()->and($rows[0]['detail'])->toContain('batch')->toContain('16')->toContain('32')
        ->and($rows[1]['ok'])->toBeFalse()->and($rows[1]['detail'])->toContain('batch')->toContain('32')->toContain('64');
});

it('checks each profile once through the server it names, and skips servers without a url', function (): void {
    config()->set('ai.features.embeddings.models', [
        'openai_compatible:intfloat/multilingual-e5-small' => ['dimensions' => 384, 'normalize' => true],
        'openai_compatible:all-MiniLM-L6-v2' => ['dimensions' => 384, 'normalize' => true, 'server' => 'embeddings_minilm'],
    ]);
    config()->set('ai.servers.embeddings_minilm', ['url' => 'http://minilm.test:8082', 'api_key' => null]);
    config()->set('ai.servers.rerank.url', '');
    config()->set('ai.servers.transcription.url', '');
    server_check_fake([
        'tei.test:8080/v1/embeddings' => Http::response(server_check_embeddings()),
        'tei.test:8080/info' => Http::response('not found', 404),
        'minilm.test:8082/v1/embeddings' => Http::response(server_check_embeddings('all-MiniLM-L6-v2')),
        'minilm.test:8082/info' => Http::response('not found', 404),
    ]);

    $rows = new ServerCheck()->run();

    expect(array_column($rows, 'server'))->toBe(['embeddings', 'embeddings_minilm'])
        ->and(array_column($rows, 'ok'))->toBe([true, true]);
});

it('reports nothing when no server is configured', function (): void {
    config()->set('ai.servers.embeddings.url', '');
    config()->set('ai.servers.rerank.url', '');
    config()->set('ai.servers.transcription.url', '');
    Http::preventStrayRequests();

    expect(new ServerCheck()->run())->toBe([]);
});

it('reads the batch limit of a keyed tei reranker with the key and a request id', function (): void {
    config()->set('ai.servers.rerank.api_key', 'rerank-key');
    server_check_fake([
        'tei.test:8080/v1/embeddings' => Http::response(server_check_embeddings()),
        'tei.test:8080/info' => Http::response('not found', 404),
        'rerank.test:8081/rerank' => Http::response([['index' => 0, 'score' => 0.9], ['index' => 1, 'score' => 0.1]]),
        'rerank.test:8081/info' => static fn (Request $request) => $request->hasHeader('Authorization', 'Bearer rerank-key') && preg_match('/^[0-9a-f]{32}$/', $request->header('X-Request-Id')[0] ?? '') === 1
            ? Http::response(['model_id' => 'BAAI/bge-reranker-base', 'max_client_batch_size' => 32])
            : Http::response('unauthorized', 401),
    ]);

    $row = new ServerCheck()->run()[1];

    expect($row['ok'])->toBeFalse()->and($row['detail'])->toContain('batch')->toContain('32')->toContain('64');
});

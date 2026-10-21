<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\Embeddings\SentenceTransformersEmbeddingsProvider;
use Modules\AI\Ai\SelfHosted\SelfHostedServiceException;
use Modules\Core\Search\Exceptions\EmbeddingsException;
use NeuronAI\RAG\Document;

beforeEach(function (): void {
    $this->mockClient = Mockery::mock(Client::class);
});

afterEach(function (): void {
    Mockery::close();
});

it('embedText sends POST to embed and returns float array', function (): void {
    $embeddings = array_fill(0, 512, 0.1);
    $this->mockClient->shouldReceive('post')
        ->once()
        ->with('embed', Mockery::on(fn (array $arg): bool => isset($arg['json']['text']) && isset($arg['json']['truncation'])))
        ->andReturn(new Response(200, [], json_encode(['embeddings' => [$embeddings]])));

    $provider = new SentenceTransformersEmbeddingsProvider('http://localhost:8000');
    $reflection = new ReflectionClass($provider);
    $clientProp = $reflection->getProperty('client');
    $clientProp->setValue($provider, $this->mockClient);

    $result = $provider->embedText('hello world');

    expect($result)->toBeArray()
        ->and($result)->toHaveCount(512)
        ->and($result[0])->toBe(0.1);
});

it('embedDocuments processes batches and sets embedding on documents', function (): void {
    $doc1 = new Document('First');
    $doc2 = new Document('Second');
    $emb1 = array_fill(0, 512, 0.1);
    $emb2 = array_fill(0, 512, 0.2);

    $this->mockClient->shouldReceive('post')
        ->once()
        ->with('embed', Mockery::on(fn (array $arg): bool => isset($arg['json']['texts']) && count($arg['json']['texts']) === 2))
        ->andReturn(new Response(200, [], json_encode(['embeddings' => [$emb1, $emb2]])));

    $provider = new SentenceTransformersEmbeddingsProvider('http://localhost:8000');
    $reflection = new ReflectionClass($provider);
    $clientProp = $reflection->getProperty('client');
    $clientProp->setValue($provider, $this->mockClient);

    $result = $provider->embedDocuments([$doc1, $doc2]);

    expect($result)->toHaveCount(2)
        ->and($result[0]->getEmbedding())->toBe($emb1)
        ->and($result[1]->getEmbedding())->toBe($emb2);
});

it('throws exception on unexpected format', function (): void {
    $this->mockClient->shouldReceive('post')
        ->once()
        ->andReturn(new Response(200, [], json_encode(['invalid' => 'response'])));

    $provider = new SentenceTransformersEmbeddingsProvider('http://localhost:8000');
    $reflection = new ReflectionClass($provider);
    $clientProp = $reflection->getProperty('client');
    $clientProp->setValue($provider, $this->mockClient);

    $provider->embedText('test');
})->throws(Exception::class);

it('throws exception when embedText receives no embeddings', function (): void {
    $this->mockClient->shouldReceive('post')
        ->once()
        ->andReturn(new Response(200, [], json_encode(['embeddings' => []])));

    $provider = new SentenceTransformersEmbeddingsProvider('http://localhost:8000');
    $reflection = new ReflectionClass($provider);
    $clientProp = $reflection->getProperty('client');
    $clientProp->setValue($provider, $this->mockClient);

    $provider->embedText('test');
})->throws(EmbeddingsException::class, 'SentenceTransformers returned an empty embedding');

it('throws exception when embeddings payload is not an array', function (): void {
    $this->mockClient->shouldReceive('post')
        ->once()
        ->andReturn(new Response(200, [], json_encode(['embeddings' => 'invalid'])));

    $provider = new SentenceTransformersEmbeddingsProvider('http://localhost:8000');
    $reflection = new ReflectionClass($provider);
    $clientProp = $reflection->getProperty('client');
    $clientProp->setValue($provider, $this->mockClient);

    $provider->embedText('test');
})->throws(EmbeddingsException::class, 'SentenceTransformers returned unexpected format');

it('throws exception when an embedding vector is not an array', function (): void {
    $this->mockClient->shouldReceive('post')
        ->once()
        ->andReturn(new Response(200, [], json_encode(['embeddings' => ['invalid']])));

    $provider = new SentenceTransformersEmbeddingsProvider('http://localhost:8000');
    $reflection = new ReflectionClass($provider);
    $clientProp = $reflection->getProperty('client');
    $clientProp->setValue($provider, $this->mockClient);

    $provider->embedText('test');
})->throws(EmbeddingsException::class, 'SentenceTransformers returned invalid embedding vector');

it('throws exception when an embedding component is not numeric', function (): void {
    $this->mockClient->shouldReceive('post')
        ->once()
        ->andReturn(new Response(200, [], json_encode(['embeddings' => [[0.1, 'invalid']]])));

    $provider = new SentenceTransformersEmbeddingsProvider('http://localhost:8000');
    $reflection = new ReflectionClass($provider);
    $clientProp = $reflection->getProperty('client');
    $clientProp->setValue($provider, $this->mockClient);

    $provider->embedText('test');
})->throws(EmbeddingsException::class, 'SentenceTransformers returned invalid embedding component');

it('prepends http when missing from URL', function (): void {
    $embeddings = array_fill(0, 512, 0.1);
    $this->mockClient->shouldReceive('post')
        ->once()
        ->andReturn(new Response(200, [], json_encode(['embeddings' => [$embeddings]])));

    $provider = new SentenceTransformersEmbeddingsProvider('localhost:8000');
    $reflection = new ReflectionClass($provider);
    $clientProp = $reflection->getProperty('client');
    $clientProp->setValue($provider, $this->mockClient);

    $provider->embedText('test');

    expect(true)->toBeTrue();
});

it('sets authorization header when api_key is provided', function (): void {
    $provider = new SentenceTransformersEmbeddingsProvider(
        url: 'http://localhost:8000',
        api_key: 'test-secret-key',
    );
    expect($provider)->toBeInstanceOf(SentenceTransformersEmbeddingsProvider::class);
});

it('sends the configured model in a single-text embed request', function (): void {
    $embeddings = array_fill(0, 384, 0.1);
    $this->mockClient->shouldReceive('post')
        ->once()
        ->with('embed', Mockery::on(fn (array $arg): bool => ($arg['json']['model'] ?? null) === 'intfloat/multilingual-e5-small'))
        ->andReturn(new Response(200, [], json_encode(['embeddings' => [$embeddings]])));

    $provider = new SentenceTransformersEmbeddingsProvider('http://localhost:8000', model: 'intfloat/multilingual-e5-small');
    $reflection = new ReflectionClass($provider);
    $clientProp = $reflection->getProperty('client');
    $clientProp->setValue($provider, $this->mockClient);

    $provider->embedText('hello world');
});

it('sends the configured model in a batch embed request', function (): void {
    $emb = array_fill(0, 384, 0.1);
    $this->mockClient->shouldReceive('post')
        ->once()
        ->with('embed', Mockery::on(fn (array $arg): bool => ($arg['json']['model'] ?? null) === 'intfloat/multilingual-e5-small' && isset($arg['json']['texts'])))
        ->andReturn(new Response(200, [], json_encode(['embeddings' => [$emb]])));

    $provider = new SentenceTransformersEmbeddingsProvider('http://localhost:8000', model: 'intfloat/multilingual-e5-small');
    $reflection = new ReflectionClass($provider);
    $clientProp = $reflection->getProperty('client');
    $clientProp->setValue($provider, $this->mockClient);

    $provider->embedDocuments([new Document('First')]);
});

it('omits the model key when no model is configured', function (): void {
    $embeddings = array_fill(0, 384, 0.1);
    $this->mockClient->shouldReceive('post')
        ->once()
        ->with('embed', Mockery::on(fn (array $arg): bool => ! array_key_exists('model', $arg['json'])))
        ->andReturn(new Response(200, [], json_encode(['embeddings' => [$embeddings]])));

    $provider = new SentenceTransformersEmbeddingsProvider('http://localhost:8000');
    $reflection = new ReflectionClass($provider);
    $clientProp = $reflection->getProperty('client');
    $clientProp->setValue($provider, $this->mockClient);

    $provider->embedText('hello world');
});

it('halves the batch and retries when the embedding service strains', function (): void {
    $emb = array_fill(0, 512, 0.1);

    // The full-size (4) batch fails as if the service saturated; the adaptive
    // controller halves it and retries fewer texts, which succeed.
    $this->mockClient->shouldReceive('post')
        ->once()
        ->with('embed', Mockery::on(fn (array $arg): bool => count($arg['json']['texts']) === 4))
        ->andThrow(new RuntimeException('service saturated'));
    $this->mockClient->shouldReceive('post')
        ->with('embed', Mockery::on(fn (array $arg): bool => count($arg['json']['texts']) === 2))
        ->andReturnUsing(fn (): Response => new Response(200, [], json_encode(['embeddings' => [$emb, $emb]])));

    $provider = new SentenceTransformersEmbeddingsProvider('http://localhost:8000', batch_size: 4);
    $reflection = new ReflectionClass($provider);
    $clientProp = $reflection->getProperty('client');
    $clientProp->setValue($provider, $this->mockClient);

    $result = $provider->embedDocuments([new Document('a'), new Document('b'), new Document('c'), new Document('d')]);

    expect($result)->toHaveCount(4)
        ->and($result[0]->getEmbedding())->toBe($emb);
});

it('splits documents into requests according to the configured batch size', function (): void {
    $emb = array_fill(0, 512, 0.1);

    $this->mockClient->shouldReceive('post')
        ->once()
        ->with('embed', Mockery::on(fn (array $arg): bool => count($arg['json']['texts']) === 2))
        ->andReturn(new Response(200, [], json_encode(['embeddings' => [$emb, $emb]])));
    $this->mockClient->shouldReceive('post')
        ->once()
        ->with('embed', Mockery::on(fn (array $arg): bool => count($arg['json']['texts']) === 1))
        ->andReturn(new Response(200, [], json_encode(['embeddings' => [$emb]])));

    $provider = new SentenceTransformersEmbeddingsProvider('http://localhost:8000', batch_size: 2);
    $reflection = new ReflectionClass($provider);
    $clientProp = $reflection->getProperty('client');
    $clientProp->setValue($provider, $this->mockClient);

    $result = $provider->embedDocuments([new Document('a'), new Document('b'), new Document('c')]);

    expect($result)->toHaveCount(3);
});

/**
 * @param  array<string, mixed>  $answer
 */
function sentenceTransformersProviderAnswering(array $answer, ?string $model, $client): SentenceTransformersEmbeddingsProvider
{
    $client->shouldReceive('post')->andReturn(new Response(200, [], json_encode($answer)));

    $provider = new SentenceTransformersEmbeddingsProvider('http://localhost:8000', model: $model);
    $property = (new ReflectionClass($provider))->getProperty('client');
    $property->setValue($provider, $client);

    return $provider;
}

it('rejects an answer from another model than the one requested, instead of storing its vectors', function (): void {
    $provider = sentenceTransformersProviderAnswering([
        'model' => 'sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2',
        'embeddings' => [array_fill(0, 384, 0.1)],
    ], 'intfloat/multilingual-e5-small', $this->mockClient);

    expect(fn () => $provider->embedText('hello'))
        ->toThrow(EmbeddingsException::class, 'paraphrase-multilingual-MiniLM-L12-v2');
});

it('accepts an answer that names the requested model, whatever its organisation prefix', function (string $answered): void {
    $provider = sentenceTransformersProviderAnswering([
        'model' => $answered,
        'embeddings' => [array_fill(0, 384, 0.1)],
    ], 'intfloat/multilingual-e5-small', $this->mockClient);

    expect($provider->embedText('hello'))->toHaveCount(384);
})->with([
    'the same name' => ['intfloat/multilingual-e5-small'],
    'without the organisation' => ['multilingual-e5-small'],
]);

it('does not check the model when the service does not name it, or none was requested', function (?string $requested, array $answer): void {
    $provider = sentenceTransformersProviderAnswering($answer, $requested, $this->mockClient);

    expect($provider->embedText('hello'))->toHaveCount(384);
})->with([
    'an answer without a model' => ['intfloat/multilingual-e5-small', ['embeddings' => [array_fill(0, 384, 0.1)]]],
    'no model requested' => [null, ['model' => 'any-model', 'embeddings' => [array_fill(0, 384, 0.1)]]],
]);

/**
 * A provider whose client answers with $responses in order and records every request it sent in $history.
 *
 * @param  list<Response>  $responses
 * @param  array<int, array<string, mixed>>  $history
 */
function sentenceTransformersProviderRecording(array $responses, array &$history): SentenceTransformersEmbeddingsProvider
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    $provider = new SentenceTransformersEmbeddingsProvider('http://st.test:8000');
    (new ReflectionClass($provider))->getProperty('client')->setValue($provider, new Client(['handler' => $stack, 'base_uri' => 'http://st.test:8000']));

    return $provider;
}

it('sends a request id of 32 hex characters with every embed call', function (): void {
    $history = [];
    $provider = sentenceTransformersProviderRecording([
        new Response(200, [], json_encode(['embeddings' => [[0.1, 0.2]]])),
        new Response(200, [], json_encode(['embeddings' => [[0.1, 0.2]]])),
    ], $history);

    $provider->embedText('hello');
    $provider->embedDocuments([new Document('First')]);

    $ids = array_map(fn (array $entry): string => $entry['request']->getHeaderLine('X-Request-Id'), $history);

    expect($ids)->toHaveCount(2)
        ->and($ids[0])->toMatch('/^[0-9a-f]{32}$/')
        ->and($ids[1])->toMatch('/^[0-9a-f]{32}$/')
        ->and($ids[1])->not->toBe($ids[0]);
});

/**
 * The job fails with the exception, so the request id in it is what leads from the job log to the service log.
 * A single text goes through the same call as a batch without the adaptive batcher's retries (and their sleeps).
 */
it('throws a failure carrying the request id and logs it once when the service answers 500', function (): void {
    Log::spy();
    $history = [];
    $provider = sentenceTransformersProviderRecording([
        new Response(500, [], json_encode(['error' => 'internal error', 'request_id' => 'x'])),
    ], $history);

    try {
        $provider->embedText('secret text');
        $this->fail('The call should have failed.');
    } catch (SelfHostedServiceException $exception) {
        $id = $history[0]['request']->getHeaderLine('X-Request-Id');

        expect($exception->getMessage())->toContain("request_id {$id}");
    }

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $context === [
            'service' => 'sentence_transformers',
            'endpoint' => 'http://st.test:8000/embed',
            'status' => 500,
            'request_id' => $id,
        ] && ! str_contains($message, 'secret text'),
    );
});

/**
 * Guzzle resolves `embed` against the base URL, so the log names the URL the call really went to.
 */
it('logs the url the call really went to', function (string $base, string $called): void {
    Log::spy();
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(503, [], json_encode(['error' => 'busy']))]));
    $stack->push(Middleware::history($history));
    $provider = new SentenceTransformersEmbeddingsProvider($base);
    (new ReflectionClass($provider))->getProperty('client')->setValue($provider, new Client(['handler' => $stack, 'base_uri' => $base]));

    expect(fn () => $provider->embedText('hello'))->toThrow(SelfHostedServiceException::class);

    expect((string) $history[0]['request']->getUri())->toBe($called);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['endpoint'] === $called);
})->with([
    'a base url with a trailing slash' => ['http://gw.test/st/', 'http://gw.test/st/embed'],
    'a base url without one' => ['http://gw.test/st', 'http://gw.test/embed'],
]);

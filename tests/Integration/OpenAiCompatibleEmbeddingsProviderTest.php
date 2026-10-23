<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\Embeddings\OpenAiCompatibleEmbeddingsProvider;
use Modules\AI\Ai\SelfHosted\AiServer;
use Modules\AI\Ai\SelfHosted\SelfHostedServiceException;
use Modules\AI\Tests\Stubs\ScriptedHttpClient;
use Modules\Core\Search\Exceptions\EmbeddingsException;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\RAG\Document;

/**
 * A server answering like TEI: one vector per input, the first component being the input's position in its request.
 *
 * @param  list<string>  $inputs
 * @return array<string, mixed>
 */
function embeddingsServerAnswer(array $inputs, string $model = 'intfloat/multilingual-e5-small', bool $reversed = false): array
{
    $data = array_map(
        static fn (string $text, int $index): array => ['object' => 'embedding', 'index' => $index, 'embedding' => [(float) $index, (float) mb_strlen($text)]],
        $inputs,
        array_keys($inputs),
    );

    return ['object' => 'list', 'model' => $model, 'data' => $reversed ? array_reverse($data) : $data, 'usage' => ['prompt_tokens' => 1, 'total_tokens' => 1]];
}

function embeddingsServer(string $url = 'http://tei.test:8080', ?string $key = 'secret-key', int $batch = 32): AiServer
{
    return new AiServer('embeddings', $url, $key, 5, $batch, null, null);
}

it('posts to /v1/embeddings of the server root with model, input and the bearer key', function (): void {
    $client = new ScriptedHttpClient(fn (HttpRequest $request): HttpResponse => new HttpResponse(200, json_encode(embeddingsServerAnswer($request->body['input']))));
    $provider = new OpenAiCompatibleEmbeddingsProvider(embeddingsServer(), 'intfloat/multilingual-e5-small', null, $client);

    $vector = $provider->embedText('hello');

    $request = $client->requests[0];

    expect($vector)->toBe([0.0, 5.0])
        ->and($request->uri)->toBe('http://tei.test:8080/v1/embeddings')
        ->and($request->body['model'])->toBe('intfloat/multilingual-e5-small')
        ->and($request->body['input'])->toBe(['hello'])
        ->and($request->body['encoding_format'])->toBe('float')
        ->and($request->headers['Authorization'])->toBe('Bearer secret-key');
});

it('sends no Authorization header when the server has no key', function (): void {
    $client = new ScriptedHttpClient(fn (HttpRequest $request): HttpResponse => new HttpResponse(200, json_encode(embeddingsServerAnswer($request->body['input']))));
    $provider = new OpenAiCompatibleEmbeddingsProvider(embeddingsServer(key: null), 'intfloat/multilingual-e5-small', null, $client);

    $provider->embedText('hello');

    expect($client->requests[0]->headers)->not->toHaveKey('Authorization');
});

it('sends dimensions only when given', function (?int $dimensions, bool $sent): void {
    $client = new ScriptedHttpClient(fn (HttpRequest $request): HttpResponse => new HttpResponse(200, json_encode(embeddingsServerAnswer($request->body['input']))));

    new OpenAiCompatibleEmbeddingsProvider(embeddingsServer(), 'intfloat/multilingual-e5-small', $dimensions, $client)->embedText('x');

    expect(array_key_exists('dimensions', $client->requests[0]->body))->toBe($sent);
})->with([[null, false], [384, true]]);

it('puts every answer vector on the document of its index, whatever the answer order', function (): void {
    $client = new ScriptedHttpClient(fn (HttpRequest $request): HttpResponse => new HttpResponse(200, json_encode(embeddingsServerAnswer($request->body['input'], reversed: true))));
    $provider = new OpenAiCompatibleEmbeddingsProvider(embeddingsServer(), 'intfloat/multilingual-e5-small', null, $client);

    $documents = $provider->embedDocuments([new Document('a'), new Document('bb'), new Document('ccc')]);

    expect(array_map(static fn (Document $document): array => $document->getEmbedding(), $documents))->toBe([[0.0, 1.0], [1.0, 2.0], [2.0, 3.0]]);
});

it('splits 33 documents into requests of at most the server batch size', function (): void {
    $client = new ScriptedHttpClient(fn (HttpRequest $request): HttpResponse => count($request->body['input']) > 32
        ? new HttpResponse(413, json_encode(['message' => 'Batch size error', 'type' => 'validation']))
        : new HttpResponse(200, json_encode(embeddingsServerAnswer($request->body['input']))));
    $provider = new OpenAiCompatibleEmbeddingsProvider(embeddingsServer(batch: 32), 'intfloat/multilingual-e5-small', null, $client);

    $documents = $provider->embedDocuments(array_map(static fn (int $i): Document => new Document('text ' . $i), range(1, 33)));

    expect($documents)->toHaveCount(33)
        ->and(array_map(static fn (HttpRequest $request): int => count($request->body['input']), $client->requests))->toBe([32, 1])
        ->and($documents[32]->getEmbedding())->not->toBe([]);
});

it('halves the batch and retries when a request fails', function (): void {
    $client = new ScriptedHttpClient(fn (HttpRequest $request): HttpResponse => count($request->body['input']) > 32
        ? new HttpResponse(413, json_encode(['message' => 'Batch size error', 'type' => 'validation']))
        : new HttpResponse(200, json_encode(embeddingsServerAnswer($request->body['input']))));
    $provider = new OpenAiCompatibleEmbeddingsProvider(embeddingsServer(batch: 64), 'intfloat/multilingual-e5-small', null, $client);

    $documents = $provider->embedDocuments(array_map(static fn (int $i): Document => new Document('text ' . $i), range(1, 40)));

    expect($documents)->toHaveCount(40)
        ->and(array_map(static fn (HttpRequest $request): int => count($request->body['input']), $client->requests))->toBe([40, 32, 8]);
});

it('sends one X-Request-Id per request, 32 hex characters, and logs the id on failure', function (): void {
    Log::spy();
    $client = new ScriptedHttpClient(fn (HttpRequest $request): HttpResponse => new HttpResponse(503, 'down'));
    $provider = new OpenAiCompatibleEmbeddingsProvider(embeddingsServer(), 'm', null, $client);

    try {
        $provider->embedText('hello');
        $this->fail('The 503 should surface as an exception.');
    } catch (SelfHostedServiceException $exception) {
        $id = $client->requests[0]->headers['X-Request-Id'];

        expect($id)->toMatch('/^[0-9a-f]{32}$/')
            ->and($exception->context()['request_id'])->toBe($id)
            ->and($exception->context()['status'])->toBe(503)
            ->and($exception->context()['service'])->toBe('embeddings')
            ->and($exception->getMessage())->toStartWith('[request_id ' . $id . ']');

        Log::shouldHaveReceived('warning')->once()->with(Mockery::type('string'), Mockery::on(fn (array $context): bool => $context['request_id'] === $id && $context['status'] === 503));
    }
});

it('uses a new request id for every request', function (): void {
    $client = new ScriptedHttpClient(fn (HttpRequest $request): HttpResponse => new HttpResponse(200, json_encode(embeddingsServerAnswer($request->body['input']))));
    $provider = new OpenAiCompatibleEmbeddingsProvider(embeddingsServer(batch: 2), 'intfloat/multilingual-e5-small', null, $client);

    $provider->embedDocuments([new Document('a'), new Document('b'), new Document('c')]);

    $ids = array_map(static fn (HttpRequest $request): string => $request->headers['X-Request-Id'], $client->requests);

    expect($ids)->toHaveCount(2)->and(array_unique($ids))->toHaveCount(2);
});

it('refuses an answer that names another model than the profile', function (): void {
    $client = new ScriptedHttpClient(fn (HttpRequest $request): HttpResponse => new HttpResponse(200, json_encode(embeddingsServerAnswer($request->body['input'], 'sentence-transformers/all-MiniLM-L6-v2'))));
    $provider = new OpenAiCompatibleEmbeddingsProvider(embeddingsServer(), 'intfloat/multilingual-e5-small', null, $client);

    $provider->embedText('hello');
})->throws(EmbeddingsException::class, 'served model');

it('accepts the profile model with or without its organisation', function (): void {
    $client = new ScriptedHttpClient(fn (HttpRequest $request): HttpResponse => new HttpResponse(200, json_encode(embeddingsServerAnswer($request->body['input'], 'multilingual-e5-small'))));
    $provider = new OpenAiCompatibleEmbeddingsProvider(embeddingsServer(), 'intfloat/multilingual-e5-small', null, $client);

    expect($provider->embedText('hello'))->toBe([0.0, 5.0]);
});

it('refuses an answer that is not one well-formed vector per input', function (array $answer): void {
    $client = new ScriptedHttpClient(fn (HttpRequest $request): HttpResponse => new HttpResponse(200, json_encode($answer)));
    $provider = new OpenAiCompatibleEmbeddingsProvider(embeddingsServer(), 'm', null, $client);

    $provider->embedText('a');
})->with([
    'two vectors for one input' => [['data' => [['index' => 0, 'embedding' => [0.1]], ['index' => 1, 'embedding' => [0.2]]]]],
    'no data' => [['object' => 'list']],
    'a vector that is not numbers' => [['data' => [['index' => 0, 'embedding' => ['x']]]]],
    'an index out of range' => [['data' => [['index' => 3, 'embedding' => [0.1]]]]],
])->throws(EmbeddingsException::class);

it('never puts a url password, a key, a text or an answer body in the exception or the log', function (): void {
    Log::spy();
    $client = new ScriptedHttpClient(fn (HttpRequest $request): HttpResponse => new HttpResponse(500, 'echo: TEXT-OF-THE-DOCUMENT'));
    $provider = new OpenAiCompatibleEmbeddingsProvider(embeddingsServer('http://user:url-password@tei.test:8080', 'secret-key'), 'm', null, $client);

    try {
        $provider->embedText('TEXT-OF-THE-DOCUMENT');
        $this->fail('The 500 should surface as an exception.');
    } catch (SelfHostedServiceException $exception) {
        expect($exception->getMessage())->not->toContain('url-password')
            ->and($exception->getMessage())->not->toContain('secret-key')
            ->and($exception->getMessage())->not->toContain('TEXT-OF-THE-DOCUMENT')
            ->and(json_encode($exception->context()))->not->toContain('url-password');
    }

    Log::shouldHaveReceived('warning')->with(Mockery::type('string'), Mockery::on(fn (array $context): bool => ! str_contains(json_encode($context), 'url-password') && ! str_contains(json_encode($context), 'secret-key')));
});

it('turns a 2xx answer that is not JSON into an EmbeddingsException carrying the request id', function (): void {
    $client = new ScriptedHttpClient(fn (HttpRequest $request): HttpResponse => new HttpResponse(200, '<html>maintenance page</html>'));
    $provider = new OpenAiCompatibleEmbeddingsProvider(embeddingsServer(), 'm', null, $client);

    try {
        $provider->embedText('hello');
        $this->fail('A page that is not JSON should not parse.');
    } catch (EmbeddingsException $exception) {
        $id = $client->requests[0]->headers['X-Request-Id'];

        expect($exception->getMessage())->toContain("request_id {$id}")->toContain('not JSON')->not->toContain('maintenance page');
    }
});

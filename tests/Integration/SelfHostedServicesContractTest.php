<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\AI\Ai\Embeddings\EmbeddingServiceIdentity;
use Modules\AI\Ai\Embeddings\SentenceTransformersEmbeddingsProvider;
use Modules\AI\Ai\MediaAnalysis\MediaAnalysisModelProfile;
use Modules\AI\Ai\MediaAnalysis\Transcription\WhisperTranscriber;
use Modules\AI\Services\CrossEncoderService;
use Modules\AI\Tests\Stubs\SelfHostedServiceContract;
use Modules\Core\Search\Exceptions\EmbeddingsException;
use NeuronAI\RAG\Document;

/**
 * The clients against the OpenAPI documents the services publish, copied in `tests/Fixtures/SelfHostedServices`
 * with the service version they come from. A change on either side that breaks the other fails here: a field
 * the client sends that the service does not declare, a required one the client omits, a field the client
 * reads that the answer does not declare. Updating the fixtures is a step of a service release.
 */
beforeEach(function (): void {
    Sleep::fake();
    $this->embeddings = SelfHostedServiceContract::load('sentence-transformers-api');
    $this->whisper = SelfHostedServiceContract::load('whisper-api');
});

/**
 * A sentence-transformers provider whose client answers `$answer` to every call and records the requests it sent.
 *
 * @param  array<string, mixed>  $answer
 * @param  array<int, array<string, mixed>>  $history
 */
function contract_embeddings_provider(array $answer, array &$history, int $calls = 1): SentenceTransformersEmbeddingsProvider
{
    $stack = HandlerStack::create(new MockHandler(array_map(
        static fn (): Response => new Response(200, [], (string) json_encode($answer)),
        range(1, $calls),
    )));
    $stack->push(Middleware::history($history));

    $provider = new SentenceTransformersEmbeddingsProvider('http://st.test:8000', model: 'intfloat/multilingual-e5-small');
    (new ReflectionClass($provider))->getProperty('client')->setValue($provider, new Client(['handler' => $stack, 'base_uri' => 'http://st.test:8000']));

    return $provider;
}

/**
 * @param  array<int, array<string, mixed>>  $history
 * @return list<array<string, mixed>>
 */
function contract_sent_bodies(array $history): array
{
    return array_map(
        static fn (array $entry): array => json_decode((string) $entry['request']->getBody(), true, 512, JSON_THROW_ON_ERROR),
        $history,
    );
}

function contract_profile(int $dimensions): EmbeddingModelProfile
{
    return new EmbeddingModelProfile('e5-small', 'sentence_transformers', 'intfloat/multilingual-e5-small', $dimensions, 'query: ', 'passage: ', 'cosine', true);
}

function contract_audio_file(): string
{
    $path = tempnam(sys_get_temp_dir(), 'wh') . '.mp3';
    file_put_contents($path, 'fake audio bytes');

    return $path;
}

it('copies each service document with the version it comes from', function (string $service): void {
    $contract = SelfHostedServiceContract::load($service);

    expect($contract->version())->toBe($contract->documentVersion())
        ->and($contract->apiVersion())->toBe(1);
})->with(['sentence-transformers-api', 'whisper-api']);

it('validates a body against a schema: an undeclared field, a missing required one, a wrong type', function (array $body, string $error): void {
    expect($this->embeddings->violations($this->embeddings->requestSchema('/score', 'post'), $body))->toContain($error);
})->with([
    'an undeclared field' => [['pairs' => [], 'top_k' => 3], '$.top_k is not declared'],
    'a missing required field' => [['model' => 'm'], '$.pairs is required'],
    'a wrong type' => [['pairs' => [['query' => 'q', 'text' => 5]]], '$.pairs[0].text must be string'],
]);

it('sends /embed bodies the service declares, for one text and for a batch', function (): void {
    $history = [];
    $provider = contract_embeddings_provider($this->embeddings->responseExample('/embed', 'post', 200), $history, 2);

    $provider->embedText('hello');
    $provider->embedDocuments([new Document('First')]);

    $schema = $this->embeddings->requestSchema('/embed', 'post');

    foreach (contract_sent_bodies($history) as $body) {
        expect($this->embeddings->violations($schema, $body))->toBe([])
            ->and(isset($body['text']) || isset($body['texts']))->toBeTrue();
    }
});

it('sends an /embed probe body the service declares', function (): void {
    config(['ai.providers.sentence_transformers.url' => 'http://st.test:8000']);
    Http::fake(['*/embed' => Http::response($this->embeddings->responseExample('/embed', 'post', 200))]);

    (new EmbeddingServiceIdentity())->probeModel(contract_profile(3));

    Http::assertSent(fn ($request): bool => $this->embeddings->violations($this->embeddings->requestSchema('/embed', 'post'), $request->data()) === []);
});

it('sends a /score body the service declares', function (): void {
    Http::fake(['*/score' => Http::response($this->embeddings->responseExample('/score', 'post', 200))]);

    (new CrossEncoderService('http://st.test:8000'))->score([['query' => 'orari', 'text' => 'aperto dalle 9']]);

    Http::assertSent(fn ($request): bool => $this->embeddings->violations($this->embeddings->requestSchema('/score', 'post'), $request->data()) === []);
});

it('sends the media in the multipart field the service declares', function (): void {
    config(['ai.providers.whisper.url' => 'http://whisper.test']);
    Http::fake(['whisper.test/*' => Http::response($this->whisper->responseExample('/transcribe', 'post', 200))]);
    $path = contract_audio_file();

    (new WhisperTranscriber())->transcribe($path, 'audio/mpeg', new MediaAnalysisModelProfile('transcription', 'whisper', 'whisper', ''));

    $schema = $this->whisper->requestSchema('/transcribe', 'post', 'multipart/form-data');
    Http::assertSent(function ($request) use ($schema): bool {
        $fields = array_column($request->data(), 'name');

        return $fields === ['file'] && $this->whisper->violations($schema, array_fill_keys($fields, 'binary')) === [];
    });

    @unlink($path);
});

it('parses the example answers of the services', function (): void {
    $history = [];
    config(['ai.providers.sentence_transformers.url' => 'http://st.test:8000', 'ai.providers.whisper.url' => 'http://whisper.test']);
    Http::fake([
        'st.test:8000/health' => Http::response($this->embeddings->responseExample('/health', 'get', 200)),
        'st.test:8000/embed' => Http::response($this->embeddings->responseExample('/embed', 'post', 200)),
        'st.test:8000/score' => Http::response($this->embeddings->responseExample('/score', 'post', 200)),
        'whisper.test/*' => Http::response($this->whisper->responseExample('/transcribe', 'post', 200)),
    ]);
    $path = contract_audio_file();

    $identity = new EmbeddingServiceIdentity();
    $reranked = (new CrossEncoderService('http://st.test:8000'))->scoreWithModel([['query' => 'orari', 'text' => 'aperto dalle 9']]);

    expect(contract_embeddings_provider($this->embeddings->responseExample('/embed', 'post', 200), $history)->embedText('ciao'))->toBe([0.012, -0.034, 0.051])
        ->and($identity->probeModel(contract_profile(3)))->toBe('intfloat/multilingual-e5-small')
        ->and($identity->healthModel())->toBe('intfloat/multilingual-e5-small')
        ->and($reranked->scores)->toBe([0.93])
        ->and($reranked->model)->toBe('cross-encoder/mmarco-mMiniLMv2-L12-H384-v1')
        ->and((new WhisperTranscriber())->transcribe($path, 'audio/mpeg', new MediaAnalysisModelProfile('transcription', 'whisper', 'whisper', '')))->toBe('Buongiorno a tutti.');

    @unlink($path);
});

/**
 * The examples are what the clients are fed above, so they must be answers the services may really give.
 */
it('has example answers that match their own schemas', function (string $service, string $path, string $method): void {
    $contract = SelfHostedServiceContract::load($service);

    expect($contract->violations($contract->responseSchema($path, $method, 200), $contract->responseExample($path, $method, 200)))->toBe([]);
})->with([
    '/embed' => ['sentence-transformers-api', '/embed', 'post'],
    '/score' => ['sentence-transformers-api', '/score', 'post'],
    '/health of the embeddings' => ['sentence-transformers-api', '/health', 'get'],
    '/transcribe' => ['whisper-api', '/transcribe', 'post'],
]);

/**
 * An answer holding only what its schema requires: a client that needs anything else depends on a field
 * the service never promised, and fails here.
 */
it('parses answers that carry only the required fields', function (): void {
    $history = [];
    config(['ai.providers.sentence_transformers.url' => 'http://st.test:8000', 'ai.providers.whisper.url' => 'http://whisper.test']);
    $embed = $this->embeddings->requiredOnly($this->embeddings->responseSchema('/embed', 'post', 200), $this->embeddings->responseExample('/embed', 'post', 200));
    Http::fake([
        'st.test:8000/health' => Http::response($this->embeddings->requiredOnly($this->embeddings->responseSchema('/health', 'get', 200), $this->embeddings->responseExample('/health', 'get', 200))),
        'st.test:8000/embed' => Http::response($embed),
        'st.test:8000/score' => Http::response($this->embeddings->requiredOnly($this->embeddings->responseSchema('/score', 'post', 200), $this->embeddings->responseExample('/score', 'post', 200))),
        'whisper.test/*' => Http::response($this->whisper->requiredOnly($this->whisper->responseSchema('/transcribe', 'post', 200), $this->whisper->responseExample('/transcribe', 'post', 200))),
    ]);
    $path = contract_audio_file();

    $identity = new EmbeddingServiceIdentity();

    expect(contract_embeddings_provider($embed, $history)->embedText('ciao'))->toHaveCount(3)
        ->and($identity->probeModel(contract_profile(3)))->toBe('intfloat/multilingual-e5-small')
        ->and($identity->healthModel())->toBe('intfloat/multilingual-e5-small')
        ->and((new CrossEncoderService('http://st.test:8000'))->score([['query' => 'orari', 'text' => 'aperto dalle 9']]))->toBe([0.93])
        ->and((new WhisperTranscriber())->transcribe($path, 'audio/mpeg', new MediaAnalysisModelProfile('transcription', 'whisper', 'whisper', '')))->toBe('Buongiorno a tutti.');

    @unlink($path);
});

it('keeps only the required fields of an answer, at every depth', function (): void {
    $schema = $this->embeddings->responseSchema('/health', 'get', 200);
    $answer = $this->embeddings->responseExample('/health', 'get', 200);
    $answer['extra'] = true;
    $answer['reranker']['allowed_models'] = ['m'];

    $reduced = $this->embeddings->requiredOnly($schema, $answer);

    expect($reduced)->not->toHaveKey('extra')
        ->and($reduced['reranker'])->not->toHaveKey('allowed_models')
        ->and(array_keys($reduced))->toBe($schema['required']);
});

/**
 * Every field a client reads must be declared in the answer's schema: reading one the service never promised
 * works only until the service stops sending it.
 */
it('reads only fields the answer schemas declare', function (string $service, string $path, string $method, string $field): void {
    $contract = SelfHostedServiceContract::load($service);

    expect($contract->declares($contract->responseSchema($path, $method, 200), $field))->toBeTrue();
})->with([
    'embeddings of /embed' => ['sentence-transformers-api', '/embed', 'post', 'embeddings'],
    'model of /embed' => ['sentence-transformers-api', '/embed', 'post', 'model'],
    'scores of /score' => ['sentence-transformers-api', '/score', 'post', 'scores'],
    'model of /score' => ['sentence-transformers-api', '/score', 'post', 'model'],
    'model of /health' => ['sentence-transformers-api', '/health', 'get', 'model'],
    'api_version of /health' => ['sentence-transformers-api', '/health', 'get', 'api_version'],
    'text of /transcribe' => ['whisper-api', '/transcribe', 'post', 'text'],
]);

/**
 * Proof that the declared fields above are the ones the clients depend on: without them the answer is unusable.
 */
it('depends on the embeddings of /embed', function (): void {
    $answer = $this->embeddings->responseExample('/embed', 'post', 200);
    unset($answer['embeddings']);
    $history = [];

    expect(fn () => contract_embeddings_provider($answer, $history)->embedText('ciao'))->toThrow(EmbeddingsException::class);
});

it('depends on the scores of /score', function (): void {
    $answer = $this->embeddings->responseExample('/score', 'post', 200);
    unset($answer['scores']);
    Http::fake(['*/score' => Http::response($answer)]);

    expect(fn () => (new CrossEncoderService('http://st.test:8000'))->score([['query' => 'q', 'text' => 't']]))->toThrow(RuntimeException::class);
});

it('depends on the text of /transcribe', function (): void {
    $answer = $this->whisper->responseExample('/transcribe', 'post', 200);
    unset($answer['text']);
    config(['ai.providers.whisper.url' => 'http://whisper.test']);
    Http::fake(['whisper.test/*' => Http::response($answer)]);
    $path = contract_audio_file();

    expect((new WhisperTranscriber())->transcribe($path, 'audio/mpeg', new MediaAnalysisModelProfile('transcription', 'whisper', 'whisper', '')))->toBeNull();

    @unlink($path);
});

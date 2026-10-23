<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Modules\AI\Ai\Embeddings\OpenAiCompatibleEmbeddingsProvider;
use Modules\AI\Ai\MediaAnalysis\MediaAnalysisModelProfile;
use Modules\AI\Ai\MediaAnalysis\Transcription\OpenAiTranscriber;
use Modules\AI\Ai\SelfHosted\AiServer;
use Modules\AI\Services\CrossEncoderService;
use Modules\AI\Tests\Stubs\OpenApiContract;
use Modules\AI\Tests\Stubs\ScriptedHttpClient;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\RAG\Document;

/**
 * The clients against the OpenAPI documents the servers publish, copied in `tests/Fixtures/StandardServers` with
 * the release they come from: Text Embeddings Inference (tei), Infinity (infinity) and speaches (speaches). A change
 * on either side that breaks the other fails here: a field the client sends that the server does not declare, a
 * required one it omits, a field it reads that the answer does not promise. Updating the fixtures is a step of
 * adopting a new release of a documented server (the version is in the `.version` file next to each).
 *
 * Infinity publishes `/embeddings` and `/rerank` under its `--url-prefix`; the guide starts it with `/v1`, which is
 * where the client sends them.
 */
beforeEach(function (): void {
    Sleep::fake();
});

/**
 * One embeddings answer in the shape of the document, carrying `$count` vectors.
 *
 * @return array<string, mixed>
 */
function contract_embeddings_answer(OpenApiContract $contract, string $path, int $count, bool $requiredOnly = false): array
{
    $schema = $contract->responseSchema($path, 'post');
    $answer = $contract->sample($schema, $requiredOnly);
    $item = $answer['data'][0];

    $answer['data'] = array_map(static fn (int $index): array => [...$item, 'index' => $index], range(0, $count - 1));

    return $answer;
}

/**
 * Embeds with the real provider against a scripted server answering `$answer`; returns the bodies it sent and the vectors it got.
 *
 * @param  array<string, mixed>  $answer
 * @return array{bodies: list<array<string, mixed>>, vectors: list<list<float>>}
 */
function contract_embed(array $answer): array
{
    $client = new ScriptedHttpClient(static fn (HttpRequest $request): HttpResponse => new HttpResponse(200, json_encode($answer)));
    $provider = new OpenAiCompatibleEmbeddingsProvider(
        new AiServer('embeddings', 'http://server.test', null, 5, 32, null, null),
        is_string($answer['model'] ?? null) ? $answer['model'] : 'm',
        null,
        $client,
    );

    $count = count($answer['data']);
    $documents = $provider->embedDocuments(array_map(static fn (int $i): Document => new Document("text {$i}"), range(1, $count)));

    return [
        'bodies' => array_map(static fn (HttpRequest $request): array => $request->body, $client->requests),
        'vectors' => array_map(static fn (Document $document): array => $document->getEmbedding(), $documents),
    ];
}

/**
 * Reranks `$count` pairs against a faked server of the dialect that answers `$answer`; returns the body sent and the scores.
 *
 * @param  array<string, mixed>|list<array<string, mixed>>  $answer
 * @return array{body: array<string, mixed>, scores: list<float>}
 */
function contract_rerank(string $dialect, array $answer, int $count): array
{
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake(['*/rerank' => Http::response($answer), '*/info' => Http::response('not found', 404)]);

    $pairs = array_map(static fn (int $i): array => ['query' => 'what is this', 'text' => "text {$i}"], range(1, $count));
    $scores = new CrossEncoderService(new AiServer('rerank', 'http://server.test', null, 5, 32, $dialect, 'some/model'))->score($pairs);

    return ['body' => Http::recorded()->first()[0]->data(), 'scores' => $scores];
}

it('sends embeddings bodies the servers declare, for one text and for a batch', function (string $server, string $path): void {
    $contract = OpenApiContract::load($server);

    foreach ([1, 3] as $count) {
        $result = contract_embed(contract_embeddings_answer($contract, $path, $count));

        foreach ($result['bodies'] as $body) {
            expect($contract->violations($contract->requestSchema($path, 'post'), $body))->toBe([]);
        }
    }
})->with([
    'tei' => ['tei', '/v1/embeddings'],
    'infinity' => ['infinity', '/embeddings'],
]);

it('parses the example embeddings answers and answers reduced to the required fields', function (string $server, string $path, bool $requiredOnly): void {
    $contract = OpenApiContract::load($server);
    $answer = contract_embeddings_answer($contract, $path, 2, $requiredOnly);

    expect($contract->violations($contract->responseSchema($path, 'post'), $answer))->toBe([]);

    $result = contract_embed($answer);

    expect($result['vectors'])->toHaveCount(2)->and($result['vectors'][0])->not->toBe([]);
})->with([
    'tei, as the document exemplifies it' => ['tei', '/v1/embeddings', false],
    'tei, required fields only' => ['tei', '/v1/embeddings', true],
    'infinity, as the document exemplifies it' => ['infinity', '/embeddings', false],
    'infinity, required fields only' => ['infinity', '/embeddings', true],
]);

it('depends only on fields the embeddings answers promise', function (string $server, string $path, string $item): void {
    $contract = OpenApiContract::load($server);
    $answer = $contract->responseSchema($path, 'post');
    $embedding = $contract->resolveForTest($answer['properties']['data']['items']);

    expect($contract->requires($answer, 'data'))->toBeTrue()
        ->and($contract->requires($embedding, 'embedding'))->toBeTrue()
        ->and($contract->requires($embedding, 'index'))->toBeTrue();
})->with([
    'tei' => ['tei', '/v1/embeddings', 'OpenAICompatEmbedding'],
    'infinity' => ['infinity', '/embeddings', 'EmbeddingObject'],
]);

it('sends rerank bodies the servers declare', function (string $server, string $path, string $dialect, string $answerSchema): void {
    $contract = OpenApiContract::load($server);
    $item = $contract->sample($contract->schema($answerSchema));
    $answer = $dialect === 'tei'
        ? array_map(static fn (int $i): array => [...$item, 'index' => $i], range(0, 2))
        : [...$contract->sample($contract->responseSchema($path, 'post')), 'results' => array_map(static fn (int $i): array => [...$contract->sample($contract->schema('_ReRankObject')), 'index' => $i], range(0, 2))];

    $result = contract_rerank($dialect, $answer, 3);

    expect($contract->violations($contract->requestSchema($path, 'post'), $result['body']))->toBe([])
        ->and($result['scores'])->toHaveCount(3);
})->with([
    'tei' => ['tei', '/rerank', 'tei', 'Rank'],
    'infinity' => ['infinity', '/rerank', 'cohere', '_ReRankObject'],
]);

it('parses the rerank answers in the shape the documents give, and reduced to the required fields', function (string $server, string $path, string $dialect, bool $requiredOnly): void {
    $contract = OpenApiContract::load($server);
    $schema = $contract->responseSchema($path, 'post');

    if ($dialect === 'tei') {
        $item = $contract->sample($contract->schema('Rank'), $requiredOnly);
        $answer = array_map(static fn (int $i): array => [...$item, 'index' => $i], range(0, 1));
    } else {
        $answer = $contract->sample($schema, $requiredOnly);
        $item = $contract->sample($contract->schema('_ReRankObject'), $requiredOnly);
        $answer['results'] = array_map(static fn (int $i): array => [...$item, 'index' => $i], range(0, 1));
    }

    expect($contract->violations($schema, $answer))->toBe([])
        ->and(contract_rerank($dialect, $answer, 2)['scores'])->toHaveCount(2);
})->with([
    'tei' => ['tei', '/rerank', 'tei', false],
    'tei, required fields only' => ['tei', '/rerank', 'tei', true],
    'infinity' => ['infinity', '/rerank', 'cohere', false],
    'infinity, required fields only' => ['infinity', '/rerank', 'cohere', true],
]);

it('depends only on fields the rerank answers promise', function (): void {
    $tei = OpenApiContract::load('tei');
    $infinity = OpenApiContract::load('infinity');

    expect($tei->requires($tei->schema('Rank'), 'index'))->toBeTrue()
        ->and($tei->requires($tei->schema('Rank'), 'score'))->toBeTrue()
        ->and($infinity->requires($infinity->schema('ReRankResult'), 'results'))->toBeTrue()
        ->and($infinity->requires($infinity->schema('_ReRankObject'), 'index'))->toBeTrue()
        ->and($infinity->requires($infinity->schema('_ReRankObject'), 'relevance_score'))->toBeTrue();
});

it('sends the multipart fields speaches declares and reads the text of its answer', function (bool $requiredOnly): void {
    $contract = OpenApiContract::load('speaches');
    config()->set('ai.servers.transcription', ['url' => 'http://speaches.test:8000', 'api_key' => null, 'timeout' => 120]);
    $answer = $contract->sample($contract->schema('Transcription'), $requiredOnly);
    Http::fake(['*' => Http::response($answer)]);

    $path = tempnam(sys_get_temp_dir(), 'wh') . '.mp3';
    file_put_contents($path, 'fake audio bytes');

    $text = new OpenAiTranscriber()->transcribe($path, 'audio/mpeg', new MediaAnalysisModelProfile('transcription', 'openai_compatible:Systran/faster-whisper-base', 'openai_compatible', 'Systran/faster-whisper-base'));
    @unlink($path);

    $fields = [];
    Http::assertSent(function (Request $request) use (&$fields): bool {
        foreach ($request->data() as $part) {
            $fields[$part['name']] = (string) $part['contents'];
        }

        return true;
    });

    expect($contract->violations($contract->requestSchema('/v1/audio/transcriptions', 'post', 'application/x-www-form-urlencoded'), $fields))->toBe([])
        ->and($text)->toBe($answer['text'])
        ->and($contract->requires($contract->schema('Transcription'), 'text'))->toBeTrue();
})->with([false, true]);

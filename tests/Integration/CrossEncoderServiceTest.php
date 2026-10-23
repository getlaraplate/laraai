<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Modules\AI\Ai\SelfHosted\AiServer;
use Modules\AI\Ai\SelfHosted\SelfHostedServiceException;
use Modules\AI\Services\CrossEncoderService;
use Modules\Core\Search\Contracts\IReranker;
use Modules\Core\Search\Contracts\IRerankerWithModel;

/**
 * A reranker that cannot score has to fail, not to answer: {@see EnsembleSearchService} catches the failure,
 * logs it and reports `reranked = false`, while a made-up answer (zeros) is taken for a successful rerank
 * that happens to change nothing.
 *
 * @return list<array{query: string, text: string}>
 */
function cross_encoder_pairs(int $count): array
{
    return array_map(static fn (int $i): array => ['query' => 'q', 'text' => "text {$i}"], range(1, $count));
}

function rerank_server(string $dialect = 'tei', ?string $model = null, string $url = 'http://rerank.test:8081', ?string $key = null): AiServer
{
    return new AiServer('rerank', $url, $key, 10, 32, $dialect, $model);
}

/**
 * An answer in the shape of the dialect: one score per index, in the given order of indexes.
 *
 * @param  array<int, float>  $scores  index => score
 * @param  list<int>|null  $order  the order the server lists them in; the indexes in ascending order when null
 * @return array<string, mixed>|list<array<string, mixed>>
 */
function rerank_answer(string $dialect, array $scores, ?array $order = null, ?string $model = null): array
{
    $order ??= array_keys($scores);
    $items = array_map(static fn (int $index): array => $dialect === 'tei'
        ? ['index' => $index, 'score' => $scores[$index]]
        : ['index' => $index, 'relevance_score' => $scores[$index]], $order);

    return $dialect === 'tei' ? $items : ['results' => $items, ...($model === null ? [] : ['model' => $model])];
}

beforeEach(function (): void {
    Sleep::fake();
});

it('implements the reranker contracts', function (): void {
    $service = new CrossEncoderService(rerank_server());

    expect($service)->toBeInstanceOf(IReranker::class)->toBeInstanceOf(IRerankerWithModel::class);
});

it('returns an empty list for no pairs without calling the server', function (): void {
    Http::preventStrayRequests();

    expect(new CrossEncoderService(rerank_server())->score([]))->toBe([]);
});

it('posts the query once with every text to /rerank for the tei dialect and places each score by its index', function (): void {
    Http::fake(['*/rerank' => Http::response(rerank_answer('tei', [0 => 0.1, 1 => 0.9, 2 => 0.5], [1, 2, 0]))]);

    $scores = new CrossEncoderService(rerank_server('tei'))->score(cross_encoder_pairs(3));

    expect($scores)->toBe([0.1, 0.9, 0.5]);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://rerank.test:8081/rerank'
        && $request['query'] === 'q'
        && $request['texts'] === ['text 1', 'text 2', 'text 3']
        && $request['truncate'] === true
        && ! isset($request['raw_scores']));
});

it('posts model, query and documents to /rerank for the cohere dialect and places each score by its index', function (): void {
    Http::fake(['*/rerank' => Http::response(rerank_answer('cohere', [0 => 0.2, 1 => 0.8, 2 => 0.6], [1, 2, 0], 'BAAI/bge-reranker-v2-m3'))]);

    $scores = new CrossEncoderService(rerank_server('cohere', 'BAAI/bge-reranker-v2-m3'))->score(cross_encoder_pairs(3));

    expect($scores)->toBe([0.2, 0.8, 0.6]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://rerank.test:8081/rerank'
        && $request['model'] === 'BAAI/bge-reranker-v2-m3'
        && $request['query'] === 'q'
        && $request['documents'] === ['text 1', 'text 2', 'text 3']);
});

it('keeps the scores within 0 and 1', function (string $dialect): void {
    Http::fake(['*/rerank' => Http::response(rerank_answer($dialect, [0 => 0.25, 1 => 1.7, 2 => -0.3]))]);

    expect(new CrossEncoderService(rerank_server($dialect, 'm'))->score(cross_encoder_pairs(3)))->toBe([0.25, 1.0, 0.0]);
})->with(['tei', 'cohere']);

it('fails instead of inventing scores when the answer is not usable', function (string $dialect, mixed $answer, string $reason): void {
    Http::fake(['*/rerank' => Http::response($answer)]);

    expect(fn () => new CrossEncoderService(rerank_server($dialect, 'm'))->score(cross_encoder_pairs(3)))
        ->toThrow(RuntimeException::class, $reason);
})->with(function (): Generator {
    foreach (['tei', 'cohere'] as $dialect) {
        $wrap = static fn (array $items): array => $dialect === 'tei' ? $items : ['results' => $items];
        $key = $dialect === 'tei' ? 'score' : 'relevance_score';

        yield "{$dialect}: a payload that is not a list of results" => [$dialect, ['oops' => true], 'malformed'];

        yield "{$dialect}: a repeated index" => [$dialect, $wrap([['index' => 0, $key => 0.1], ['index' => 0, $key => 0.2], ['index' => 2, $key => 0.3]]), 'index'];

        yield "{$dialect}: a missing index" => [$dialect, $wrap([['index' => 0, $key => 0.1], ['index' => 2, $key => 0.2], [$key => 0.3]]), 'index'];

        yield "{$dialect}: an index out of range" => [$dialect, $wrap([['index' => 0, $key => 0.1], ['index' => 1, $key => 0.2], ['index' => 7, $key => 0.3]]), 'index'];

        yield "{$dialect}: a score that is not a number" => [$dialect, $wrap([['index' => 0, $key => 0.1], ['index' => 1, $key => 'high'], ['index' => 2, $key => 0.3]]), 'not a number'];

        yield "{$dialect}: fewer scores than pairs" => [$dialect, $wrap([['index' => 0, $key => 0.1], ['index' => 1, $key => 0.2]]), '2 scores for 3 pairs'];

        yield "{$dialect}: more scores than pairs" => [$dialect, $wrap([['index' => 0, $key => 0.1], ['index' => 1, $key => 0.2], ['index' => 2, $key => 0.3], ['index' => 3, $key => 0.4]]), '4 scores for 3 pairs'];
    }
});

it('names the model from /info, cached, for the tei dialect, and never fails the rerank when /info is unavailable', function (): void {
    Http::fake([
        '*/rerank' => Http::response(rerank_answer('tei', [0 => 0.5])),
        '*/info' => Http::response(['model_id' => 'BAAI/bge-reranker-base', 'max_client_batch_size' => 64]),
    ]);
    $service = new CrossEncoderService(rerank_server('tei'));

    expect($service->scoreWithModel(cross_encoder_pairs(1))->model)->toBe('BAAI/bge-reranker-base')
        ->and($service->scoreWithModel(cross_encoder_pairs(1))->model)->toBe('BAAI/bge-reranker-base');

    Http::assertSentCount(3);
});

it('has no model name when /info is unavailable, and still scores', function (): void {
    Http::fake([
        '*/rerank' => Http::response(rerank_answer('tei', [0 => 0.5])),
        '*/info' => Http::response('not found', 404),
    ]);

    $result = new CrossEncoderService(rerank_server('tei'))->scoreWithModel(cross_encoder_pairs(1));

    expect($result->scores)->toBe([0.5])->and($result->model)->toBeNull();
});

it('names the model of the answer for the cohere dialect, or the configured one', function (?string $answered, string $expected): void {
    Http::fake(['*/rerank' => Http::response(rerank_answer('cohere', [0 => 0.5], null, $answered))]);

    expect(new CrossEncoderService(rerank_server('cohere', 'configured-model'))->scoreWithModel(cross_encoder_pairs(1))->model)->toBe($expected);
})->with([
    'the answered model' => ['answered-model', 'answered-model'],
    'the configured model when the answer names none' => [null, 'configured-model'],
]);

it('refuses the cohere dialect without a configured model and an unknown dialect', function (string $dialect, ?string $model, string $reason): void {
    Http::preventStrayRequests();

    expect(fn () => new CrossEncoderService(rerank_server($dialect, $model))->score(cross_encoder_pairs(1)))
        ->toThrow(RuntimeException::class, $reason);
})->with([
    'cohere without a model' => ['cohere', null, 'AI_RERANK_MODEL'],
    'an unknown dialect' => ['jina', 'm', 'dialect "jina"'],
]);

it('refuses pairs that do not share one query', function (): void {
    Http::preventStrayRequests();

    expect(fn () => new CrossEncoderService(rerank_server())->score([['query' => 'a', 'text' => 'x'], ['query' => 'b', 'text' => 'y']]))
        ->toThrow(RuntimeException::class, 'one query');
});

it('fails when the service answers with an error, and says a 424 is a model that is not a reranker', function (): void {
    Http::fake(['*/rerank' => Http::response('boom', 500)]);

    expect(fn () => new CrossEncoderService(rerank_server())->score(cross_encoder_pairs(2)))->toThrow(SelfHostedServiceException::class);

    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake(['*/rerank' => Http::response(['error' => 'Model is not a re-ranker model', 'error_type' => 'Backend'], 424)]);

    try {
        new CrossEncoderService(rerank_server('tei', url: 'http://other-rerank.test'))->score(cross_encoder_pairs(2));
        $this->fail('A 424 should surface as an exception.');
    } catch (SelfHostedServiceException $exception) {
        expect($exception->getMessage())->toContain('not a reranker')->and($exception->context()['status'])->toBe(424);
    }
});

it('sends a request id of 32 hex characters', function (): void {
    Http::fake(['*/rerank' => Http::response(rerank_answer('tei', [0 => 0.5]))]);

    new CrossEncoderService(rerank_server())->score(cross_encoder_pairs(1));

    Http::assertSent(fn (Request $request): bool => preg_match('/^[0-9a-f]{32}$/', $request->header('X-Request-Id')[0] ?? '') === 1);
});

/**
 * The server log shows the attempts of one rerank together only when they share the id.
 */
it('sends the same request id on the retry of a failed attempt', function (): void {
    Http::fakeSequence('*/rerank')
        ->push('busy', 503)
        ->push(rerank_answer('tei', [0 => 0.5]));

    new CrossEncoderService(rerank_server())->score(cross_encoder_pairs(1));

    $ids = Http::recorded()->map(fn (array $pair): string => $pair[0]->header('X-Request-Id')[0] ?? '')->all();

    expect($ids)->toHaveCount(2)
        ->and($ids[0])->toMatch('/^[0-9a-f]{32}$/')
        ->and($ids[1])->toBe($ids[0]);
});

/**
 * The search logs the rerank failure with the exception message ({@see EnsembleSearchService}), so the request
 * id rides in that message instead of a second log line.
 */
it('names the request id, the endpoint and the status in the failure it throws, without logging it twice', function (): void {
    Log::spy();
    Http::fake(['*/rerank' => Http::response('boom', 500)]);

    try {
        new CrossEncoderService(rerank_server())->score(cross_encoder_pairs(2));
        $this->fail('The rerank should have failed.');
    } catch (SelfHostedServiceException $exception) {
        $id = Http::recorded()->first()[0]->header('X-Request-Id')[0];

        expect($exception->getMessage())->toContain("request_id {$id}")
            ->and($exception->getMessage())->toContain('http://rerank.test:8081/rerank')
            ->and($exception->getMessage())->toContain('500')
            ->and($exception->getPrevious())->toBeNull();
    }

    Log::shouldNotHaveReceived('warning');
});

it('scores at most 64 pairs in one request', function (): void {
    Http::fake(['*/rerank' => static fn (Request $request) => Http::response(rerank_answer('tei', array_fill(0, count($request['texts']), 0.5)))]);

    expect(new CrossEncoderService(rerank_server())->score(cross_encoder_pairs(70)))->toHaveCount(64);
});

it('sends the key of the server as a bearer token, and none when it has none', function (): void {
    Http::fake(['*' => Http::response(rerank_answer('tei', [0 => 0.5]))]);

    new CrossEncoderService(rerank_server(key: 'secret'))->score(cross_encoder_pairs(1));
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer secret'));

    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake(['*' => Http::response(rerank_answer('tei', [0 => 0.5]))]);

    new CrossEncoderService(rerank_server())->score(cross_encoder_pairs(1));
    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
});

it('reads the rerank server of the configuration when it is given none, and fails when there is none', function (): void {
    config(['ai.servers.rerank' => ['url' => 'http://configured.test:9000/', 'api_key' => null, 'dialect' => 'tei', 'model' => null]]);
    Http::fake(['*' => Http::response(rerank_answer('tei', [0 => 0.5]))]);

    new CrossEncoderService()->score(cross_encoder_pairs(1));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://configured.test:9000/rerank');

    config(['ai.servers.rerank.url' => '']);

    expect(fn () => new CrossEncoderService()->score(cross_encoder_pairs(1)))->toThrow(RuntimeException::class, 'AI_RERANK_URL');
});

it('remembers that /info is unavailable for a while, so a proxy that only exposes /rerank costs one extra request, not one per search', function (): void {
    Http::fake([
        '*/rerank' => Http::response(rerank_answer('tei', [0 => 0.5])),
        '*/info' => Http::response('not found', 404),
    ]);
    $service = new CrossEncoderService(rerank_server('tei', url: 'http://proxied-rerank.test'));

    $service->scoreWithModel(cross_encoder_pairs(1));
    $service->scoreWithModel(cross_encoder_pairs(1));

    Http::assertSentCount(3);
});

it('does not retry a 424, the model of a server that is not a reranker', function (): void {
    Http::fake(['*/rerank' => Http::response(['error' => 'Model is not a re-ranker model'], 424)]);

    expect(fn () => new CrossEncoderService(rerank_server('tei', url: 'http://not-a-reranker.test'))->score(cross_encoder_pairs(1)))->toThrow(SelfHostedServiceException::class);

    Http::assertSentCount(1);
});

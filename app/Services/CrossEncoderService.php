<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\AI\Ai\SelfHosted\AiServer;
use Modules\AI\Ai\SelfHosted\AiServers;
use Modules\AI\Ai\SelfHosted\RerankDialect;
use Modules\AI\Ai\SelfHosted\SelfHostedServiceException;
use Modules\AI\Ai\SelfHosted\ServiceRequestId;
use Modules\Core\Search\Contracts\IRerankerWithModel;
use Modules\Core\Search\DTOs\RerankResult;
use RuntimeException;

/**
 * HTTP client for a rerank server, `ai.servers.rerank`: Text Embeddings Inference (dialect `tei`) or one that
 * speaks the Cohere shape (dialect `cohere`: Infinity, vLLM). It sends one query with the texts of the pairs
 * to `POST {url}/rerank` and receives relevance scores in [0, 1], placed by the index the server answers
 * with. There is no built-in address, so a host with no rerank server cannot rerank and says so instead of
 * calling a guessed one.
 */
final readonly class CrossEncoderService implements IRerankerWithModel
{
    /**
     * Seconds to connect to the server: it answers at once or it is not there, and Laravel's own 10 would
     * make every search wait that long, per attempt, on a host that drops packets.
     */
    private const int CONNECT_TIMEOUT = 2;

    /**
     * Seconds for the whole request, scoring included.
     */
    private const int TIMEOUT = 10;

    /**
     * Seconds the model name that `GET /info` gives is remembered.
     */
    private const int MODEL_CACHE_SECONDS = 600;

    /**
     * @param  AiServer|null  $server  the server to ask; the configured `ai.servers.rerank` when null
     */
    public function __construct(private ?AiServer $server = null) {}

    /**
     * Scores at most 64 pairs per request. The server either answers with one score per pair or this
     * throws: `EnsembleSearchService::rerankTopK()` catches the failure, logs it and keeps the fused order
     * with `meta['reranked'] = false`, which is how a caller (and the evaluation report) learns that no
     * reranking happened. Zeros made up for an unusable answer would pass for a real, unhelpful rerank.
     * The search logs the failure with its message, which carries the request id: this client adds no
     * log line of its own.
     *
     * @param  list<array{query: string, text: string}>  $pairs  every pair shares the query
     *
     * @throws SelfHostedServiceException when the server is unreachable or keeps answering with an error status
     * @throws RuntimeException when no server is configured, the dialect is unusable, the pairs do not share one query, or the answer is not one score per pair
     *
     * @return list<float>
     */
    public function score(array $pairs): array
    {
        return $this->rerank($pairs, false)->scores;
    }

    /**
     * Same as {@see self::score()}, with the model that scored the pairs where the server says it (`/info` of
     * a `tei` server, the answer of a `cohere` one, else the configured model). Null when it does not.
     *
     * @param  list<array{query: string, text: string}>  $pairs
     *
     * @throws SelfHostedServiceException when the server is unreachable or keeps answering with an error status
     * @throws RuntimeException when no server is configured, the dialect is unusable, the pairs do not share one query, or the answer is not one score per pair
     */
    public function scoreWithModel(array $pairs): RerankResult
    {
        return $this->rerank($pairs, true);
    }

    /**
     * @param  list<array{query: string, text: string}>  $pairs
     */
    private function rerank(array $pairs, bool $withModel): RerankResult
    {
        if ($pairs === []) {
            return new RerankResult([]);
        }

        $server = $this->target();
        $dialect = $this->dialect($server);
        $pairs = array_slice($pairs, 0, 64);
        $query = $pairs[0]['query'];

        foreach ($pairs as $pair) {
            throw_if($pair['query'] !== $query, RuntimeException::class, 'The pairs of one rerank call must share one query.');
        }

        $endpoint = $server->url . '/rerank';
        // Set once on the pending request, so every attempt of `retry()` sends the same id.
        $request_id = new ServiceRequestId('rerank', $endpoint);
        $request = $this->request($server)->retry(2, 200)->withHeaders($request_id->header());

        try {
            // `retry()` rethrows the last failure once its attempts are spent, so a response that gets here is a success.
            $response = $request->post($endpoint, $dialect->body($query, array_column($pairs, 'text'), $server->model));
        } catch (ConnectionException|RequestException $exception) {
            throw $this->failure($request_id, $exception);
        }

        $payload = $response->json();
        $answered = is_array($payload) && is_string($payload['model'] ?? null) && $payload['model'] !== '' ? $payload['model'] : null;
        $model = match (true) {
            ! $withModel => null,
            $dialect === RerankDialect::Tei => $this->infoModel($server),
            default => $answered ?? $server->model,
        };

        return new RerankResult($this->parseScores($dialect, $payload, count($pairs)), $model);
    }

    /**
     * The server to ask. Read when scoring, not when constructed: this class is resolved with the search
     * service, and a missing URL must fail the rerank (which search survives), not the resolution (which it would not).
     *
     * @throws RuntimeException
     */
    private function target(): AiServer
    {
        $server = $this->server ?? new AiServers()->rerank();

        throw_unless($server->isConfigured(), RuntimeException::class, 'The rerank server URL is not configured: set AI_RERANK_URL (and AI_RERANK_DIALECT, AI_RERANK_MODEL for a cohere-style server).');

        return $server;
    }

    /**
     * @throws RuntimeException
     */
    private function dialect(AiServer $server): RerankDialect
    {
        $dialect = RerankDialect::tryFrom((string) $server->dialect);

        throw_if($dialect === null, RuntimeException::class, "The rerank dialect \"{$server->dialect}\" is not one of tei, cohere.");
        throw_if($dialect === RerankDialect::Cohere && ($server->model === null || $server->model === ''), RuntimeException::class, 'The cohere rerank dialect needs the model to rank with: set AI_RERANK_MODEL.');

        return $dialect;
    }

    private function request(AiServer $server): PendingRequest
    {
        $request = Http::connectTimeout(self::CONNECT_TIMEOUT)->timeout(self::TIMEOUT);

        return $server->apiKey !== null && $server->apiKey !== '' ? $request->withToken($server->apiKey) : $request;
    }

    /**
     * The failure to throw for a call that failed: a 424 of a `tei` server means the model it loaded is not a reranker.
     */
    private function failure(ServiceRequestId $request_id, ConnectionException|RequestException $exception): SelfHostedServiceException
    {
        $failure = $request_id->exception($exception);

        if (ServiceRequestId::statusOf($exception) !== 424) {
            return $failure;
        }

        return new SelfHostedServiceException($failure->getMessage() . ' The model the server runs is not a reranker.', $failure->context(), $exception);
    }

    /**
     * The model a `tei` server runs, from `GET /info`, remembered for a while; null when the server does not say.
     * Never fails the rerank: the name is for the report only.
     */
    private function infoModel(AiServer $server): ?string
    {
        $key = 'ai:rerank:model:' . md5($server->url);
        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = $this->request($server)->timeout(5)->get($server->url . '/info');
        } catch (ConnectionException) {
            return null;
        }

        $model = $response->successful() ? $response->json('model_id') : null;

        if (! is_string($model) || $model === '') {
            return null;
        }

        Cache::put($key, $model, self::MODEL_CACHE_SECONDS);

        return $model;
    }

    /**
     * @throws RuntimeException
     *
     * @return list<float>
     */
    private function parseScores(RerankDialect $dialect, mixed $payload, int $expected_count): array
    {
        $results = $dialect->results($payload);

        throw_if($results === null, RuntimeException::class, 'The rerank answer is malformed: it carries no list of results.');
        throw_if($expected_count !== count($results), RuntimeException::class, 'The rerank server returned ' . count($results) . " scores for {$expected_count} pairs.");

        $scores = [];

        foreach ($results as $position => $result) {
            $index = is_array($result) && is_int($result['index'] ?? null) ? $result['index'] : null;

            throw_if($index === null || $index < 0 || $index >= $expected_count || isset($scores[$index]), RuntimeException::class, "The rerank result #{$position} has a missing, repeated or out-of-range index.");

            $score = $result[$dialect->scoreKey()] ?? null;

            throw_unless(is_int($score) || is_float($score), RuntimeException::class, "The rerank score of index {$index} is not a number.");

            $scores[$index] = max(0.0, min(1.0, (float) $score));
        }

        ksort($scores);

        return array_values($scores);
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\AI\Ai\SelfHosted\AiServer;
use Modules\AI\Ai\SelfHosted\AiServers;
use Modules\AI\Ai\SelfHosted\SelfHostedServiceException;
use Modules\AI\Ai\SelfHosted\ServiceRequestId;
use Throwable;

/**
 * Which model the embeddings server of a profile really runs and whether its vectors fit the profile. The probe
 * is a `POST /v1/embeddings` for a fixed text, which every OpenAI-compatible server answers: the answer names the
 * model, carries the vector (length and norm are checked) and is authoritative because a multi-model server
 * answers for the model the request asked for. A server that has `GET /info` (Text Embeddings Inference) adds a
 * second witness; one that has not is simply not asked twice. Shared by `ai:embeddings:repair`,
 * `ai:embeddings:switch` and the switch preview. Every call sends a request id; a failed one logs it and throws it.
 */
final readonly class EmbeddingServerIdentity
{
    /**
     * Seconds `GET /info` may take: it is a hint, never a reason to wait.
     */
    private const int INFO_TIMEOUT = 5;

    public function __construct(private AiServers $servers = new AiServers()) {}

    public function server(EmbeddingModelProfile $profile): AiServer
    {
        return $this->servers->forProfile($profile);
    }

    /**
     * Probes the server of the profile. `$timeout` overrides the configured request timeout for this call only (a
     * quick latency measurement must not wait as long as a job would); `$withInfo` false skips `GET /info`.
     *
     * @throws EmbeddingDimensionMismatch when the answer carries no vector or one of another length
     * @throws SelfHostedServiceException when the server cannot be reached or answers an error
     */
    public function inspect(EmbeddingModelProfile $profile, ?int $timeout = null, bool $withInfo = true): EmbeddingInspection
    {
        $server = $this->servers->forProfile($profile);

        throw_unless($server->isConfigured(), SelfHostedServiceException::class, "The embeddings server \"{$server->name}\" has no URL.", ['service' => 'embeddings', 'endpoint' => '', 'status' => null, 'request_id' => '']);

        $url = $server->url . '/v1/embeddings';
        $response = $this->send($url, $server, static fn (PendingRequest $request): Response => $request
            ->timeout($timeout ?? $server->timeout)
            ->acceptJson()
            ->post($url, ['model' => $profile->serviceModel, 'input' => [EmbeddingDimensionProbe::TEXT], 'encoding_format' => 'float']));

        $vector = $response->json('data.0.embedding');

        EmbeddingDimensionProbe::assertDimensions($profile, $vector);

        $info = $withInfo ? $this->info($server) : [];

        return new EmbeddingInspection(
            probeModel: self::name($response->json('model')),
            infoModel: self::name($info['model_id'] ?? null),
            norm: sqrt(array_sum(array_map(static fn (mixed $component): float => (float) $component ** 2, (array) $vector))),
            maxClientBatchSize: is_int($info['max_client_batch_size'] ?? null) ? $info['max_client_batch_size'] : null,
        );
    }

    /**
     * Why the server of the profile cannot be switched to or back to, or null when it can: it answers, runs the
     * profile's model (probe and `/info` agreeing), returns vectors of the profile's length and, when the profile
     * normalises, of norm 1. Unlike the repair, a server that cannot be identified stops the switch.
     */
    public function refusal(EmbeddingModelProfile $profile): ?string
    {
        $server = $this->servers->forProfile($profile);

        if (! $server->isConfigured()) {
            return "the embeddings server \"{$server->name}\" has no URL";
        }

        $endpoint = ServiceRequestId::endpointOf($server->url . '/v1/embeddings');

        try {
            $inspection = $this->inspect($profile);
        } catch (EmbeddingDimensionMismatch $mismatch) {
            return $mismatch->getMessage();
        } catch (Throwable $exception) {
            return "the embedding server probe failed at {$endpoint}: {$exception->getMessage()}";
        }

        if ($inspection->reportedModel() === null) {
            return 'the embedding server at ' . ServiceRequestId::endpointOf($server->url) . " does not report which model it runs, so it cannot be checked against \"{$profile->serviceModel}\"";
        }

        return $inspection->modelConflict($profile) ?? $inspection->normProblem($profile);
    }

    private static function name(mixed $model): ?string
    {
        return is_string($model) && $model !== '' ? $model : null;
    }

    /**
     * What `GET /info` says, or nothing: a server without the route, or one that is slow, is not a failure.
     *
     * @return array<string, mixed>
     */
    private function info(AiServer $server): array
    {
        $url = $server->url . '/info';

        try {
            $response = $this->request($server, new ServiceRequestId('embeddings', $url))->timeout(self::INFO_TIMEOUT)->get($url);
        } catch (ConnectionException) {
            return [];
        }

        $body = $response->successful() ? $response->json() : null;

        return is_array($body) ? $body : [];
    }

    /**
     * Sends one call with a request id; a call that cannot reach the server or gets an error status logs the id
     * and fails with it.
     *
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws SelfHostedServiceException
     */
    private function send(string $url, AiServer $server, callable $call): Response
    {
        $request_id = new ServiceRequestId('embeddings', $url);

        try {
            return $call($this->request($server, $request_id))->throw();
        } catch (ConnectionException|RequestException $exception) {
            $request_id->logFailure(ServiceRequestId::statusOf($exception));

            throw $request_id->exception($exception);
        }
    }

    private function request(AiServer $server, ServiceRequestId $request_id): PendingRequest
    {
        $request = Http::withHeaders($request_id->header());

        return $server->apiKey !== null && $server->apiKey !== '' ? $request->withToken($server->apiKey) : $request;
    }
}

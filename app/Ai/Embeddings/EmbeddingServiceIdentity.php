<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings;

use function ai_config_int;
use function ai_config_nullable_string;
use function ai_config_string;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\SelfHosted\SelfHostedServiceException;
use Modules\AI\Ai\SelfHosted\ServiceRequestId;

/**
 * Which model the sentence-transformers service really runs. `/health` echoes the model the service
 * was started with; the answer to a probe on `/embed` names the model that embedded it, which is
 * authoritative because a multi-model service answers for the model the request asked for. Shared by
 * `ai:embeddings:repair` and `ai:embeddings:switch`, which word their own messages. Every call sends a
 * request id; a failed one logs it and throws it in the message the commands print.
 */
final readonly class EmbeddingServiceIdentity
{
    /**
     * The version of the service API this client speaks: the `api_version` of `/health`.
     */
    public const int API_VERSION = 1;

    /**
     * The model to check and where it comes from: the probe's answer first, `/health` as fallback;
     * null when neither names one.
     *
     * @return array{model: string, source: string}|null
     */
    public static function reported(?string $probeModel, ?string $healthModel): ?array
    {
        if ($probeModel !== null) {
            return ['model' => $probeModel, 'source' => '/embed'];
        }

        if ($healthModel !== null) {
            return ['model' => $healthModel, 'source' => '/health'];
        }

        return null;
    }

    public function url(): string
    {
        return mb_rtrim(ai_config_string('ai.providers.sentence_transformers.url', 'http://localhost:8000'), '/');
    }

    /**
     * The model `/health` reports, or null when it names none. A service that reports an `api_version`
     * other than {@see self::API_VERSION} gets a warning, once per process: it may still answer, but not
     * as this client expects. A service that reports none predates the field and is not warned about.
     *
     * @throws SelfHostedServiceException when the service cannot be reached or answers an error
     */
    public function healthModel(): ?string
    {
        $url = $this->url() . '/health';
        $response = $this->send($url, static fn (PendingRequest $request, string $url): Response => $request->timeout(5)->get($url));

        self::checkApiVersion($url, $response->json('api_version'));

        return self::modelName($response->json('model'));
    }

    /**
     * Embeds the probe text with the payload the jobs send and checks the vector against the
     * profile's dimensions. `$timeout` overrides the configured request timeout for this call only
     * (a quick latency measurement must not wait as long as a job would).
     *
     * @throws EmbeddingDimensionMismatch when the answer carries no vector or one of another length
     * @throws SelfHostedServiceException when the service cannot be reached or answers an error
     *
     * @return string|null the model the answer names, or null when it names none
     */
    public function probeModel(EmbeddingModelProfile $profile, ?int $timeout = null): ?string
    {
        $api_key = ai_config_nullable_string('ai.providers.sentence_transformers.api_key');
        $timeout ??= ai_config_int('ai.providers.sentence_transformers.timeout', 30);

        $response = $this->send($this->url() . '/embed', static function (PendingRequest $request, string $url) use ($api_key, $timeout, $profile): Response {
            $request = $request->timeout($timeout)->acceptJson();

            if ($api_key !== null && $api_key !== '') {
                $request = $request->withToken($api_key);
            }

            return $request->post($url, [
                'texts' => [EmbeddingDimensionProbe::TEXT],
                'truncation' => true,
                'normalize_embeddings' => true,
                'max_length' => 512,
                'model' => $profile->serviceModel,
            ]);
        });

        EmbeddingDimensionProbe::assertDimensions($profile, $response->json('embeddings.0'));

        return self::modelName($response->json('model'));
    }

    private static function checkApiVersion(string $url, mixed $received): void
    {
        // `"1"` and `1.0` name the same version: the warning is about the version, not about its JSON type.
        if ($received === null || (is_numeric($received) && (float) $received === (float) self::API_VERSION)) {
            return;
        }

        $endpoint = ServiceRequestId::endpointOf($url);

        // `once()` keys on this call site and the closure's variables: one warning per process and per answer.
        once(static fn () => Log::warning('The embedding service speaks an API version this client does not know', [
            'service' => 'sentence_transformers',
            'endpoint' => $endpoint,
            'expected_api_version' => self::API_VERSION,
            'received_api_version' => $received,
        ]));
    }

    private static function modelName(mixed $model): ?string
    {
        return is_string($model) && $model !== '' ? $model : null;
    }

    /**
     * Sends one call with a request id; a call that cannot reach the service or gets an error status logs
     * the id and fails with it.
     *
     * @param  callable(PendingRequest, string): Response  $call
     *
     * @throws SelfHostedServiceException
     */
    private function send(string $url, callable $call): Response
    {
        $request_id = new ServiceRequestId('sentence_transformers', $url);

        try {
            return $call(Http::withHeaders($request_id->header()), $url)->throw();
        } catch (ConnectionException|RequestException $exception) {
            $request_id->logFailure(ServiceRequestId::statusOf($exception));

            throw $request_id->exception($exception);
        }
    }
}

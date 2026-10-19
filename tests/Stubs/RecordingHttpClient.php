<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;
use RuntimeException;

/**
 * A NeuronAI HTTP client that never touches the network: it records the last
 * request a provider sent and answers with a canned response. Injected into a
 * real provider so a test can prove the provider's request marshaling and
 * response parsing — the whole model call minus the socket — without an API key.
 */
final class RecordingHttpClient implements HttpClientInterface
{
    public ?HttpRequest $lastRequest = null;

    /**
     * @param  array<string, mixed>  $responseBody
     */
    public function __construct(
        private readonly array $responseBody,
        private readonly int $statusCode = 200,
    ) {}

    public function request(HttpRequest $request): HttpResponse
    {
        $this->lastRequest = $request;

        $response = new HttpResponse($this->statusCode, (string) json_encode($this->responseBody));

        if ($response->statusCode >= 400) {
            throw HttpException::statusError($request, $response);
        }

        return $response;
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        throw new RuntimeException('The recording HTTP client does not support streaming.');
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs;

use Closure;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;
use Override;
use RuntimeException;

/**
 * A NeuronAI HTTP client that never touches the network: it records every request it gets and answers what
 * the closure returns for it. An answer with an error status is thrown as Neuron's own client does.
 */
final class ScriptedHttpClient implements HttpClientInterface
{
    /**
     * @var list<HttpRequest>
     */
    public array $requests = [];

    /**
     * @param  Closure(HttpRequest): HttpResponse  $respond
     */
    public function __construct(private readonly Closure $respond) {}

    #[Override]
    public function request(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;

        $response = ($this->respond)($request);

        if ($response->statusCode >= 400) {
            throw HttpException::statusError($request, $response);
        }

        return $response;
    }

    #[Override]
    public function stream(HttpRequest $request): StreamInterface
    {
        throw new RuntimeException('The scripted HTTP client does not support streaming.');
    }
}

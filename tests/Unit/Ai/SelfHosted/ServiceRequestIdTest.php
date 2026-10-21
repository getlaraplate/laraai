<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\SelfHosted\SelfHostedServiceException;
use Modules\AI\Ai\SelfHosted\ServiceRequestId;

it('is 32 lowercase hex characters, new for every call', function (): void {
    $first = new ServiceRequestId('whisper', 'http://whisper.test/transcribe');
    $second = new ServiceRequestId('whisper', 'http://whisper.test/transcribe');

    expect($first->value)->toMatch('/^[0-9a-f]{32}$/')
        ->and($second->value)->not->toBe($first->value)
        ->and($first->header())->toBe(['X-Request-Id' => $first->value]);
});

/**
 * The log line names the call, never what it carried: a URL may hold a password or a query, and neither
 * belongs in a log.
 */
it('keeps credentials and the query out of the endpoint it reports', function (): void {
    $request_id = new ServiceRequestId('sentence_transformers', 'https://user:secret@st.test:8443/embed?key=abc');

    expect($request_id->endpoint)->toBe('https://st.test:8443/embed');
});

it('logs one warning naming the service, the endpoint, the status and the request id', function (): void {
    Log::spy();
    $request_id = new ServiceRequestId('cross_encoder', 'http://st.test/score');

    $request_id->logFailure(503);

    Log::shouldHaveReceived('warning')->once()->with(Mockery::type('string'), [
        'service' => 'cross_encoder',
        'endpoint' => 'http://st.test/score',
        'status' => 503,
        'request_id' => $request_id->value,
    ]);
});

it('reads the status of a failed call from the exception, or none when the service was not reached', function (Throwable $exception, ?int $status): void {
    expect(ServiceRequestId::statusOf($exception))->toBe($status);
})->with([
    'an http client error answer' => [new RequestException(new Response(new GuzzleResponse(502))), 502],
    'a guzzle error answer' => [new ServerException('boom', new GuzzleRequest('POST', 'embed'), new GuzzleResponse(500)), 500],
    'an http client connection failure' => [new ConnectionException('refused'), null],
    'a guzzle connection failure' => [new ConnectException('refused', new GuzzleRequest('POST', 'embed')), null],
]);

it('carries the request id in the message and the report context of the exception it builds', function (): void {
    $request_id = new ServiceRequestId('sentence_transformers', 'http://st.test/health');
    $previous = new ConnectionException('connection refused');

    $exception = $request_id->exception($previous);

    expect($exception)->toBeInstanceOf(SelfHostedServiceException::class)
        ->and($exception->getMessage())->toStartWith("[request_id {$request_id->value}]")
        ->and($exception->getMessage())->toContain('connection refused')
        ->and($exception->getPrevious())->toBe($previous)
        ->and($exception->context())->toBe([
            'service' => 'sentence_transformers',
            'endpoint' => 'http://st.test/health',
            'status' => null,
            'request_id' => $request_id->value,
        ]);
});

/**
 * The message of the HTTP exception names the URL it called, credentials and query included: the message
 * built from it keeps the reason and drops those.
 */
it('keeps credentials and the query out of the message of the exception it builds', function (): void {
    $request_id = new ServiceRequestId('sentence_transformers', 'https://TOKEN@gw.test/embed?key=abc');
    $previous = new ConnectException(
        'cURL error 7: Failed to connect: Connection refused (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://TOKEN@gw.test/embed?key=abc',
        new GuzzleRequest('POST', 'https://TOKEN@gw.test/embed?key=abc'),
    );

    $message = $request_id->exception($previous)->getMessage();

    expect($message)->toContain('Connection refused')
        ->and($message)->toContain('for https://gw.test/embed')
        ->and($message)->toContain('(see https://curl.haxx.se/libcurl/c/libcurl-errors.html)')
        ->and($message)->not->toContain('TOKEN')
        ->and($message)->not->toContain('key=abc');
});

it('logs an error of the client apart from a failed call, naming its class only', function (): void {
    Log::spy();
    $request_id = new ServiceRequestId('whisper', 'http://whisper.test/transcribe');

    $request_id->logClientError(new LogicException('/tmp/secret-file.mp3 broke'));

    Log::shouldHaveReceived('warning')->once()->with('Self-hosted AI service call could not be handled', [
        'service' => 'whisper',
        'endpoint' => 'http://whisper.test/transcribe',
        'request_id' => $request_id->value,
        'exception' => LogicException::class,
    ]);
});

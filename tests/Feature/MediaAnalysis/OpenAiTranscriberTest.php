<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\MediaAnalysis\MediaAnalysisModelProfile;
use Modules\AI\Ai\MediaAnalysis\Transcription\OpenAiTranscriber;

function transcriptionProfile(): MediaAnalysisModelProfile
{
    return new MediaAnalysisModelProfile('transcription', 'openai_compatible:Systran/faster-whisper-base', 'openai_compatible', 'Systran/faster-whisper-base');
}

function audioFixture(): string
{
    $path = tempnam(sys_get_temp_dir(), 'wh') . '.mp3';
    file_put_contents($path, 'fake audio bytes');

    return $path;
}

/**
 * The contents of a form part of a multipart request.
 */
function transcriptionPart(Request $request, string $name): ?string
{
    $part = collect($request->data())->firstWhere('name', $name);

    return $part === null ? null : (string) $part['contents'];
}

beforeEach(function (): void {
    config()->set('ai.servers.transcription', ['url' => 'http://speaches.test:8000', 'api_key' => null, 'timeout' => 120]);
});

it('posts the file and the model of the profile to /v1/audio/transcriptions and returns the trimmed transcript', function (): void {
    config()->set('ai.servers.transcription.api_key', 'secret');
    Http::fake(['speaches.test:8000/*' => Http::response(['text' => "  hello world \n", 'language' => 'en'])]);

    $path = audioFixture();
    $result = new OpenAiTranscriber()->transcribe($path, 'audio/mpeg', transcriptionProfile());

    expect($result)->toBe('hello world');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://speaches.test:8000/v1/audio/transcriptions'
        && $request->isMultipart()
        && transcriptionPart($request, 'file') === 'fake audio bytes'
        && transcriptionPart($request, 'model') === 'Systran/faster-whisper-base'
        && transcriptionPart($request, 'response_format') === 'json'
        && $request->hasHeader('Authorization', 'Bearer secret'));

    @unlink($path);
});

it('sends no Authorization header when the server has no key', function (): void {
    Http::fake(['speaches.test:8000/*' => Http::response(['text' => 'hello'])]);

    $path = audioFixture();
    new OpenAiTranscriber()->transcribe($path, 'audio/mpeg', transcriptionProfile());

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));

    @unlink($path);
});

it('is a no-op when no transcription server is configured', function (): void {
    config()->set('ai.servers.transcription.url', '');
    Http::fake();

    $path = audioFixture();
    $result = new OpenAiTranscriber()->transcribe($path, 'audio/mpeg', transcriptionProfile());

    expect($result)->toBeNull();
    Http::assertNothingSent();

    @unlink($path);
});

it('returns null for a file that is not there, without calling the server', function (): void {
    Http::fake();

    expect(new OpenAiTranscriber()->transcribe('/nonexistent/file.mp3', 'audio/mpeg', transcriptionProfile()))->toBeNull();
    Http::assertNothingSent();
});

it('returns null when the answer carries no text or an empty one', function (array $answer): void {
    Http::fake(['speaches.test:8000/*' => Http::response($answer)]);

    $path = audioFixture();

    expect(new OpenAiTranscriber()->transcribe($path, 'audio/mpeg', transcriptionProfile()))->toBeNull();

    @unlink($path);
})->with([[['language' => 'en']], [['text' => '  ']], [['text' => null]]]);

it('returns null when the server responds with an error', function (int $status): void {
    Http::fake(['speaches.test:8000/*' => Http::response('boom', $status)]);

    $path = audioFixture();

    expect(new OpenAiTranscriber()->transcribe($path, 'audio/mpeg', transcriptionProfile()))->toBeNull();

    @unlink($path);
})->with([500, 413, 422]);

it('sends a request id of 32 hex characters', function (): void {
    Http::fake(['speaches.test:8000/*' => Http::response(['text' => 'hello'])]);

    $path = audioFixture();
    new OpenAiTranscriber()->transcribe($path, 'audio/mpeg', transcriptionProfile());

    Http::assertSent(fn (Request $request): bool => preg_match('/^[0-9a-f]{32}$/', $request->header('X-Request-Id')[0] ?? '') === 1);

    @unlink($path);
});

/**
 * A missing transcript must not fail the media analysis (M12), but a failed call has to leave a trace that
 * leads to the server log: the request id, and nothing of the media itself.
 */
it('returns null and logs one warning with the request id when the server refuses the upload as too large', function (): void {
    Log::spy();
    Http::fake(['speaches.test:8000/*' => Http::response('Request Entity Too Large', 413)]);

    $path = audioFixture();
    $result = new OpenAiTranscriber()->transcribe($path, 'audio/mpeg', transcriptionProfile());
    $id = Http::recorded()->first()[0]->header('X-Request-Id')[0];

    expect($result)->toBeNull();
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $context === [
            'service' => 'transcription',
            'endpoint' => 'http://speaches.test:8000/v1/audio/transcriptions',
            'status' => 413,
            'request_id' => $id,
        ] && ! str_contains($message . json_encode($context), basename($path)),
    );

    @unlink($path);
});

it('returns null and logs the request id without a status when the server cannot be reached', function (): void {
    Log::spy();
    Http::fake(['speaches.test:8000/*' => static fn () => throw new ConnectionException('connection refused')]);

    $path = audioFixture();
    $result = new OpenAiTranscriber()->transcribe($path, 'audio/mpeg', transcriptionProfile());

    expect($result)->toBeNull();
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $context['service'] === 'transcription'
            && $context['status'] === null
            && preg_match('/^[0-9a-f]{32}$/', $context['request_id']) === 1,
    );

    @unlink($path);
});

it('never logs the password of the url or the key', function (): void {
    Log::spy();
    config()->set('ai.servers.transcription', ['url' => 'http://user:url-password@speaches.test:8000', 'api_key' => 'secret-key', 'timeout' => 120]);
    Http::fake(['speaches.test:8000/*' => Http::response('boom', 500)]);

    $path = audioFixture();
    new OpenAiTranscriber()->transcribe($path, 'audio/mpeg', transcriptionProfile());

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => ! str_contains($message . json_encode($context), 'url-password') && ! str_contains($message . json_encode($context), 'secret-key'),
    );

    @unlink($path);
});

it('still returns null without throwing when the log itself fails', function (): void {
    Log::shouldReceive('warning')->andThrow(new UnexpectedValueException('log stream not writable'));
    Http::fake(['speaches.test:8000/*' => Http::response(['error' => 'busy'], 503)]);

    $path = audioFixture();

    expect(new OpenAiTranscriber()->transcribe($path, 'audio/mpeg', transcriptionProfile()))->toBeNull();

    @unlink($path);
});

/**
 * A failure of the client itself is not a server that could not be reached: it is logged apart, by class,
 * so it is not taken for an outage.
 */
it('returns null and logs a client error apart when the call fails for another reason than the server', function (): void {
    Log::spy();
    Http::fake(['speaches.test:8000/*' => static fn () => throw new LogicException('a bug')]);

    $path = audioFixture();

    expect(new OpenAiTranscriber()->transcribe($path, 'audio/mpeg', transcriptionProfile()))->toBeNull();
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'Self-hosted AI service call could not be handled'
            && $context['exception'] === LogicException::class,
    );

    @unlink($path);
});

<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\MediaAnalysis\MediaAnalysisModelProfile;
use Modules\AI\Ai\MediaAnalysis\Transcription\WhisperTranscriber;

function transcriptionProfile(): MediaAnalysisModelProfile
{
    return new MediaAnalysisModelProfile('transcription', 'whisper', 'whisper', '');
}

function audioFixture(): string
{
    $path = tempnam(sys_get_temp_dir(), 'wh') . '.mp3';
    file_put_contents($path, 'fake audio bytes');

    return $path;
}

it('posts the file to the configured Whisper service and returns the transcript', function (): void {
    config()->set('ai.providers.whisper.url', 'http://whisper.test');
    config()->set('ai.providers.whisper.api_key', 'secret');
    Http::fake(['whisper.test/*' => Http::response(['text' => 'hello world', 'language' => 'en'])]);

    $path = audioFixture();
    $result = (new WhisperTranscriber())->transcribe($path, 'audio/mpeg', transcriptionProfile());

    expect($result)->toBe('hello world');
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/transcribe')
        && $request->hasHeader('Authorization', 'Bearer secret'));

    @unlink($path);
});

it('is a no-op when no Whisper URL is configured', function (): void {
    config()->set('ai.providers.whisper.url', null);
    Http::fake();

    $path = audioFixture();
    $result = (new WhisperTranscriber())->transcribe($path, 'audio/mpeg', transcriptionProfile());

    expect($result)->toBeNull();
    Http::assertNothingSent();

    @unlink($path);
});

it('returns null when the service responds with an error', function (): void {
    config()->set('ai.providers.whisper.url', 'http://whisper.test');
    Http::fake(['whisper.test/*' => Http::response('boom', 500)]);

    $path = audioFixture();
    $result = (new WhisperTranscriber())->transcribe($path, 'audio/mpeg', transcriptionProfile());

    expect($result)->toBeNull();

    @unlink($path);
});

it('sends a request id of 32 hex characters', function (): void {
    config()->set('ai.providers.whisper.url', 'http://whisper.test');
    Http::fake(['whisper.test/*' => Http::response(['text' => 'hello', 'language' => 'en', 'duration' => 1.0])]);

    $path = audioFixture();
    (new WhisperTranscriber())->transcribe($path, 'audio/mpeg', transcriptionProfile());

    Http::assertSent(fn ($request): bool => preg_match('/^[0-9a-f]{32}$/', $request->header('X-Request-Id')[0] ?? '') === 1);

    @unlink($path);
});

/**
 * A missing transcript must not fail the media analysis (M12), but a failed call has to leave a trace that
 * leads to the service log: the request id, and nothing of the media itself.
 */
it('returns null and logs one warning with the request id when the service is busy', function (): void {
    Log::spy();
    config()->set('ai.providers.whisper.url', 'http://whisper.test');
    Http::fake(['whisper.test/*' => Http::response(['error' => 'busy'], 503, ['Retry-After' => '5'])]);

    $path = audioFixture();
    $result = (new WhisperTranscriber())->transcribe($path, 'audio/mpeg', transcriptionProfile());
    $id = Http::recorded()->first()[0]->header('X-Request-Id')[0];

    expect($result)->toBeNull();
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $context === [
            'service' => 'whisper',
            'endpoint' => 'http://whisper.test/transcribe',
            'status' => 503,
            'request_id' => $id,
        ] && ! str_contains($message . json_encode($context), basename($path)),
    );

    @unlink($path);
});

it('returns null and logs the request id without a status when the service cannot be reached', function (): void {
    Log::spy();
    config()->set('ai.providers.whisper.url', 'http://whisper.test');
    Http::fake(['whisper.test/*' => static fn () => throw new ConnectionException('connection refused')]);

    $path = audioFixture();
    $result = (new WhisperTranscriber())->transcribe($path, 'audio/mpeg', transcriptionProfile());

    expect($result)->toBeNull();
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $context['service'] === 'whisper'
            && $context['status'] === null
            && preg_match('/^[0-9a-f]{32}$/', $context['request_id']) === 1,
    );

    @unlink($path);
});

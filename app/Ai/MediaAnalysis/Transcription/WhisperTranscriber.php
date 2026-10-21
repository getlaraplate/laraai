<?php

declare(strict_types=1);

namespace Modules\AI\Ai\MediaAnalysis\Transcription;

use function ai_config_string;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Modules\AI\Ai\MediaAnalysis\Contracts\MediaTranscriber;
use Modules\AI\Ai\MediaAnalysis\MediaAnalysisModelProfile;
use Modules\AI\Ai\SelfHosted\ServiceRequestId;
use Throwable;

/**
 * Transcribes audio/video by POSTing the file to the self-hosted Whisper service
 * (Task 8), mirroring the self-hosted embedding service pattern: base URL +
 * optional Bearer key from config. When no URL is configured, or on any error, it
 * returns null so the media still indexes without a transcript (M12). Returns the
 * transcript in the spoken/source language (M17). A failed call logs one warning with
 * its request id (and nothing of the media), which the service log repeats.
 */
final class WhisperTranscriber implements MediaTranscriber
{
    public function transcribe(string $path, string $mimeType, MediaAnalysisModelProfile $profile): ?string
    {
        $url = ai_config_string('ai.providers.whisper.url', '');

        if ($url === '' || ! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $timeout = config('ai.providers.whisper.timeout', 120);
        $timeout = is_numeric($timeout) ? (int) $timeout : 120;

        $endpoint = mb_rtrim($url, '/') . '/transcribe';
        $request_id = new ServiceRequestId('whisper', $endpoint);

        try {
            $request = Http::timeout($timeout)
                ->withHeaders($request_id->header())
                ->attach('file', $contents, basename($path));

            $apiKey = ai_config_string('ai.providers.whisper.api_key', '');

            if ($apiKey !== '') {
                $request = $request->withToken($apiKey);
            }

            $response = $request->post($endpoint);

            if (! $response->successful()) {
                self::quietly(static fn () => $request_id->logFailure($response->status()));

                return null;
            }

            $text = $response->json('text');
        } catch (ConnectionException|RequestException $exception) {
            self::quietly(static fn () => $request_id->logFailure(ServiceRequestId::statusOf($exception)));

            return null;
        } catch (Throwable $exception) {
            self::quietly(static fn () => $request_id->logClientError($exception));

            return null;
        }

        return is_string($text) && mb_trim($text) !== '' ? mb_trim($text) : null;
    }

    /**
     * Logs without ever throwing: a log that cannot be written must not turn a missing transcript into a
     * failed analysis.
     *
     * @param  callable(): void  $log
     */
    private static function quietly(callable $log): void
    {
        rescue($log, report: false);
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Ai\MediaAnalysis\Transcription;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Modules\AI\Ai\MediaAnalysis\Contracts\MediaTranscriber;
use Modules\AI\Ai\MediaAnalysis\MediaAnalysisModelProfile;
use Modules\AI\Ai\SelfHosted\AiServers;
use Modules\AI\Ai\SelfHosted\ServiceRequestId;
use Throwable;

/**
 * Transcribes audio/video by POSTing the file to an OpenAI-style transcription server (speaches, LocalAI, a cloud
 * API): `POST {url}/v1/audio/transcriptions`, multipart `file` and `model`, `response_format=json`, of which
 * only `text` is read. The server is `ai.servers.transcription`. When no URL is configured, or on any error, it
 * returns null so the media still indexes without a transcript (M12). Returns the transcript in the
 * spoken/source language (M17). A failed call logs one warning with its request id (and nothing of the
 * media), which the server or its proxy may repeat.
 */
final class OpenAiTranscriber implements MediaTranscriber
{
    public function transcribe(string $path, string $mimeType, MediaAnalysisModelProfile $profile): ?string
    {
        $server = new AiServers()->transcription();

        if (! $server->isConfigured() || ! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $endpoint = $server->url . '/v1/audio/transcriptions';
        $request_id = new ServiceRequestId('transcription', $endpoint);

        try {
            $request = Http::timeout($server->timeout)
                ->withHeaders($request_id->header())
                ->attach('file', $contents, basename($path));

            if ($server->apiKey !== null && $server->apiKey !== '') {
                $request = $request->withToken($server->apiKey);
            }

            $fields = [['name' => 'response_format', 'contents' => 'json']];

            if ($profile->serviceModel !== '') {
                $fields[] = ['name' => 'model', 'contents' => $profile->serviceModel];
            }

            $response = $request->post($endpoint, $fields);

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

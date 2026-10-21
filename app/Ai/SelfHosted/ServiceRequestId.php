<?php

declare(strict_types=1);

namespace Modules\AI\Ai\SelfHosted;

use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The id of one call to a self-hosted AI service (`sentence-transformers-api`, `whisper-api`), sent as
 * `X-Request-Id`. The service echoes it and logs it, so a failure logged here leads to the service log
 * line of the same call. A retry of the call keeps the id, so the service log shows the attempts together.
 *
 * The log context names the service, the endpoint, the status and the id: never the text, the query, the
 * file name or a credential of the call.
 */
final readonly class ServiceRequestId
{
    public const string HEADER = 'X-Request-Id';

    /**
     * 32 lowercase hex characters.
     */
    public string $value;

    /**
     * The URL called, without credentials, query or fragment.
     */
    public string $endpoint;

    /**
     * @param  string  $service  `sentence_transformers`, `cross_encoder` or `whisper`
     * @param  string  $url  the URL of the call
     */
    public function __construct(
        public string $service,
        string $url,
    ) {
        $this->value = bin2hex(random_bytes(16));
        $this->endpoint = self::endpointOf($url);
    }

    /**
     * The status of the answer a failed call got, or null when it got none (the service was not reached).
     */
    public static function statusOf(Throwable $exception): ?int
    {
        if ($exception instanceof RequestException) {
            return $exception->response->status();
        }

        if ($exception instanceof GuzzleRequestException) {
            return $exception->getResponse()?->getStatusCode();
        }

        return null;
    }

    /**
     * `$url` without credentials, query or fragment: what a log line may say of a call.
     */
    public static function endpointOf(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return strtok($url, '?#') ?: $url;
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . $parts['host'] . $port . ($parts['path'] ?? '');
    }

    /**
     * @return array<string, string>
     */
    public function header(): array
    {
        return [self::HEADER => $this->value];
    }

    /**
     * @return array{service: string, endpoint: string, status: int|null, request_id: string}
     */
    public function context(?int $status): array
    {
        return [
            'service' => $this->service,
            'endpoint' => $this->endpoint,
            'status' => $status,
            'request_id' => $this->value,
        ];
    }

    public function logFailure(?int $status): void
    {
        Log::warning('Self-hosted AI service call failed', $this->context($status));
    }

    /**
     * Logs a call that failed on the client side (a bug, an answer it could not handle), apart from a call
     * the service failed, so it is not taken for an outage. Only the class of the exception: its message may
     * name what the call carried.
     */
    public function logClientError(Throwable $exception): void
    {
        Log::warning('Self-hosted AI service call could not be handled', [
            'service' => $this->service,
            'endpoint' => $this->endpoint,
            'request_id' => $this->value,
            'exception' => $exception::class,
        ]);
    }

    /**
     * The failure to throw for a call that failed with `$previous`: its message starts with the request id,
     * so a command that prints a shortened message still shows it.
     */
    public function exception(Throwable $previous): SelfHostedServiceException
    {
        $status = self::statusOf($previous);
        $outcome = $status === null ? 'failed' : "failed with status {$status}";

        return new SelfHostedServiceException(
            "[request_id {$this->value}] {$this->service} call to {$this->endpoint} {$outcome}: " . self::withoutUrlSecrets($previous->getMessage()),
            $this->context($status),
            $previous,
        );
    }

    /**
     * `$text` with every URL in it reduced to {@see self::endpointOf()}: the HTTP exceptions name the URL
     * they called, credentials and query included.
     */
    private static function withoutUrlSecrets(string $text): string
    {
        return preg_replace_callback(
            '~\b[a-z][a-z0-9+.-]*://[^\s"\'<>()]+~i',
            static fn (array $match): string => self::endpointOf($match[0]),
            $text,
        ) ?? $text;
    }
}

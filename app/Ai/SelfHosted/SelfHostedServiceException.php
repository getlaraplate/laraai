<?php

declare(strict_types=1);

namespace Modules\AI\Ai\SelfHosted;

use RuntimeException;
use Throwable;

/**
 * A call to a self-hosted AI service that failed: unreachable, or answered with an error status. Its message
 * starts with the request id, and {@see self::context()} gives the exception handler the same fields as the
 * client's warning, so a failed job's log line carries the id too.
 */
final class SelfHostedServiceException extends RuntimeException
{
    /**
     * @param  array{service: string, endpoint: string, status: int|null, request_id: string}  $context
     */
    public function __construct(string $message, private readonly array $context, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Read by Laravel's exception handler when it reports the exception.
     *
     * @return array{service: string, endpoint: string, status: int|null, request_id: string}
     */
    public function context(): array
    {
        return $this->context;
    }
}

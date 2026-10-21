<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Shares the embedding of a query text between concurrent and repeated requests: the first caller
 * computes it under an atomic lock, the others wait for the lock and read the stored vector.
 *
 * The key is partitioned by the signed-in user (a shared partition when there is none), so one user cannot tell
 * by timing what another searched. It also holds the identity of the active embedding profile and a hash of the normalized text, so a
 * model switch never reads a stale vector and the text itself is never stored. The cache and the lock
 * are an optimization: when the store fails or the wait times out, the vector is computed uncached.
 */
final readonly class QueryEmbeddingCache
{
    public const int TTL_SECONDS = 300;

    public const int LOCK_SECONDS = 30;

    public const int WAIT_SECONDS = 5;

    public const string SHARED_PARTITION = 'shared';

    public function __construct(
        private ?EmbeddingModelRegistry $registry = null,
        private int $waitSeconds = self::WAIT_SECONDS,
    ) {}

    /**
     * @param  Closure(): list<float>  $compute
     * @return list<float>
     */
    public function remember(string $text, Closure $compute): array
    {
        if (self::normalize($text) === null) {
            return $compute();
        }

        $key = $this->keyFor($text);

        try {
            $cached = $this->read($key);

            if ($cached !== null) {
                return $cached;
            }

            $lock = Cache::lock($key . ':lock', self::LOCK_SECONDS);
            $lock->block($this->waitSeconds);
        } catch (Throwable $exception) {
            if (! $exception instanceof LockTimeoutException) {
                report($exception);
            }

            return $compute();
        }

        try {
            $cached = $this->read($key);

            if ($cached !== null) {
                return $cached;
            }

            $vector = $compute();

            if (self::isStorable($vector)) {
                try {
                    Cache::put($key, $vector, self::TTL_SECONDS);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }

            return $vector;
        } finally {
            try {
                $lock->release();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * The cache key of a query text under the active embedding profile.
     */
    public function keyFor(string $text): string
    {
        $profile = ($this->registry ?? resolve(EmbeddingModelRegistry::class))->active();
        $normalized = self::normalize($text) ?? '';

        $partition = auth()->id();

        return 'ai:query-embedding:' . hash('sha256', json_encode([
            $partition === null ? self::SHARED_PARTITION : 'user:' . $partition,
            $profile->key,
            $profile->provider,
            $profile->serviceModel,
            $profile->dimensions,
            $profile->queryPrefix,
            $normalized,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * The text with its whitespace collapsed, or null when it is not valid UTF-8 and so has no
     * identity worth a cache entry.
     */
    private static function normalize(string $text): ?string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $text);

        return $collapsed === null ? null : mb_trim($collapsed);
    }

    /**
     * @phpstan-assert-if-true list<float> $value
     */
    private static function isStorable(mixed $value): bool
    {
        return is_array($value)
            && $value !== []
            && array_is_list($value)
            && array_all($value, static fn (mixed $item): bool => is_int($item) || is_float($item));
    }

    /**
     * A store failure counts as a miss.
     *
     * @return list<float>|null
     */
    private function read(string $key): ?array
    {
        try {
            $value = Cache::get($key);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return self::isStorable($value) ? $value : null;
    }
}

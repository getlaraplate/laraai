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
 * The key holds the identity of the active embedding profile and a hash of the normalized text, so a
 * model switch never reads a stale vector and the text itself is never stored. The cache and the lock
 * are an optimization: when the store fails or the wait times out, the vector is computed uncached.
 */
final readonly class QueryEmbeddingCache
{
    public const int TTL_SECONDS = 300;

    public const int LOCK_SECONDS = 30;

    public const int WAIT_SECONDS = 5;

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
        try {
            $key = $this->keyFor($text);
            $cached = $this->read($key);

            if ($cached !== null) {
                return $cached;
            }

            $lock = Cache::lock($key . ':lock', self::LOCK_SECONDS);
            $lock->block($this->waitSeconds);
        } catch (LockTimeoutException|Throwable) {
            return $compute();
        }

        try {
            $cached = $this->read($key);

            if ($cached !== null) {
                return $cached;
            }

            $vector = $compute();

            try {
                Cache::put($key, $vector, self::TTL_SECONDS);
            } catch (Throwable) {
                return $vector;
            }

            return $vector;
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
            }
        }
    }

    /**
     * The cache key of a query text under the active embedding profile.
     */
    public function keyFor(string $text): string
    {
        $profile = ($this->registry ?? resolve(EmbeddingModelRegistry::class))->active();
        $normalized = mb_trim((string) preg_replace('/\s+/u', ' ', $text));

        return 'ai:query-embedding:' . hash('sha256', json_encode([
            $profile->key,
            $profile->provider,
            $profile->serviceModel,
            $profile->dimensions,
            $profile->queryPrefix,
            $normalized,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<float>|null
     */
    private function read(string $key): ?array
    {
        $value = Cache::get($key);

        return is_array($value) ? array_values($value) : null;
    }
}

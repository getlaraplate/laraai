<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\QueryEmbeddingCache;

beforeEach(function (): void {
    Cache::flush();
    Sleep::fake(syncWithCarbon: true);
});

it('returns the cached vector without computing', function (): void {
    $cache = new QueryEmbeddingCache;
    $calls = 0;
    $compute = function () use (&$calls): array {
        $calls++;

        return [0.1, 0.2];
    };

    $first = $cache->remember('ordini aperti', $compute);
    $second = $cache->remember('ordini aperti', $compute);

    expect($first)->toBe([0.1, 0.2])
        ->and($second)->toBe([0.1, 0.2])
        ->and($calls)->toBe(1);
});

it('shares the key across spacing and not across case', function (): void {
    $cache = new QueryEmbeddingCache;
    $calls = 0;
    $compute = function () use (&$calls): array {
        $calls++;

        return [1.0];
    };

    $cache->remember('ordini  aperti ', $compute);
    $cache->remember('ordini aperti', $compute);

    expect($calls)->toBe(1);

    $cache->remember('Ordini aperti', $compute);

    expect($calls)->toBe(2);
});

it('computes once for a caller that waits on the lock', function (): void {
    $cache = new QueryEmbeddingCache;
    $key = $cache->keyFor('attesa');
    $lock = Cache::lock($key . ':lock', 30);
    expect($lock->get())->toBeTrue();

    Sleep::whenFakingSleep(function () use ($key, $lock): void {
        Cache::put($key, [0.5, 0.6], 300);
        $lock->release();
    });

    $result = $cache->remember('attesa', function (): array {
        throw new RuntimeException('must not compute');
    });

    expect($result)->toBe([0.5, 0.6]);
});

it('computes without the cache when the wait times out', function (): void {
    $cache = new QueryEmbeddingCache(null, 1);
    $lock = Cache::lock($cache->keyFor('bloccato') . ':lock', 30);
    expect($lock->get())->toBeTrue();

    $result = $cache->remember('bloccato', static fn (): array => [0.9]);

    expect($result)->toBe([0.9])
        ->and(Cache::get($cache->keyFor('bloccato')))->toBeNull();
});

it('keys by the active embedding profile', function (): void {
    $keys = array_keys((array) config('ai.features.embeddings.models'));
    expect(count($keys))->toBeGreaterThanOrEqual(2);

    $cache = new QueryEmbeddingCache;
    $calls = 0;
    $compute = function () use (&$calls): array {
        $calls++;

        return [1.0];
    };

    config(['core.search.vector.model' => $keys[0]]);
    $cache->remember('x', $compute);
    $cache->remember('x', $compute);
    expect($calls)->toBe(1);

    config(['core.search.vector.model' => $keys[1]]);
    $cache->remember('x', $compute);

    expect($calls)->toBe(2);
});

it('respects a withActive override in the key', function (): void {
    $keys = array_keys((array) config('ai.features.embeddings.models'));
    $registry = app(EmbeddingModelRegistry::class);
    $cache = new QueryEmbeddingCache($registry);

    $other = $registry->withActive((string) $keys[1], fn (): string => $cache->keyFor('x'));

    expect($other)->not->toBe($cache->keyFor('x'));
});

it('stores only the vector', function (): void {
    $cache = new QueryEmbeddingCache;
    $cache->remember('testo riservato', static fn (): array => [0.3]);

    $store = Cache::getStore();
    $reflection = new ReflectionProperty($store, 'storage');
    $storage = $reflection->getValue($store);

    expect($storage)->not->toBeEmpty()
        ->and(json_encode($storage))->not->toContain('riservato')
        ->and(Cache::get($cache->keyFor('testo riservato')))->toBe([0.3]);
});

it('computes when the cache store throws', function (): void {
    Cache::shouldReceive('get')->andThrow(new RuntimeException('down'));
    Cache::shouldReceive('lock')->andThrow(new RuntimeException('down'));

    $result = (new QueryEmbeddingCache)->remember('x', static fn (): array => [0.7]);

    expect($result)->toBe([0.7]);
});

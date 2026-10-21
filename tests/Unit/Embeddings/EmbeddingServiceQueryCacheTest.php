<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Modules\AI\Ai\Embeddings\QueryEmbeddingCache;
use Modules\AI\Services\EmbeddingService;
use Modules\AI\Tests\Stubs\Embeddings\RecordingEmbeddingsProvider;

beforeEach(function (): void {
    Cache::flush();
});

it('embeds a query once for repeated calls', function (): void {
    $cache = new QueryEmbeddingCache;
    $key = $cache->keyFor('x');

    // The configured provider would call the embedding service over HTTP: a stored vector proves
    // the second and later calls are served by the cache, without resolving the provider.
    $cache->remember('x', static fn (): array => [0.4, 0.5]);

    $service = new EmbeddingService(queryCache: $cache);

    expect($service->embedText('x'))->toBe([0.4, 0.5])
        ->and($service->embedText('x'))->toBe([0.4, 0.5])
        ->and(Cache::has($key))->toBeTrue();
});

it('resolves the cache from the container when none is injected', function (): void {
    app(QueryEmbeddingCache::class)->remember('y', static fn (): array => [0.8]);

    expect((new EmbeddingService)->embedText('y'))->toBe([0.8]);
});

it('does not cache when a provider factory is injected', function (): void {
    $provider = new RecordingEmbeddingsProvider;
    $service = new EmbeddingService(fn () => $provider);

    $service->embedText('x');
    $service->embedText('x');

    expect($provider->textsSent)->toBe(['x', 'x']);
});

it('does not cache document embeddings', function (): void {
    $provider = new RecordingEmbeddingsProvider;
    $service = new EmbeddingService(fn () => $provider);

    $service->embedDocument('body');
    $service->embedDocument('body');

    expect($provider->textsSent)->toHaveCount(2);
});

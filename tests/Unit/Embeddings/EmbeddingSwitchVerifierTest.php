<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\QueryEmbeddingCache;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchCorpus;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchVerifier;
use Modules\AI\Ai\Embeddings\Switching\SearchIndexInspector;
use Modules\AI\Contracts\IEmbeddableModels;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Tests\Stubs\Embeddings\RecordingEmbeddingsProvider;
use Modules\Core\Search\Contracts\ITextEmbedder;

it('reaches the target provider on every run, whatever the query cache holds', function (): void {
    Cache::flush();
    config(['core.search.vector.enabled' => true]);

    $registry = app(EmbeddingModelRegistry::class);
    $configured = $registry->get($registry->keys()[0]);
    $target = new EmbeddingModelProfile(
        $configured->key,
        $configured->provider,
        $configured->serviceModel,
        3,
        $configured->queryPrefix,
        $configured->passagePrefix,
        $configured->similarity,
        $configured->normalize,
    );

    $provider = new RecordingEmbeddingsProvider;
    $service = Mockery::mock(IEmbeddingService::class, static function (MockInterface $mock) use ($provider): void {
        $mock->shouldReceive('getEmbeddingsProvider')->andReturn($provider);
        $mock->shouldReceive('embedText')->andReturnUsing(
            static fn (string $text): array => resolve(QueryEmbeddingCache::class)->remember($text, static fn (): array => $provider->embedText($text)),
        );
    });
    app()->instance(IEmbeddingService::class, $service);
    app()->instance(ITextEmbedder::class, Mockery::mock(ITextEmbedder::class, static function (MockInterface $mock) use ($service): void {
        $mock->shouldReceive('embed')->andReturnUsing(static fn (string $text): array => $service->embedText($text));
    }));

    $corpus = new EmbeddingSwitchCorpus(Mockery::mock(IEmbeddableModels::class, static function (MockInterface $mock): void {
        $mock->shouldReceive('all')->andReturn([]);
    }));
    $verifier = new EmbeddingSwitchVerifier($corpus, new SearchIndexInspector, $registry);

    $verifier->verify($target);
    $verifier->verify($target);

    expect($provider->textsSent)->toBe(['test', 'test']);
});

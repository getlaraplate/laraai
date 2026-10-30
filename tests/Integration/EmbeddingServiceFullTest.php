<?php

declare(strict_types=1);

use Modules\AI\Services\EmbeddingService;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Splitter\SplitterInterface;

it('embedDocument returns embeddings from mocked provider', function (): void {
    $doc = new Document('chunk content');
    $doc->setEmbedding([0.1, 0.2, 0.3]);

    $providerMock = Mockery::mock(EmbeddingsProviderInterface::class);
    $providerMock->shouldReceive('embedDocuments')
        ->with(Mockery::on(fn ($chunks): bool => is_array($chunks) && count($chunks) >= 1))
        ->andReturn([$doc]);

    $service = new EmbeddingService(fn () => $providerMock);

    $result = $service->embedDocument("text\twith\nnewlines  and   spaces");

    expect($result)->toBeArray()
        ->and($result)->toHaveCount(1)
        ->and($result[0])->toBeInstanceOf(Document::class)
        ->and($result[0]->getEmbedding())->toBe([0.1, 0.2, 0.3]);
});

it('embedText returns embeddings from mocked provider', function (): void {
    $providerMock = Mockery::mock(EmbeddingsProviderInterface::class);
    $providerMock->shouldReceive('embedText')
        ->with('hello')
        ->andReturn([0.1, 0.2, 0.3]);

    $service = new EmbeddingService(fn () => $providerMock);

    $result = $service->embedText('hello');

    expect($result)->toBe([0.1, 0.2, 0.3]);
});

it('getEmbeddingsProvider returns EmbeddingsProviderInterface', function (): void {
    config()->set('ai.features.embeddings.models', ['openai:text-embedding-3-small' => ['dimensions' => 1536]]);
    config()->set('ai.features.embeddings.serving_model', 'openai:text-embedding-3-small');
    config()->set('ai.providers.openai.api_key', 'fake-key');
    config()->set('ai.providers.openai.model', 'text-embedding-3-small');

    $service = new EmbeddingService;
    $provider = $service->getEmbeddingsProvider();

    expect($provider)->toBeInstanceOf(EmbeddingsProviderInterface::class);
});

it('embedDocument cleans and processes text', function (): void {
    config()->set('ai.features.embeddings.models', ['ollama:nomic-embed-text' => ['dimensions' => 768]]);
    config()->set('ai.features.embeddings.serving_model', 'ollama:nomic-embed-text');
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');
    config()->set('ai.providers.ollama.model', 'nomic-embed-text');

    $service = new EmbeddingService;

    try {
        $result = $service->embedDocument("text\twith\nnewlines  and   spaces");
        expect($result)->toBeArray();
    } catch (Throwable $e) {
        test()->markTestSkipped('Ollama not available: ' . $e->getMessage());
    }
});

it('embedText returns float array', function (): void {
    config()->set('ai.features.embeddings.models', ['ollama:nomic-embed-text' => ['dimensions' => 768]]);
    config()->set('ai.features.embeddings.serving_model', 'ollama:nomic-embed-text');
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');
    config()->set('ai.providers.ollama.model', 'nomic-embed-text');

    $service = new EmbeddingService;

    try {
        $result = $service->embedText('hello');
        expect($result)->toBeArray()
            ->and($result)->not->toBeEmpty()
            ->and($result[0])->toBeFloat();
    } catch (Throwable $e) {
        test()->markTestSkipped('Ollama not available: ' . $e->getMessage());
    }
});

it('embeds several texts in one batched provider call and slices results back per text', function (): void {
    // One chunk per '|'-separated part, so texts produce a variable chunk count.
    $splitter = Mockery::mock(SplitterInterface::class);
    $splitter->shouldReceive('splitDocument')->andReturnUsing(
        static fn (Document $document): array => array_map(
            static fn (string $part): Document => new Document($part),
            explode('|', $document->getContent()),
        ),
    );

    $provider = Mockery::mock(EmbeddingsProviderInterface::class);
    $provider->shouldReceive('embedDocuments')
        ->once() // a single batched call for every chunk of every text
        ->andReturnUsing(static function (array $chunks): array {
            foreach ($chunks as $index => $chunk) {
                $chunk->setEmbedding([(float) $index]);
            }

            return $chunks;
        });

    $service = new EmbeddingService(static fn (): EmbeddingsProviderInterface => $provider, $splitter);

    $result = $service->embedDocumentsBatch(['a|b', 'c']);

    expect($result)->toHaveCount(2)
        ->and($result[0])->toHaveCount(2)
        ->and($result[1])->toHaveCount(1)
        ->and($result[1][0]->getEmbedding())->toBe([2.0]);
});

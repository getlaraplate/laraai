<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Closure;
use Modules\AI\Ai\Embeddings\EmbeddingsProviderFactory;
use Modules\AI\Ai\Embeddings\PrefixingEmbeddingsProvider;
use Modules\AI\Ai\Embeddings\QueryEmbeddingCache;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Services\Documentation\Chunking\SplitterFactory;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Splitter\SplitterInterface;
use Override;

final readonly class EmbeddingService implements IEmbeddingService
{
    /**
     * @param  Closure(): EmbeddingsProviderInterface|null  $providerFactory  Optional factory for testing
     */
    public function __construct(
        private ?Closure $providerFactory = null,
        private ?SplitterInterface $splitter = null,
        private ?QueryEmbeddingCache $queryCache = null,
    ) {}

    /**
     * Generate embeddings for a document (with splitting for long texts).
     *
     * @return Document[]
     */
    #[Override]
    public function embedDocument(string $data): array
    {
        return $this->getProvider()->embedDocuments($this->chunksFor($data));
    }

    /**
     * @param  list<string>  $texts
     * @return list<Document[]>
     */
    #[Override]
    public function embedDocumentsBatch(array $texts): array
    {
        $all_chunks = [];
        $ranges = [];

        foreach ($texts as $text) {
            $chunks = $this->chunksFor($text);
            $ranges[] = [count($all_chunks), count($chunks)];

            foreach ($chunks as $chunk) {
                $all_chunks[] = $chunk;
            }
        }

        if ($all_chunks === []) {
            return array_fill(0, count($texts), []);
        }

        // One batched (adaptive) provider call for every chunk of every text;
        // the provider preserves order, so each input's chunks are sliced back.
        $embedded = $this->getProvider()->embedDocuments($all_chunks);

        return array_map(
            static fn (array $range): array => array_slice($embedded, $range[0], $range[1]),
            $ranges,
        );
    }

    /**
     * Generate embedding for a simple text string. With the configured provider the vector is shared
     * through {@see QueryEmbeddingCache}; an injected provider factory is not identified by the active
     * profile, so it is never cached.
     *
     * @return list<float>
     */
    #[Override]
    public function embedText(string $text): array
    {
        if ($this->providerFactory !== null) {
            return array_values($this->getProvider()->embedText($text));
        }

        return ($this->queryCache ?? resolve(QueryEmbeddingCache::class))->remember(
            $text,
            fn (): array => array_values($this->getProvider()->embedText($text)),
        );
    }

    /**
     * Get the configured embedding provider for use by other services (e.g. RAG).
     */
    #[Override]
    public function getEmbeddingsProvider(): EmbeddingsProviderInterface
    {
        return $this->getProvider();
    }

    /**
     * Clean and split a text into the chunk documents sent to the provider, which adds the
     * passage prefix of its model to each of them ({@see PrefixingEmbeddingsProvider}).
     *
     * @return list<Document>
     */
    private function chunksFor(string $data): array
    {
        $content = preg_replace("/\n|\t/", ' ', $data);
        $content = preg_replace("/\s+/", ' ', (string) $content);
        $content = mb_trim((string) $content);

        $document = (new Document($content))
            ->setSourceType('inline')
            ->setSourceName('document');

        $splitter = $this->splitter ?? SplitterFactory::make();

        return $splitter->splitDocument($document);
    }

    private function getProvider(): EmbeddingsProviderInterface
    {
        $factory = $this->providerFactory ?? EmbeddingsProviderFactory::make(...);

        return $factory();
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Documentation;

use BadMethodCallException;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Contracts\IEmbeddingService;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

final class StubDocumentationEmbeddingService implements IEmbeddingService
{
    /**
     * @return array<int, mixed>
     */
    public function embedDocument(string $data): array
    {
        return [];
    }

    /**
     * @param  list<string>  $texts
     * @param  array<int, string|null>  $headers
     * @return list<array<int, mixed>>
     */
    public function embedDocumentsBatch(array $texts, array $headers = []): array
    {
        // Documentation retrieval tests key on embedText(); the document path is
        // unused here, so mirror embedDocument() with one empty result per text.
        return array_map(static fn (): array => [], $texts);
    }

    /**
     * @return list<float>
     */
    public function embedText(string $text): array
    {
        // Keyed on the semantic query so callers can author fixtures with bare
        // queries: strip the active model's query/passage instruction prefix
        // (e.g. e5's "query: ") that the retriever now prepends, so the crc32
        // key matches regardless of the prefix.
        $profile = app(EmbeddingModelRegistry::class)->active();

        foreach ([$profile->queryPrefix, $profile->passagePrefix] as $prefix) {
            if ($prefix !== '' && str_starts_with($text, $prefix)) {
                $text = mb_substr($text, mb_strlen($prefix));

                break;
            }
        }

        return [(float) crc32($text)];
    }

    public function getEmbeddingsProvider(): EmbeddingsProviderInterface
    {
        throw new BadMethodCallException('Stub embedding service has no provider.');
    }
}

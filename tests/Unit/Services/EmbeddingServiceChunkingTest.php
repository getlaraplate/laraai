<?php

declare(strict_types=1);

use Modules\AI\Services\Documentation\Chunking\MarkdownAwareSplitter;
use Modules\AI\Services\EmbeddingService;
use Modules\AI\Tests\Stubs\Embeddings\RecordingEmbeddingsProvider;

/**
 * An embedding service over a recording provider and the structure-aware splitter with a small word limit,
 * so the chunk texts handed to the provider are visible and the boundaries are easy to reason about.
 */
function chunkingService(RecordingEmbeddingsProvider $provider, int $maxWords = 6): EmbeddingService
{
    return new EmbeddingService(static fn (): RecordingEmbeddingsProvider => $provider, new MarkdownAwareSplitter($maxWords));
}

it('without a header chunks are unchanged apart from structure', function (): void {
    $provider = new RecordingEmbeddingsProvider;

    chunkingService($provider)->embedDocumentsBatch([
        "  text\twith\nnewlines  and   spaces ",
        "One two three. Four five six.\tSeven eight nine ten. Eleven",
    ]);

    // The same chunks the service produced when it flattened the text before splitting.
    expect($provider->textsSent)->toBe([
        'text with newlines and spaces',
        'One two three. Four five six.',
        'Seven eight nine ten. Eleven',
    ]);
});

it('every chunk of a long text starts with the header', function (): void {
    $provider = new RecordingEmbeddingsProvider;

    $result = chunkingService($provider)->embedDocumentsBatch(
        ['One two three. Four five six. Seven eight nine ten. Eleven', 'A text without header'],
        [0 => 'news | Opening day | 2026-06-01'],
    );

    expect($result[0])->toHaveCount(2)
        ->and($result[1])->toHaveCount(1)
        ->and($provider->textsSent)->toBe([
            "news | Opening day | 2026-06-01\nOne two three. Four five six.",
            "news | Opening day | 2026-06-01\nSeven eight nine ten. Eleven",
            'A text without header',
        ]);
});

it('paragraph breaks survive until the split', function (): void {
    $provider = new RecordingEmbeddingsProvider;

    chunkingService($provider)->embedDocument("Opening hours\r\n\r\nMonday to Friday nine to five");

    // Flattened first, this was one 8-word paragraph cut at the sixth word.
    expect($provider->textsSent)->toBe([
        'Opening hours',
        'Monday to Friday nine to five',
    ]);
});

it('a blank text yields no chunk even with a header', function (): void {
    $provider = new RecordingEmbeddingsProvider;

    $result = chunkingService($provider)->embedDocumentsBatch([" \n\t "], [0 => 'news | Empty']);

    expect($result)->toBe([[]])
        ->and($provider->textsSent)->toBe([]);
});

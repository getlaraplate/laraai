<?php

declare(strict_types=1);

use Modules\AI\Ai\Agents\DocumentationAgent;
use Modules\AI\Ai\Embeddings\EmbeddingsProviderFactory;
use Modules\AI\Ai\Embeddings\PrefixingEmbeddingsProvider;
use Modules\AI\Tests\Stubs\Embeddings\RecordingEmbeddingsProvider;
use NeuronAI\RAG\Document;

const E5_MODEL = 'openai_compatible:intfloat/multilingual-e5-small';

it('embeds a question with the query prefix and a chunk with the passage prefix', function (): void {
    $inner = new RecordingEmbeddingsProvider;
    $provider = new PrefixingEmbeddingsProvider($inner, 'query: ', 'passage: ');

    $provider->embedText('how do I export?');
    $provider->embedDocuments([new Document('first'), new Document('second')]);
    $provider->embedDocument(new Document('third'));

    expect($inner->textsSent)->toBe(['query: how do I export?', 'passage: first', 'passage: second', 'passage: third']);
});

it('copies the vector back and leaves the content the model reads untouched', function (): void {
    $document = (new Document('Open the list and use the export action.'))->setSourceName('exports.md');

    $embedded = new PrefixingEmbeddingsProvider(new RecordingEmbeddingsProvider, 'query: ', 'passage: ')->embedDocuments([$document]);

    expect($embedded)->toHaveCount(1)
        ->and($embedded[0])->toBe($document)
        ->and($document->getContent())->toBe('Open the list and use the export action.')
        ->and($document->getEmbedding())->toBe([0.1, 0.2, 0.3])
        ->and($document->getSourceName())->toBe('exports.md');
});

it('keeps the order of the documents it embeds', function (): void {
    $documents = [new Document('a'), new Document('b'), new Document('c')];

    $inner = new RecordingEmbeddingsProvider;
    new PrefixingEmbeddingsProvider($inner, '', 'passage: ')->embedDocuments($documents);

    expect(array_map(static fn (Document $document): string => $document->getContent(), $documents))->toBe(['a', 'b', 'c'])
        ->and($inner->textsSent)->toBe(['passage: a', 'passage: b', 'passage: c']);
});

it('is the provider of a profile that has prefixes, for the factory and for the documentation agent', function (): void {
    config()->set('ai.servers.embeddings.url', 'http://localhost:8000');
    config()->set('core.search.vector.model', E5_MODEL);

    $agent = DocumentationAgent::make();
    $embeddings = new ReflectionMethod($agent, 'embeddings');

    expect(EmbeddingsProviderFactory::make())->toBeInstanceOf(PrefixingEmbeddingsProvider::class)
        ->and($embeddings->invoke($agent))->toBeInstanceOf(PrefixingEmbeddingsProvider::class);
});

it('is not added for a profile without prefixes or for a provider that is not the profile\'s', function (): void {
    config()->set('ai.servers.embeddings.url', 'http://localhost:8000');
    config()->set('core.search.vector.model', 'openai_compatible:all-MiniLM-L6-v2');
    expect(EmbeddingsProviderFactory::make())->not->toBeInstanceOf(PrefixingEmbeddingsProvider::class);

    config()->set('core.search.vector.model', E5_MODEL);
    config()->set('ai.providers.mistral.api_key', 'not-a-key');
    expect(EmbeddingsProviderFactory::make('mistral'))->not->toBeInstanceOf(PrefixingEmbeddingsProvider::class);
});

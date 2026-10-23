<?php

declare(strict_types=1);

use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\AI\Ai\SelfHosted\AiServer;
use Modules\AI\Ai\SelfHosted\AiServers;

/**
 * A profile of the given server name, as the registry builds one.
 */
function aiServersProfile(?string $server = null): EmbeddingModelProfile
{
    return new EmbeddingModelProfile(
        key: 'openai_compatible:intfloat/multilingual-e5-small',
        provider: 'openai_compatible',
        serviceModel: 'intfloat/multilingual-e5-small',
        dimensions: 384,
        queryPrefix: 'query: ',
        passagePrefix: 'passage: ',
        similarity: 'cosine',
        normalize: true,
        server: $server,
    );
}

beforeEach(function (): void {
    config()->set('ai.servers', [
        'embeddings' => ['url' => 'http://embeddings.test:8080/', 'api_key' => 'default-key', 'timeout' => 30, 'batch_size' => 32],
        'embeddings_minilm' => ['url' => null, 'api_key' => null],
        'embeddings_other' => ['url' => 'http://other.test:8081', 'api_key' => null, 'timeout' => 12, 'batch_size' => 8],
        'rerank' => ['url' => '', 'api_key' => null, 'dialect' => 'tei', 'model' => null],
        'transcription' => ['url' => '', 'api_key' => null, 'timeout' => 120],
    ]);
});

it('resolves the default embeddings server and trims a trailing slash from its url', function (): void {
    $server = new AiServers()->named('embeddings');

    expect($server)->toEqual(new AiServer('embeddings', 'http://embeddings.test:8080', 'default-key', 30, 32, null, null))
        ->and($server->isConfigured())->toBeTrue();
});

it('falls back to the embeddings server for url, key, timeout and batch size when a named embeddings server has no url', function (): void {
    $server = new AiServers()->named('embeddings_minilm');

    expect($server->name)->toBe('embeddings_minilm')
        ->and($server->url)->toBe('http://embeddings.test:8080')
        ->and($server->apiKey)->toBe('default-key')
        ->and($server->timeout)->toBe(30)
        ->and($server->batchSize)->toBe(32);
});

it('uses the own url of a named server that has one and does not borrow the key of the default', function (): void {
    $server = new AiServers()->named('embeddings_other');

    expect($server->url)->toBe('http://other.test:8081')
        ->and($server->apiKey)->toBeNull()
        ->and($server->timeout)->toBe(12)
        ->and($server->batchSize)->toBe(8);
});

it('has no fallback for rerank and transcription', function (): void {
    $servers = new AiServers();

    expect($servers->rerank()->isConfigured())->toBeFalse()
        ->and($servers->rerank()->url)->toBe('')
        ->and($servers->rerank()->dialect)->toBe('tei')
        ->and($servers->transcription()->isConfigured())->toBeFalse()
        ->and($servers->transcription()->timeout)->toBe(120);
});

it('resolves the server a profile names and the default when it names none', function (): void {
    $servers = new AiServers();

    expect($servers->forProfile(aiServersProfile('embeddings_other'))->url)->toBe('http://other.test:8081')
        ->and($servers->forProfile(aiServersProfile())->name)->toBe('embeddings');
});

it('is not configured when neither the named server nor the default has a url', function (): void {
    config()->set('ai.servers.embeddings.url', '');

    expect(new AiServers()->forProfile(aiServersProfile('embeddings_minilm'))->isConfigured())->toBeFalse();
});

it('gives a named server with its own url the timeout and batch size of the default when it sets none', function (): void {
    config()->set('ai.servers.embeddings.timeout', 120);
    config()->set('ai.servers.embeddings.batch_size', 16);
    config()->set('ai.servers.embeddings_minilm', ['url' => 'http://minilm.test:8082', 'api_key' => 'own-key']);

    $server = new AiServers()->named('embeddings_minilm');

    expect($server->url)->toBe('http://minilm.test:8082')
        ->and($server->apiKey)->toBe('own-key')
        ->and($server->timeout)->toBe(120)
        ->and($server->batchSize)->toBe(16);
});

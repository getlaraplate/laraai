<?php

declare(strict_types=1);

use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\EmbeddingsProviderFactory;

test('active resolves the configured multilingual-e5-small profile', function (): void {
    $profile = app(EmbeddingModelRegistry::class)->active();

    expect($profile->key)->toBe('openai_compatible:intfloat/multilingual-e5-small')
        ->and($profile->queryPrefix)->toBe('query: ')
        ->and($profile->passagePrefix)->toBe('passage: ')
        ->and($profile->dimensions)->toBe(384);
});

test('get throws for an unknown model key', function (): void {
    expect(fn () => app(EmbeddingModelRegistry::class)->get('nope'))
        ->toThrow(InvalidArgumentException::class);
});

it('takes dimensions and similarity from the profile, not from Core', function (): void {
    config([
        'core.search.vector.dimensions' => 999,
        'core.search.vector.similarity' => 'dot_product',
    ]);

    $profile = app(EmbeddingModelRegistry::class)->active();

    expect($profile->dimensions)->toBe(384)
        ->and($profile->similarity)->toBe('cosine');
});

it('rejects a profile with no dimensions', function (mixed $dimensions): void {
    config(['ai.features.embeddings.models.openai_compatible:broken' => ['dimensions' => $dimensions]]);

    expect(fn () => app(EmbeddingModelRegistry::class)->get('openai_compatible:broken'))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'missing' => [null],
    'zero' => [0],
]);

it('lists the configured profile keys', function (): void {
    expect(app(EmbeddingModelRegistry::class)->keys())->toBe([
        'openai_compatible:intfloat/multilingual-e5-small',
        'openai_compatible:all-MiniLM-L6-v2',
    ]);
});

it('derives provider and service model from the key, splitting on the first colon', function (): void {
    config(['ai.features.embeddings.models.ollama:nomic-embed-text:latest' => ['dimensions' => 768]]);

    $registry = app(EmbeddingModelRegistry::class);
    $first = $registry->get('openai_compatible:intfloat/multilingual-e5-small');
    $second = $registry->get('ollama:nomic-embed-text:latest');

    expect($first->provider)->toBe('openai_compatible')
        ->and($first->serviceModel)->toBe('intfloat/multilingual-e5-small')
        ->and($second->provider)->toBe('ollama')
        ->and($second->serviceModel)->toBe('nomic-embed-text:latest')
        ->and($second->dimensions)->toBe(768);
});

it('returns the overridden profile while a callback runs and restores the previous one after, also when it throws', function (): void {
    $registry = app(EmbeddingModelRegistry::class);
    $other = 'openai_compatible:all-MiniLM-L6-v2';

    $inside = $registry->withActive($other, fn (): string => $registry->active()->key);

    expect($inside)->toBe($other)
        ->and($registry->active()->key)->toBe('openai_compatible:intfloat/multilingual-e5-small');

    expect(fn () => $registry->withActive($other, function (): never {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    expect($registry->active()->key)->toBe('openai_compatible:intfloat/multilingual-e5-small');
});

it('resolves the provider and service model of the overridden profile in the factory', function (): void {
    config()->set('ai.servers.embeddings.url', 'http://localhost:8000');
    $registry = app(EmbeddingModelRegistry::class);

    $sent = $registry->withActive('openai_compatible:all-MiniLM-L6-v2', function (): ?string {
        $client = new Modules\AI\Tests\Stubs\ScriptedHttpClient(fn (NeuronAI\HttpClient\HttpRequest $request): NeuronAI\HttpClient\HttpResponse => new NeuronAI\HttpClient\HttpResponse(200, json_encode(['model' => 'all-MiniLM-L6-v2', 'data' => [['index' => 0, 'embedding' => [0.1, 0.2]]]])));

        $provider = EmbeddingsProviderFactory::make();
        $inner = $provider instanceof Modules\AI\Ai\Embeddings\PrefixingEmbeddingsProvider ? (fn (): NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface => $this->inner)->call($provider) : $provider;
        $inner->setHttpClient($client);
        $provider->embedText('hello');

        return $client->requests[0]->body['model'] ?? null;
    });

    expect($sent)->toBe('all-MiniLM-L6-v2');
});

it('reads the server a profile names', function (): void {
    config()->set('ai.features.embeddings.models.openai_compatible:other', ['dimensions' => 384, 'server' => 'embeddings_other']);

    $registry = app(EmbeddingModelRegistry::class);

    expect($registry->get('openai_compatible:other')->server)->toBe('embeddings_other')
        ->and($registry->get('openai_compatible:intfloat/multilingual-e5-small')->server)->toBeNull()
        ->and($registry->get('openai_compatible:all-MiniLM-L6-v2')->server)->toBe('embeddings_minilm');
});

it('does not offer a profile whose server has no url and no default', function (): void {
    config()->set('ai.servers.embeddings.url', '');
    config()->set('ai.servers.embeddings_minilm.url', '');

    expect(app(EmbeddingModelRegistry::class)->configuredKeys())->not->toContain('openai_compatible:all-MiniLM-L6-v2')
        ->and(app(EmbeddingModelRegistry::class)->configuredKeys())->not->toContain('openai_compatible:intfloat/multilingual-e5-small');
});

it('offers a profile whose named server falls back to the default one, and one whose server has its own url', function (): void {
    config()->set('ai.servers.embeddings.url', 'http://default.test:8000');
    config()->set('ai.servers.embeddings_minilm.url', '');

    expect(app(EmbeddingModelRegistry::class)->configuredKeys())->toContain('openai_compatible:all-MiniLM-L6-v2', 'openai_compatible:intfloat/multilingual-e5-small');

    config()->set('ai.servers.embeddings.url', '');
    config()->set('ai.servers.embeddings_minilm.url', 'http://minilm.test:8081');

    expect(app(EmbeddingModelRegistry::class)->configuredKeys())->toBe(['openai_compatible:all-MiniLM-L6-v2']);
});

it('rejects a malformed key with an empty provider or service model', function (string $key): void {
    config(['ai.features.embeddings.models' => [$key => ['dimensions' => 384]]]);

    expect(fn () => app(EmbeddingModelRegistry::class)->get($key))
        ->toThrow(InvalidArgumentException::class);
})->with(['no colon' => ['foo'], 'empty provider' => [':x'], 'empty model' => ['x:']]);

it('resolves a dotted profile key when the whole models array is replaced', function (): void {
    config(['ai.features.embeddings.models' => ['ollama:nomic-embed-text:v1.5' => ['dimensions' => 768]]]);

    $profile = app(EmbeddingModelRegistry::class)->get('ollama:nomic-embed-text:v1.5');

    expect($profile->provider)->toBe('ollama')
        ->and($profile->serviceModel)->toBe('nomic-embed-text:v1.5')
        ->and($profile->dimensions)->toBe(768);
});

it('serves the model named by ai.features.embeddings.serving_model', function (): void {
    config()->set('ai.features.embeddings.serving_model', 'voyageai:voyage-3-lite');
    config()->set('ai.servers.embeddings.url', 'http://localhost:8000');
    config()->set('ai.features.embeddings.models', [
        'openai_compatible:intfloat/multilingual-e5-small' => ['dimensions' => 384],
        'voyageai:voyage-3-lite' => ['dimensions' => 512],
    ]);

    $registry = app(EmbeddingModelRegistry::class);

    expect($registry->activeKey())->toBe('voyageai:voyage-3-lite')
        ->and($registry->active()->dimensions)->toBe(512);
});

it('defaults the active profile to the first configured one when no model is being served', function (?string $model): void {
    config()->set('ai.features.embeddings.serving_model', $model);
    config()->set('ai.servers.embeddings.url', '');
    config()->set('ai.providers.voyageai.api_key', 'k');
    config()->set('ai.features.embeddings.models', [
        'openai_compatible:intfloat/multilingual-e5-small' => ['dimensions' => 384],
        'voyageai:voyage-3-lite' => ['dimensions' => 512],
    ]);

    expect(app(EmbeddingModelRegistry::class)->active()->key)->toBe('voyageai:voyage-3-lite');
})->with(['null' => [null], 'empty' => ['']]);

it('ignores the Core search.vector.model key', function (): void {
    config()->set('core.search.vector.model', 'openai_compatible:all-MiniLM-L6-v2');
    config()->set('ai.features.embeddings.serving_model', null);
    config()->set('ai.servers.embeddings.url', 'http://localhost:8000');

    expect(app(EmbeddingModelRegistry::class)->activeKey())->toBe('openai_compatible:intfloat/multilingual-e5-small');
});

it('knows voyageai and openai_compatible configuration', function (): void {
    $providers = new Modules\AI\Ai\Providers\Models\ProviderConfiguration;

    config()->set('ai.providers.voyageai.api_key', '');
    config()->set('ai.servers.embeddings.url', '');
    config()->set('ai.servers.transcription.url', '');
    expect($providers->isConfigured('voyageai'))->toBeFalse()
        ->and($providers->isConfigured('openai_compatible'))->toBeFalse();

    config()->set('ai.providers.voyageai.api_key', 'k');
    config()->set('ai.servers.embeddings.url', 'http://x');
    expect($providers->isConfigured('voyageai'))->toBeTrue()
        ->and($providers->isConfigured('openai_compatible'))->toBeFalse();

    config()->set('ai.servers.embeddings.url', '');
    config()->set('ai.servers.transcription.url', 'http://speaches.test');
    expect($providers->isConfigured('openai_compatible'))->toBeTrue();
});

<?php

declare(strict_types=1);

use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\EmbeddingsProviderFactory;
use Modules\AI\Ai\Embeddings\OpenAiCompatibleEmbeddingsProvider;
use Modules\AI\Ai\Embeddings\PrefixingEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\MistralEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\OllamaEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\VoyageEmbeddingsProvider;

it('creates an OpenAI embeddings provider', function (): void {
    config()->set('ai.providers.openai.api_key', 'test-key');
    config()->set('ai.providers.openai.model', 'text-embedding-3-small');

    $provider = EmbeddingsProviderFactory::make('openai');

    expect($provider)->toBeInstanceOf(OpenAIEmbeddingsProvider::class);
});

it('creates an Ollama embeddings provider', function (): void {
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');
    config()->set('ai.providers.ollama.model', 'nomic-embed-text');

    $provider = EmbeddingsProviderFactory::make('ollama');

    expect($provider)->toBeInstanceOf(OllamaEmbeddingsProvider::class);
});

it('creates a Mistral embeddings provider', function (): void {
    config()->set('ai.providers.mistral.api_key', 'test-key');
    config()->set('ai.providers.mistral.model', 'mistral-embed');

    $provider = EmbeddingsProviderFactory::make('mistral');

    expect($provider)->toBeInstanceOf(MistralEmbeddingsProvider::class);
});

it('creates a VoyageAI embeddings provider', function (): void {
    config()->set('ai.providers.voyageai.api_key', 'test-key');
    config()->set('ai.providers.voyageai.model', 'voyage-3-lite');

    $provider = EmbeddingsProviderFactory::make('voyageai');

    expect($provider)->toBeInstanceOf(VoyageEmbeddingsProvider::class);
});

/**
 * The provider under the prefixing decorator that the factory puts on the provider of the active profile.
 */
function factoryProviderBehindPrefixes(EmbeddingsProviderInterface $provider): EmbeddingsProviderInterface
{
    return $provider instanceof PrefixingEmbeddingsProvider ? (fn (): EmbeddingsProviderInterface => $this->inner)->call($provider) : $provider;
}

it('builds the OpenAI-compatible provider of the profile server with the profile model', function (): void {
    config()->set('ai.servers.embeddings.url', 'http://localhost:8000');
    config()->set('ai.servers.embeddings.api_key', 'default-key');
    config()->set('ai.servers.embeddings.batch_size', 16);

    $provider = factoryProviderBehindPrefixes(EmbeddingsProviderFactory::make('openai_compatible'));

    expect($provider)->toBeInstanceOf(OpenAiCompatibleEmbeddingsProvider::class)
        ->and((fn (): string => $this->baseUri)->call($provider))->toBe('http://localhost:8000/v1')
        ->and((fn (): string => $this->model)->call($provider))->toBe('intfloat/multilingual-e5-small')
        ->and((fn (): int => $this->batchSize)->call($provider))->toBe(16)
        ->and((fn (): array => $this->httpHeaders)->call($provider)['Authorization'])->toBe('Bearer default-key');
});

it('uses the server a profile names', function (): void {
    config()->set('ai.servers.embeddings.url', 'http://default.test:8000');
    config()->set('ai.servers.embeddings_other', ['url' => 'http://other.test:8081', 'api_key' => null, 'timeout' => 12, 'batch_size' => 8]);
    config()->set('ai.features.embeddings.models.openai_compatible:other-model', ['dimensions' => 384, 'server' => 'embeddings_other']);

    $provider = app(EmbeddingModelRegistry::class)->withActive('openai_compatible:other-model', static fn (): EmbeddingsProviderInterface => EmbeddingsProviderFactory::make());
    $provider = factoryProviderBehindPrefixes($provider);

    expect((fn (): string => $this->baseUri)->call($provider))->toBe('http://other.test:8081/v1')
        ->and((fn (): string => $this->model)->call($provider))->toBe('other-model')
        ->and((fn (): int => $this->batchSize)->call($provider))->toBe(8);
});

it('falls back to the default server for a profile whose named server has no url', function (): void {
    config()->set('ai.servers.embeddings.url', 'http://default.test:8000');
    config()->set('ai.servers.embeddings_minilm.url', '');

    $provider = app(EmbeddingModelRegistry::class)->withActive('openai_compatible:all-MiniLM-L6-v2', static fn (): EmbeddingsProviderInterface => EmbeddingsProviderFactory::make());

    expect((fn (): string => $this->baseUri)->call(factoryProviderBehindPrefixes($provider)))->toBe('http://default.test:8000/v1');
});

it('no longer knows the sentence_transformers provider, with or without a hyphen', function (string $name): void {
    config()->set('ai.servers.embeddings.url', 'http://localhost:8000');

    EmbeddingsProviderFactory::make($name);
})->with(['sentence_transformers', 'sentence-transformers'])->throws(InvalidArgumentException::class, 'Unsupported embeddings provider');

it('refuses an OpenAI-compatible provider that is not the active profile and has no model to send', function (): void {
    config()->set('ai.servers.embeddings.url', 'http://localhost:8000');
    config()->set('ai.features.embeddings.serving_model', 'voyageai:voyage-3-lite');
    config()->set('ai.features.embeddings.models.voyageai:voyage-3-lite', ['dimensions' => 512]);

    EmbeddingsProviderFactory::make('openai_compatible');
})->throws(Modules\Core\Exceptions\ConfigurationException::class, 'no model');

it('throws when no embeddings server is configured for the profile', function (): void {
    config()->set('ai.servers.embeddings.url', '');
    config()->set('ai.features.embeddings.serving_model', 'openai_compatible:intfloat/multilingual-e5-small');

    EmbeddingsProviderFactory::make();
})->throws(Modules\Core\Exceptions\ConfigurationException::class, 'embeddings server');

it('keeps the 60-second request timeout of Neuron 3 on the embeddings providers that call an API', function (string $name, array $config): void {
    foreach ($config as $key => $value) {
        config()->set($key, $value);
    }

    $provider = factoryProviderBehindPrefixes(EmbeddingsProviderFactory::make($name));
    $client = (fn (): object => $this->httpClient)->call($provider);

    expect((fn (): float => $this->timeout)->call($client))->toBe(60.0);
})->with([
    'openai' => ['openai', ['ai.providers.openai.api_key' => 'test-key']],
    'ollama' => ['ollama', ['ai.providers.ollama.api_url' => 'http://localhost:11434']],
    'mistral' => ['mistral', ['ai.providers.mistral.api_key' => 'test-key']],
    'voyageai' => ['voyageai', ['ai.providers.voyageai.api_key' => 'test-key']],
]);

it('throws exception for unsupported embeddings provider', function (): void {
    EmbeddingsProviderFactory::make('non-existent');
})->throws(Exception::class, 'Unsupported embeddings provider: non-existent');

it('uses default provider from config when none specified', function (): void {
    config()->set('ai.features.embeddings.serving_model', 'openai_compatible:intfloat/multilingual-e5-small');
    config()->set('ai.servers.embeddings.url', 'http://localhost:8000');

    $provider = EmbeddingsProviderFactory::make();

    expect(factoryProviderBehindPrefixes($provider))->toBeInstanceOf(OpenAiCompatibleEmbeddingsProvider::class);
});

it('throws when the Ollama URL is missing for embeddings', function (): void {
    config()->set('ai.providers.ollama.api_url', null);

    EmbeddingsProviderFactory::make('ollama');
})->throws(Modules\Core\Exceptions\ConfigurationException::class, 'Ollama API URL is not configured');

it('sends the model of the profile in force, not the provider\'s configured model', function (string $profileKey, string $configuredModelKey): void {
    config()->set('ai.providers.openai.api_key', 'test-key');
    config()->set('ai.providers.mistral.api_key', 'test-key');
    config()->set('ai.providers.voyageai.api_key', 'test-key');
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');
    config()->set($configuredModelKey, 'configured-default-model');
    config()->set("ai.features.embeddings.models.{$profileKey}", ['dimensions' => 1024]);

    $provider = app(EmbeddingModelRegistry::class)->withActive($profileKey, static fn (): EmbeddingsProviderInterface => EmbeddingsProviderFactory::make());

    expect((fn (): string => $this->model)->call($provider))->toBe(explode(':', $profileKey, 2)[1]);
})->with([
    'openai' => ['openai:text-embedding-3-large', 'ai.providers.openai.model'],
    'ollama' => ['ollama:mxbai-embed-large', 'ai.providers.ollama.model'],
    'mistral' => ['mistral:mistral-embed-2312', 'ai.providers.mistral.model'],
    'voyageai' => ['voyageai:voyage-3', 'ai.providers.voyageai.model'],
]);

it('asks OpenAI for the dimensions of the profile on the text-embedding-3 models, and for the length of the model on the others', function (string $profileKey, ?int $expected): void {
    config()->set('ai.providers.openai.api_key', 'test-key');
    config()->set("ai.features.embeddings.models.{$profileKey}", ['dimensions' => 1536]);

    $provider = app(EmbeddingModelRegistry::class)->withActive($profileKey, static fn (): EmbeddingsProviderInterface => EmbeddingsProviderFactory::make());

    expect((fn (): ?int => $this->dimensions)->call($provider))->toBe($expected);
})->with([
    'text-embedding-3-small' => ['openai:text-embedding-3-small', 1536],
    'text-embedding-3-large' => ['openai:text-embedding-3-large', 1536],
    'text-embedding-ada-002 takes no dimensions' => ['openai:text-embedding-ada-002', null],
]);

it('does not apply the dimensions of the profile to an OpenAI provider that is not the profile\'s', function (): void {
    config()->set('ai.providers.openai.api_key', 'test-key');
    config()->set('ai.features.embeddings.models.ollama:mxbai-embed-large', ['dimensions' => 1024]);
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');

    $provider = app(EmbeddingModelRegistry::class)->withActive('ollama:mxbai-embed-large', static fn (): EmbeddingsProviderInterface => EmbeddingsProviderFactory::make('openai'));

    expect((fn (): ?int => $this->dimensions)->call($provider))->toBeNull();
});

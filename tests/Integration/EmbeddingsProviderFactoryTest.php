<?php

declare(strict_types=1);

use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\EmbeddingsProviderFactory;
use Modules\AI\Ai\Embeddings\PrefixingEmbeddingsProvider;
use Modules\AI\Ai\Embeddings\SentenceTransformersEmbeddingsProvider;
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

it('creates a SentenceTransformers embeddings provider', function (): void {
    config()->set('ai.providers.sentence_transformers.url', 'http://localhost:8000');

    $provider = EmbeddingsProviderFactory::make('sentence_transformers');

    expect(factoryProviderBehindPrefixes($provider))->toBeInstanceOf(SentenceTransformersEmbeddingsProvider::class);
});

it('also accepts hyphenated sentence-transformers key', function (): void {
    config()->set('ai.providers.sentence_transformers.url', 'http://localhost:8000');

    $provider = EmbeddingsProviderFactory::make('sentence-transformers');

    expect(factoryProviderBehindPrefixes($provider))->toBeInstanceOf(SentenceTransformersEmbeddingsProvider::class);
});

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
    config()->set('core.search.vector.model', 'sentence_transformers:intfloat/multilingual-e5-small');
    config()->set('ai.providers.sentence_transformers.url', 'http://localhost:8000');

    $provider = EmbeddingsProviderFactory::make();

    expect(factoryProviderBehindPrefixes($provider))->toBeInstanceOf(SentenceTransformersEmbeddingsProvider::class);
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

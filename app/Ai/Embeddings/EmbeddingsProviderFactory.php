<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings;

use function ai_config_int;
use function ai_config_nullable_string;
use function ai_config_string;

use InvalidArgumentException;
use Modules\AI\Ai\Providers\ProviderFactory;
use Modules\Core\Exceptions\ConfigurationException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\MistralEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\OllamaEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\VoyageEmbeddingsProvider;

/**
 * Factory for creating NeuronAI embeddings provider instances from application config.
 *
 * The model sent to the provider is the service model of the profile in force (the active one, or
 * the one {@see EmbeddingModelRegistry::withActive()} sets while a switch embeds), so a switch to
 * another model of the same provider embeds with that model. `ai.providers.*.model` is only the
 * fallback when a provider other than the profile's is asked for explicitly.
 *
 * The provider of the profile comes with the query and passage prefixes of its model
 * ({@see PrefixingEmbeddingsProvider}), so callers hand it the raw text.
 */
final class EmbeddingsProviderFactory
{
    public static function make(?string $provider = null): EmbeddingsProviderInterface
    {
        $profile = self::registry()->active();
        $provider ??= $profile->provider;
        $isProfileProvider = self::canonical($provider) === self::canonical($profile->provider);
        $model = $isProfileProvider ? $profile->serviceModel : null;

        $embeddings = match ($provider) {
            'openai' => self::createOpenAI($model, $isProfileProvider ? $profile->dimensions : null),
            'ollama' => self::createOllama($model),
            'mistral' => self::createMistral($model),
            'voyageai' => self::createVoyage($model),
            'sentence-transformers', 'sentence_transformers' => self::createSentenceTransformers($model),
            default => throw new InvalidArgumentException("Unsupported embeddings provider: {$provider}"),
        };

        return $isProfileProvider ? PrefixingEmbeddingsProvider::forProfile($embeddings, $profile) : $embeddings;
    }

    /**
     * The API takes a `dimensions` parameter on the `text-embedding-3` models only, and Neuron's
     * provider sends 1024 unless told otherwise, so a profile of 1536 would get vectors of 1024. The
     * profile's dimensions are sent for those models; any other model, and a provider that is not the
     * profile's, is asked for the vector length that the model has.
     */
    private static function createOpenAI(?string $model, ?int $dimensions): OpenAIEmbeddingsProvider
    {
        $model ??= ai_config_string('ai.providers.openai.model', 'text-embedding-3-small');

        return new OpenAIEmbeddingsProvider(
            key: ai_config_string('ai.providers.openai.api_key'),
            model: $model,
            dimensions: $dimensions !== null && str_starts_with($model, 'text-embedding-3') ? $dimensions : null,
            httpClient: self::httpClient(),
        );
    }

    private static function createOllama(?string $model): OllamaEmbeddingsProvider
    {
        $url = ai_config_string('ai.providers.ollama.api_url');
        throw_if($url === '', ConfigurationException::class, 'Ollama API URL is not configured');

        return new OllamaEmbeddingsProvider(
            model: $model ?? ai_config_string('ai.providers.ollama.model', 'nomic-embed-text'),
            url: mb_rtrim($url, '/') . '/api',
            httpClient: self::httpClient(),
        );
    }

    private static function createMistral(?string $model): MistralEmbeddingsProvider
    {
        return new MistralEmbeddingsProvider(
            key: ai_config_string('ai.providers.mistral.api_key'),
            model: $model ?? ai_config_string('ai.providers.mistral.model', 'mistral-embed'),
            httpClient: self::httpClient(),
        );
    }

    private static function createVoyage(?string $model): VoyageEmbeddingsProvider
    {
        return new VoyageEmbeddingsProvider(
            key: ai_config_string('ai.providers.voyageai.api_key'),
            model: $model ?? ai_config_string('ai.providers.voyageai.model', 'voyage-3-lite'),
            httpClient: self::httpClient(),
        );
    }

    /**
     * The service is multi-model: the model is sent per request, so it embeds with the model
     * Laraplate expects.
     */
    private static function createSentenceTransformers(?string $model): SentenceTransformersEmbeddingsProvider
    {
        return new SentenceTransformersEmbeddingsProvider(
            url: ai_config_string('ai.providers.sentence_transformers.url', 'http://localhost:8000'),
            api_key: ai_config_nullable_string('ai.providers.sentence_transformers.api_key'),
            timeout: ai_config_int('ai.providers.sentence_transformers.timeout', 30),
            batch_size: ai_config_int('ai.providers.sentence_transformers.batch_size', 32),
            model: $model,
        );
    }

    /**
     * The client Neuron 3 gave every provider by default had a 60-second limit; Neuron 4's own allows 300.
     */
    private static function httpClient(): CurlHttpClient
    {
        return new CurlHttpClient(timeout: ProviderFactory::HTTP_TIMEOUT);
    }

    private static function registry(): EmbeddingModelRegistry
    {
        return resolve(EmbeddingModelRegistry::class);
    }

    private static function canonical(string $provider): string
    {
        return str_replace('-', '_', $provider);
    }
}

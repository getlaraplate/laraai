<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Providers;

use function ai_config_string;

use InvalidArgumentException;
use Modules\AI\Enums\AiModelFeature;
use Modules\Core\Exceptions\ConfigurationException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Mistral\Mistral;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAILike;

/**
 * Factory for creating NeuronAI provider instances from application config.
 */
final class ProviderFactory
{
    /**
     * Seconds one model call may take when the caller asks for no cap: the limit Neuron 3 put on every
     * provider, which Neuron 4's own client (300 seconds) would otherwise raise.
     */
    public const float HTTP_TIMEOUT = 60.0;

    private const string ANTHROPIC_DEFAULT_MODEL = 'claude-sonnet-4-20250514';

    /**
     * Build a provider. An explicit `$model` overrides the provider's configured
     * default, letting one feature (e.g. text generation) target a different
     * model than another without changing the shared provider config; `null`
     * keeps the configured default. Without a provider, provider and model come
     * from the chat choice in Settings. `$maxOutputTokens` caps what the model may
     * write in one answer, with the parameter each provider names it by; `null`
     * leaves the provider's own limit. `$timeout` caps the seconds of one call to the model; `null`
     * keeps {@see self::HTTP_TIMEOUT}. `$temperature` sets the sampling temperature with the
     * parameter each provider names it by; `null` leaves the provider's own.
     */
    public static function make(?string $provider = null, ?string $model = null, ?int $maxOutputTokens = null, ?float $timeout = null, ?float $temperature = null): AIProviderInterface
    {
        if ($provider === null) {
            $choice = AiModelChoice::forFeature(AiModelFeature::Chat);
            $provider = $choice->provider;
            $model ??= $choice->model;
        }

        return match ($provider) {
            'openai' => self::createOpenAI($model, $maxOutputTokens, $timeout, $temperature),
            'ollama' => self::createOllama($model, $maxOutputTokens, $timeout, $temperature),
            'mistral' => self::createMistral($model, $maxOutputTokens, $timeout, $temperature),
            'anthropic' => self::createAnthropic($model, $maxOutputTokens, $timeout, $temperature),
            default => throw new InvalidArgumentException("Unsupported AI provider: {$provider}"),
        };
    }

    /**
     * Whether the chat provider chosen in Settings has what it needs to be called (an API key, a
     * URL). It builds the provider and calls nothing.
     */
    public static function isConfigured(): bool
    {
        try {
            self::make();
        } catch (ConfigurationException|InvalidArgumentException) {
            return false;
        }

        return true;
    }

    /**
     * With `ai.providers.openai.base_url` set, the provider talks to that OpenAI-compatible server
     * (llama.cpp, vLLM, LM Studio...), which may need no key; without it, to api.openai.com.
     */
    private static function createOpenAI(?string $model, ?int $maxOutputTokens, ?float $timeout, ?float $temperature): OpenAI
    {
        $api_key = ai_config_string('ai.providers.openai.api_key');
        $base_url = ai_config_string('ai.providers.openai.base_url');
        $model ??= ai_config_string('ai.providers.openai.model', 'gpt-4o-mini');
        $parameters = self::withTemperature(
            $maxOutputTokens === null ? [] : ['max_completion_tokens' => $maxOutputTokens],
            $temperature,
        );

        if ($base_url !== '') {
            return new OpenAILike(
                baseUri: mb_rtrim($base_url, '/'),
                key: $api_key === '' ? 'none' : $api_key,
                model: $model,
                parameters: $parameters,
                httpClient: self::httpClient($timeout),
            );
        }

        throw_if($api_key === '', ConfigurationException::class, 'OpenAI API key is not configured');

        return new OpenAI(key: $api_key, model: $model, parameters: $parameters, httpClient: self::httpClient($timeout));
    }

    private static function createOllama(?string $model, ?int $maxOutputTokens, ?float $timeout, ?float $temperature): Ollama
    {
        $options = self::withTemperature($maxOutputTokens === null ? [] : ['num_predict' => $maxOutputTokens], $temperature);

        $url = ai_config_string('ai.providers.ollama.api_url');
        throw_if($url === '', ConfigurationException::class, 'Ollama API URL is not configured');

        // OLLAMA_API_URL is the server base (http://host:11434); neuron-ai joins
        // `chat` onto its base, which must therefore end with /api.
        return new Ollama(
            url: mb_rtrim($url, '/') . '/api',
            model: $model ?? ai_config_string('ai.providers.ollama.model', 'llama3.2:3b'),
            parameters: $options === [] ? [] : ['options' => $options],
            httpClient: self::httpClient($timeout),
        );
    }

    private static function createMistral(?string $model, ?int $maxOutputTokens, ?float $timeout, ?float $temperature): Mistral
    {
        $api_key = ai_config_string('ai.providers.mistral.api_key');
        throw_if($api_key === '', ConfigurationException::class, 'Mistral API key is not configured');

        return new Mistral(
            key: $api_key,
            model: $model ?? ai_config_string('ai.providers.mistral.model', 'mistral-large-latest'),
            parameters: self::withTemperature($maxOutputTokens === null ? [] : ['max_tokens' => $maxOutputTokens], $temperature),
            httpClient: self::httpClient($timeout),
        );
    }

    private static function createAnthropic(?string $model, ?int $maxOutputTokens, ?float $timeout, ?float $temperature): Anthropic
    {
        $api_key = ai_config_string('ai.providers.anthropic.api_key');
        throw_if($api_key === '', ConfigurationException::class, 'Anthropic API key is not configured');

        $parameters = self::withTemperature([], $temperature);

        return $maxOutputTokens === null
            ? new Anthropic(key: $api_key, model: $model ?? self::ANTHROPIC_DEFAULT_MODEL, parameters: $parameters, httpClient: self::httpClient($timeout))
            : new Anthropic(key: $api_key, model: $model ?? self::ANTHROPIC_DEFAULT_MODEL, max_tokens: $maxOutputTokens, parameters: $parameters, httpClient: self::httpClient($timeout));
    }

    /**
     * `$parameters` with the sampling temperature added, when one is asked for.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private static function withTemperature(array $parameters, ?float $temperature): array
    {
        return $temperature === null ? $parameters : [...$parameters, 'temperature' => $temperature];
    }

    private static function httpClient(?float $timeout): CurlHttpClient
    {
        return new CurlHttpClient(timeout: $timeout ?? self::HTTP_TIMEOUT);
    }
}

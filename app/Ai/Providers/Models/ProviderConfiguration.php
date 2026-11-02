<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Providers\Models;

use function ai_config_string;

use Modules\AI\Ai\SelfHosted\AiServers;

/**
 * Whether a provider is configured, and how to list its models. A provider is configured
 * when its API key (openai, which also accepts a base URL, anthropic, mistral, voyageai, deepl), its URL (ollama) or the transcription server of `ai.servers` (openai_compatible) is set; an embedding profile is configured by the server it names (see EmbeddingModelRegistry::configuredKeys()).
 */
final readonly class ProviderConfiguration
{
    public function isConfigured(string $provider): bool
    {
        return match ($provider) {
            'openai' => ai_config_string('ai.providers.openai.api_key') !== '' || ai_config_string('ai.providers.openai.base_url') !== '',
            'anthropic', 'mistral', 'voyageai' => ai_config_string("ai.providers.{$provider}.api_key") !== '',
            'deepl' => ai_config_string('core.deepl_api_key') !== '',
            'ollama' => ai_config_string('ai.providers.ollama.api_url') !== '',
            'openai_compatible' => new AiServers()->transcription()->isConfigured(),
            default => false,
        };
    }

    /**
     * Whether calls to the provider go to a server the installation declares by its URL (true: the
     * URL of `ollama`, the base URL of `openai`, the transcription server of `ai.servers` for
     * `openai_compatible`) or to a hosted service reached by its key (false). Null when the provider
     * is unknown or not configured, so no call leaves at all. The base URL of `openai` serves chat
     * only: its embeddings always go to the hosted service.
     */
    public function reachesDeclaredServer(string $provider, bool $embeddings = false): ?bool
    {
        if (! $this->isConfigured($provider)) {
            return null;
        }

        return match ($provider) {
            'ollama', 'openai_compatible' => true,
            'openai' => ! $embeddings && ai_config_string('ai.providers.openai.base_url') !== '',
            'anthropic', 'mistral', 'voyageai', 'deepl' => false,
            default => null,
        };
    }

    /**
     * Null for providers without a model catalogue: DeepL has no model to choose. The `openai_compatible`
     * provider offers the transcription models the installer declared in `ai.features.media_analysis.transcription.models`.
     */
    public function lister(string $provider): ?ModelLister
    {
        return match ($provider) {
            'openai' => new OpenAiModelLister(ai_config_string('ai.providers.openai.api_key'), ai_config_string('ai.providers.openai.base_url')),
            'anthropic' => new AnthropicModelLister(ai_config_string('ai.providers.anthropic.api_key')),
            'mistral' => new MistralModelLister(ai_config_string('ai.providers.mistral.api_key')),
            'ollama' => new OllamaModelLister(ai_config_string('ai.providers.ollama.api_url')),
            'openai_compatible' => new ConfiguredModelLister('features.media_analysis.transcription.models'),
            default => null,
        };
    }
}

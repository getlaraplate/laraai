<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Providers\Models;

use function ai_config_string;

use Modules\AI\Ai\SelfHosted\AiServers;

/**
 * Whether a provider is configured, and how to list its models. A provider is configured
 * when its API key (openai, which also accepts a base URL, anthropic, mistral, voyageai, deepl), its URL (ollama) or a server of `ai.servers` (openai_compatible: embeddings or transcription) is set.
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
            'openai_compatible' => new AiServers()->named(AiServers::DEFAULT_EMBEDDINGS)->isConfigured() || new AiServers()->transcription()->isConfigured(),
            'whisper' => ai_config_string('ai.providers.whisper.url') !== '',
            default => false,
        };
    }

    /**
     * Null for providers without a model catalogue: DeepL has no model to choose, and the
     * Whisper host picks its model through its own `WHISPER_MODEL`.
     */
    public function lister(string $provider): ?ModelLister
    {
        return match ($provider) {
            'openai' => new OpenAiModelLister(ai_config_string('ai.providers.openai.api_key'), ai_config_string('ai.providers.openai.base_url')),
            'anthropic' => new AnthropicModelLister(ai_config_string('ai.providers.anthropic.api_key')),
            'mistral' => new MistralModelLister(ai_config_string('ai.providers.mistral.api_key')),
            'ollama' => new OllamaModelLister(ai_config_string('ai.providers.ollama.api_url')),
            default => null,
        };
    }
}

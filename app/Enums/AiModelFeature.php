<?php

declare(strict_types=1);

namespace Modules\AI\Enums;

/**
 * Every AI feature whose provider and model are chosen in Settings. Embeddings are not here:
 * changing their model changes the vector dimensions and forces a reindex.
 */
enum AiModelFeature: string
{
    case Chat = 'chat';
    case TextGeneration = 'text_generation';
    case Moderation = 'moderation';
    case SearchOrchestration = 'search_orchestration';
    case Translation = 'translation';
    case Faq = 'faq';
    case ContextualSuggestions = 'contextual_suggestions';
    case ChatSummary = 'chat_summary';
    case Vision = 'vision';
    case Transcription = 'transcription';

    private const array CHAT_PROVIDERS = ['openai', 'ollama', 'mistral', 'anthropic'];

    public static function fromSettingName(string $name): ?self
    {
        foreach (self::cases() as $feature) {
            if ($name === $feature->settingName()) {
                return $feature;
            }
        }

        return null;
    }

    /**
     * Setting name, without the module prefix; read from config as `ai.{name}`.
     */
    public function settingName(): string
    {
        return match ($this) {
            self::Chat => 'features.chat.model',
            self::TextGeneration => 'features.text_generation.model',
            self::Moderation => 'features.moderation.model',
            self::SearchOrchestration => 'features.search_orchestration.model',
            self::Translation => 'features.translation.model',
            self::Faq => 'features.faq.model',
            self::ContextualSuggestions => 'features.contextual_suggestions.model',
            self::ChatSummary => 'features.chat.summary.model',
            self::Vision => 'features.media_analysis.vision.model',
            self::Transcription => 'features.media_analysis.transcription.model',
        };
    }

    public function settingDescription(): string
    {
        return match ($this) {
            self::Chat => 'AI model used by chat (provider:model)',
            self::TextGeneration => 'AI model used for one-shot text generation (provider:model)',
            self::Moderation => 'AI model used for moderation (provider:model)',
            self::SearchOrchestration => 'AI model used for search orchestration (provider:model)',
            self::Translation => 'Translation provider: deepl, or an AI model (provider:model)',
            self::Faq => 'AI model used for FAQ answers (provider:model)',
            self::ContextualSuggestions => 'AI model used for contextual suggestions (provider:model)',
            self::ChatSummary => 'AI model used for chat summaries and memory (provider:model)',
            self::Vision => 'AI model used for media vision analysis (provider:model)',
            self::Transcription => 'Transcription provider for media analysis',
        };
    }

    /**
     * The choice used until Settings holds one. It lives in code: a value managed by a setting
     * has no config entry and no env variable, since the seeder writes it once and a later env
     * change would silently do nothing.
     */
    public function defaultChoice(): string
    {
        return match ($this) {
            self::Translation => 'deepl',
            self::Vision => 'anthropic:claude-sonnet-5',
            self::Transcription => 'openai_compatible:Systran/faster-whisper-base',
            default => 'ollama:llama3.2:3b',
        };
    }

    /**
     * @return list<string>
     */
    public function supportedProviders(): array
    {
        return match ($this) {
            self::Translation => ['deepl', ...self::CHAT_PROVIDERS],
            self::Vision => ['anthropic', 'openai', 'ollama'],
            self::Transcription => ['openai_compatible'],
            default => self::CHAT_PROVIDERS,
        };
    }

    /**
     * @return list<ModelCapability>
     */
    public function requiredCapabilities(): array
    {
        return match ($this) {
            self::Chat => [ModelCapability::Chat, ModelCapability::Tools],
            self::Vision => [ModelCapability::Vision],
            self::Transcription => [],
            default => [ModelCapability::Chat],
        };
    }
}

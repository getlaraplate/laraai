<?php

declare(strict_types=1);

namespace Modules\AI\Database\Seeders;

use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchState;
use Modules\AI\Ai\Rag\FaqVectorStoreConfig;
use Modules\AI\Enums\AiModelFeature;
use Modules\AI\Services\ModerationEntitySettings;
use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Models\Setting;
use Modules\Core\Overrides\Seeder;
use Modules\Core\Seeding\SeedReconciler;

class AIDatabaseSeeder extends Seeder
{
    /**
     * @param  ?string  $servingModel  the model that serves search, when already stored (see {@see self::storedServingModel()})
     * @return array<int, array{name: string, value: mixed, encrypted: bool, choices: ?array<int, mixed>, type: SettingTypeEnum, group_name: string, description: string}>
     */
    public static function runtimeSettingDefinitions(?string $servingModel = null): array
    {
        return [
            self::setting('features.embeddings.enabled', false, SettingTypeEnum::Boolean, 'ai', 'Enable embeddings generation'),
            ...self::embeddingModelSettings($servingModel),
            self::setting('features.translation.enabled', false, SettingTypeEnum::Boolean, 'ai', 'Enable automatic translation'),
            self::setting('features.media_analysis.enabled', false, SettingTypeEnum::Boolean, 'ai', 'Enable media LLM analysis (caption, OCR, transcription)'),
            self::setting('features.chat.enabled', false, SettingTypeEnum::Boolean, 'ai', 'Enable the in-app assistant (conversations, messages, agent and the decisions on what it proposes)'),
            self::setting('features.chat.summary.enabled', false, SettingTypeEnum::Boolean, 'ai', 'Enable chat summarization'),
            self::setting('features.faq.enabled', false, SettingTypeEnum::Boolean, 'ai', 'Enable FAQ/RAG answers: the assistant reads the user documentation to answer how the application works'),
            self::setting('features.faq.max_documents', 5, SettingTypeEnum::Integer, 'ai', 'Maximum FAQ documents to retrieve'),
            self::setting('features.faq.min_similarity', 0.7, SettingTypeEnum::Float, 'ai', 'Minimum FAQ similarity score'),
            self::setting('features.faq.format_citations', true, SettingTypeEnum::Boolean, 'ai', 'Append citations to FAQ answers'),
            self::setting('features.faq.splitter.driver', 'markdown_aware', SettingTypeEnum::String, 'ai', 'FAQ document splitter driver', ['markdown_aware', 'sentence', 'delimiter']),
            self::setting('features.faq.splitter.max_words', 250, SettingTypeEnum::Integer, 'ai', 'Maximum words per FAQ chunk'),
            self::setting('features.faq.splitter.overlap_words', 0, SettingTypeEnum::Integer, 'ai', 'FAQ chunk overlap words'),
            self::setting('features.faq.splitter.prepend_heading_breadcrumb', true, SettingTypeEnum::Boolean, 'ai', 'Prepend heading breadcrumb to FAQ chunks'),
            self::setting('features.contextual_suggestions.enabled', false, SettingTypeEnum::Boolean, 'ai', 'Enable contextual suggestions'),
            self::setting('features.contextual_suggestions.cooldown_minutes', 5, SettingTypeEnum::Integer, 'ai', 'Contextual suggestions cooldown minutes'),
            self::setting('features.contextual_suggestions.cache_ttl', 3600, SettingTypeEnum::Integer, 'ai', 'Contextual suggestions cache TTL seconds'),
            self::setting('features.moderation.enabled', false, SettingTypeEnum::Boolean, 'ai', 'Enable AI moderation'),
            self::setting('features.moderation.approval_mode', 'threshold', SettingTypeEnum::String, 'ai', 'AI moderation approval mode', ['threshold', 'dual']),
            self::setting('features.moderation.votes', true, SettingTypeEnum::Boolean, 'ai', 'Allow AI votes in approval workflow'),
            self::setting('features.moderation.threshold.approve', 0.85, SettingTypeEnum::Float, 'ai', 'AI moderation approval confidence threshold'),
            self::setting('features.moderation.threshold.reject', 0.85, SettingTypeEnum::Float, 'ai', 'AI moderation rejection confidence threshold'),
            self::setting('features.moderation.queue', 'default', SettingTypeEnum::String, 'ai', 'Queue of the AI moderation jobs (read when a job is dispatched)'),
            self::setting('features.text_generation.enabled', false, SettingTypeEnum::Boolean, 'ai', 'Enable one-shot text generation for other modules (e.g. SAO ownership suggestions)'),
            self::setting('features.text_generation.max_output_chars', 500, SettingTypeEnum::Integer, 'ai', 'Maximum characters of a generated text (longer text is cut on a word boundary)'),
            self::setting('features.text_generation.cache_ttl_seconds', 0, SettingTypeEnum::Integer, 'ai', 'Text generation cache TTL seconds (0 = no cache)'),
            self::setting('features.text_generation.rate_limit.max', 60, SettingTypeEnum::Integer, 'ai', 'Text generation requests per purpose within the window (0 = unlimited)'),
            self::setting('features.text_generation.rate_limit.per_seconds', 60, SettingTypeEnum::Integer, 'ai', 'Text generation rate limit window seconds'),
            self::setting('features.faq.vector_store', FaqVectorStoreConfig::DEFAULT_DRIVER, SettingTypeEnum::String, 'ai', 'Vector store of the documentation (RAG) indexes; rebuild them after a change (ai:index-rag-docs --full)', ['elasticsearch', 'filesystem']),
            self::setting('features.faq.policy_classification_version', 'in-app-docs-v1', SettingTypeEnum::String, 'ai', 'Policy classification version the user documentation must carry to be answered from'),
            self::setting('features.faq.query_logging.enabled', false, SettingTypeEnum::Boolean, 'ai', 'Log answered in-app documentation questions for analytics (create the index first: ai:create-rag-query-index)'),
            self::setting('features.faq.query_logging.query_text_mode', 'raw', SettingTypeEnum::String, 'ai', 'Whether the question is logged as typed (raw) or not at all (off)', ['raw', 'off']),
            self::setting('features.faq.query_logging.retention_days', 30, SettingTypeEnum::Integer, 'ai', 'Days a logged documentation question is kept before it is deleted'),
        ];
    }

    /**
     * One setting per AI feature holding its `provider:model`. The initial value is the
     * feature's default choice, from code: it does not read config, so seeding never depends on
     * what the overlay holds. The refresh command owns the choices from its first run on.
     *
     * @return list<array<string, mixed>>
     */
    public static function modelSettingDefinitions(): array
    {
        return array_map(
            static function (AiModelFeature $feature): array {
                $initial = $feature->defaultChoice();

                return [
                    ...self::setting($feature->settingName(), $initial, SettingTypeEnum::String, 'ai', $feature->settingDescription(), [$initial]),
                    'action_command' => 'ai:models:refresh --setting={name}',
                    // Queued: a refresh calls up to five providers in turn, plus one Ollama
                    // request per installed model, which can outlast a web request.
                    'action_queued' => true,
                ];
            },
            AiModelFeature::cases(),
        );
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $reconciler = app(SeedReconciler::class);

        $runtime = $reconciler->reconcile(
            self::internalSettingsDefinition('AI', [
                ...self::runtimeSettingDefinitions(self::storedServingModel()),
                ...app(ModerationEntitySettings::class)->definitions(),
            ]),
        );
        $models = $reconciler->reconcile(
            self::commandManagedChoicesSettingsDefinition('AI', self::modelSettingDefinitions()),
        );

        $this->command?->line(sprintf(
            '    - created %d, realigned %d, unchanged %d',
            count($runtime->created) + count($models->created),
            count($runtime->realigned) + count($models->realigned),
            $runtime->unchanged + $models->unchanged,
        ));
    }

    /**
     * The embedding model choice and the switch progress. The model that serves search is Core's
     * managed `search.vector.model`, not an AI setting. The choice defaults to that serving model,
     * so a fresh installation starts with the dropdown on the model Core seeded; without a stored
     * value it is the registry's active key. The choices are the configured profiles, and the
     * serving model is always among them.
     *
     * @return list<array<string, mixed>>
     */
    private static function embeddingModelSettings(?string $servingModel): array
    {
        $registry = app(EmbeddingModelRegistry::class);
        $default = $servingModel !== null && $servingModel !== '' ? $servingModel : $registry->activeKey();
        $choices = $registry->configuredKeys();

        if ($default !== '' && ! in_array($default, $choices, true)) {
            $choices[] = $default;
        }

        return [
            self::setting('features.embeddings.model', $default, SettingTypeEnum::String, 'ai', 'Embedding model, as provider:model (changing it starts a model switch)', $choices),
            [...self::setting('features.embeddings.switch', EmbeddingSwitchState::idle()->toJson(), SettingTypeEnum::String, 'ai', 'Embedding model switch progress, as JSON (set by the embedding model switch)'), 'managed' => true],
        ];
    }

    /**
     * The stored `search.vector.model`, written by the Core seeder that runs first. It is read from
     * the table because the seed reconciler upserts without model events, so on a fresh
     * installation the runtime config does not hold the Core value yet.
     */
    private static function storedServingModel(): ?string
    {
        $stored = Setting::query()->withoutGlobalScopes()->where('name', 'search.vector.model')->first()?->value;

        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    /**
     * @return array{name: string, value: mixed, encrypted: bool, choices: ?array<int, mixed>, type: SettingTypeEnum, group_name: string, description: string}
     */
    private static function setting(string $name, mixed $value, SettingTypeEnum $type, string $group, string $description, ?array $choices = null): array
    {
        return [
            'name' => $name,
            'value' => $value,
            'encrypted' => false,
            'choices' => $choices,
            'type' => $type,
            'group_name' => $group,
            'description' => $description,
        ];
    }
}

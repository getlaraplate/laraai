<?php

declare(strict_types=1);

use Modules\AI\Database\Seeders\AIDatabaseSeeder;
use Modules\Core\Casts\SettingTypeEnum;

it('defines ai runtime settings with current defaults and choices', function (): void {
    $definitions = collect(AIDatabaseSeeder::runtimeSettingDefinitions())->keyBy('name');

    expect($definitions->get('features.embeddings.enabled')['value'])->toBeFalse()
        ->and($definitions->get('features.faq.splitter.driver')['value'])->toBe('markdown_aware')
        ->and($definitions->get('features.faq.splitter.driver')['choices'])
        ->toBe(['markdown_aware', 'sentence', 'delimiter'])
        ->and($definitions->get('features.moderation.approval_mode')['choices'])->toBe(['threshold', 'dual']);
});

it('defines the switches and tuning values that were env variables, with the defaults they had', function (string $name, mixed $value, SettingTypeEnum $type, ?array $choices): void {
    $definition = collect(AIDatabaseSeeder::runtimeSettingDefinitions())->keyBy('name')->get($name);

    expect($definition)->not->toBeNull()
        ->and($definition['value'])->toBe($value)
        ->and($definition['type'])->toBe($type)
        ->and($definition['choices'])->toBe($choices)
        ->and($definition['group_name'])->toBe('ai')
        ->and(config('ai.' . $name))->toBe($value);
})->with([
    'text generation switch' => ['features.text_generation.enabled', false, SettingTypeEnum::Boolean, null],
    'text generation length' => ['features.text_generation.max_output_chars', 500, SettingTypeEnum::Integer, null],
    'text generation cache' => ['features.text_generation.cache_ttl_seconds', 0, SettingTypeEnum::Integer, null],
    'text generation rate' => ['features.text_generation.rate_limit.max', 60, SettingTypeEnum::Integer, null],
    'text generation window' => ['features.text_generation.rate_limit.per_seconds', 60, SettingTypeEnum::Integer, null],
    'documentation vector store' => ['features.faq.vector_store', 'elasticsearch', SettingTypeEnum::String, ['elasticsearch', 'filesystem']],
    'documentation policy version' => ['features.faq.policy_classification_version', 'in-app-docs-v1', SettingTypeEnum::String, null],
    'moderation queue' => ['features.moderation.queue', 'default', SettingTypeEnum::String, null],
    'documentation query log switch' => ['features.faq.query_logging.enabled', false, SettingTypeEnum::Boolean, null],
    'documentation query log text' => ['features.faq.query_logging.query_text_mode', 'raw', SettingTypeEnum::String, ['raw', 'off']],
    'documentation query log retention' => ['features.faq.query_logging.retention_days', 30, SettingTypeEnum::Integer, null],
    'search orchestration switch' => ['features.search_orchestration.enabled', true, SettingTypeEnum::Boolean, null],
    'search orchestration timeout' => ['features.search_orchestration.timeout', 10.0, SettingTypeEnum::Float, null],
]);

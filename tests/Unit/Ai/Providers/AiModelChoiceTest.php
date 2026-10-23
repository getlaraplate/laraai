<?php

declare(strict_types=1);

use Modules\AI\Ai\Providers\AiModelChoice;
use Modules\AI\Enums\AiModelFeature;
use Modules\AI\Enums\ModelCapability;

it('splits a choice on the first colon only', function (): void {
    $choice = AiModelChoice::parse('ollama:llama3.2:3b');

    expect($choice->provider)->toBe('ollama')
        ->and($choice->model)->toBe('llama3.2:3b')
        ->and($choice->value())->toBe('ollama:llama3.2:3b');
});

it('reads a provider without a model', function (): void {
    $choice = AiModelChoice::parse('deepl');

    expect($choice->provider)->toBe('deepl')
        ->and($choice->model)->toBeNull()
        ->and($choice->value())->toBe('deepl');
});

it('refuses an empty choice', function (): void {
    AiModelChoice::parse('  ');
})->throws(InvalidArgumentException::class);

it('reads the overlaid choice of a feature', function (): void {
    config()->set('ai.features.chat.model', 'anthropic:claude-sonnet-5');

    $choice = AiModelChoice::forFeature(AiModelFeature::Chat);

    expect($choice->provider)->toBe('anthropic')
        ->and($choice->model)->toBe('claude-sonnet-5');
});

it('falls back to the default choice when nothing is overlaid', function (AiModelFeature $feature): void {
    config()->set('ai.' . $feature->settingName(), null);

    expect(AiModelChoice::forFeature($feature)->value())->toBe($feature->defaultChoice())
        ->and($feature->supportedProviders())->toContain(AiModelChoice::parse($feature->defaultChoice())->provider);
})->with(AiModelFeature::cases());

it('keeps today\'s defaults', function (): void {
    expect(AiModelFeature::Chat->defaultChoice())->toBe('ollama:llama3.2:3b')
        ->and(AiModelFeature::Translation->defaultChoice())->toBe('deepl')
        ->and(AiModelFeature::Vision->defaultChoice())->toBe('anthropic:claude-sonnet-5')
        ->and(AiModelFeature::Transcription->defaultChoice())->toBe('openai_compatible:Systran/faster-whisper-base');
});

it('maps every setting name back to its feature', function (AiModelFeature $feature): void {
    expect(AiModelFeature::fromSettingName($feature->settingName()))->toBe($feature);
})->with(AiModelFeature::cases());

it('declares providers and capabilities per feature', function (): void {
    expect(AiModelFeature::Translation->supportedProviders())->toContain('deepl')
        ->and(AiModelFeature::Chat->supportedProviders())->not->toContain('deepl')
        ->and(AiModelFeature::Chat->requiredCapabilities())->toBe([ModelCapability::Chat, ModelCapability::Tools])
        ->and(AiModelFeature::Vision->requiredCapabilities())->toBe([ModelCapability::Vision])
        ->and(AiModelFeature::Transcription->supportedProviders())->toBe(['openai_compatible'])
        ->and(AiModelFeature::fromSettingName('features.faq.enabled'))->toBeNull();
});

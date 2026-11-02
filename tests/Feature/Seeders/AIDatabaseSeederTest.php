<?php

declare(strict_types=1);

use Modules\AI\Database\Seeders\AIDatabaseSeeder;
use Modules\AI\Enums\AiModelFeature;
use Modules\Core\Models\Setting;
use Modules\Core\Services\DatabaseConfigOverlay;
use Modules\Core\Services\PerModelSettingResolver;

it('seeds AI runtime settings stamped with the AI module', function (): void {
    $this->seed(AIDatabaseSeeder::class);

    $names = collect(AIDatabaseSeeder::runtimeSettingDefinitions())->pluck('name');

    $settings = Setting::query()->withoutGlobalScopes()->whereIn('name', $names)->get();

    expect($settings)->toHaveCount($names->count())
        ->and($settings->pluck('module')->unique()->all())->toBe(['AI']);
});

it('is idempotent and leaves an operator-changed value untouched on a second run', function (): void {
    $this->seed(AIDatabaseSeeder::class);

    Setting::query()->withoutGlobalScopes()
        ->where('name', 'features.faq.max_documents')
        ->update(['value' => json_encode(999), 'description' => 'drifted']);

    $this->seed(AIDatabaseSeeder::class);

    $setting = Setting::query()->withoutGlobalScopes()
        ->where('name', 'features.faq.max_documents')->sole();

    expect($setting->value)->toBe(999)
        ->and($setting->description)->toBe('Maximum FAQ documents to retrieve');
});

it('seeds one model setting per feature with its refresh action', function (): void {
    $this->seed(AIDatabaseSeeder::class);

    foreach (AiModelFeature::cases() as $feature) {
        $setting = Setting::query()->withoutGlobalScopes()->where('name', $feature->settingName())->sole();
        $initial = $feature->defaultChoice();

        expect($setting->module)->toBe('AI')
            ->and($setting->value)->toBe($initial)
            ->and($setting->choices)->toBe([$initial])
            ->and($setting->action_command)->toBe('ai:models:refresh --setting={name}')
            ->and($setting->action_queued)->toBeTrue();
    }
});

it('keeps refreshed model choices across a re-seed', function (): void {
    $this->seed(AIDatabaseSeeder::class);

    Setting::query()->withoutGlobalScopes()->where('name', 'features.chat.model')
        ->update(['choices' => json_encode(['ollama:a', 'ollama:b'])]);

    $this->seed(AIDatabaseSeeder::class);

    expect(Setting::query()->withoutGlobalScopes()->where('name', 'features.chat.model')->sole()->choices)
        ->toBe(['ollama:a', 'ollama:b']);
});

it('overlays a seeded switch that used to be an env variable onto the config the code reads', function (): void {
    $this->seed(AIDatabaseSeeder::class);

    Setting::query()->withoutGlobalScopes()->where('name', 'features.moderation.queue')
        ->update(['value' => json_encode('ai-moderation')]);
    Setting::query()->withoutGlobalScopes()->where('name', 'features.text_generation.max_output_chars')
        ->update(['value' => json_encode(120)]);

    app(DatabaseConfigOverlay::class)->applyFromDatabase(app(PerModelSettingResolver::class));

    expect(config('ai.features.moderation.queue'))->toBe('ai-moderation')
        ->and(config('ai.features.text_generation.max_output_chars'))->toBe(120);
});

it('seeds the relevance judge off, with its rounds, sources and excerpt length, and the model size hint', function (): void {
    $this->seed(AIDatabaseSeeder::class);

    $settings = Setting::query()->withoutGlobalScopes()->where('name', 'like', 'features.rag.judge.%')->get()->keyBy('name');

    expect($settings['features.rag.judge.enabled']->value)->toBeFalse()
        ->and($settings['features.rag.judge.max_rounds']->value)->toBe(3)
        ->and($settings['features.rag.judge.min_sources']->value)->toBe(3)
        ->and($settings['features.rag.judge.excerpt_chars']->value)->toBe(600)
        ->and($settings['features.rag.judge.enabled']->description)->toContain('mid-size instruction-tuned')->toContain('test:ai:evaluate-documentation')
        ->and($settings['features.rag.judge.model']->choices)->toBe([AiModelFeature::RelevanceJudge->defaultChoice()]);
});

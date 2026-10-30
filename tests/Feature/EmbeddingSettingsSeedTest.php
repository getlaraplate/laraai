<?php

declare(strict_types=1);

use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchState;
use Modules\AI\Database\Seeders\AIDatabaseSeeder;
use Modules\Core\Models\Setting;

function embeddingSetting(string $name): Setting
{
    return Setting::query()->withoutGlobalScopes()->where('name', $name)->sole();
}

it('seeds the model, serving model and switch settings with the right managed flags', function (): void {
    config()->set('ai.servers.embeddings.url', 'http://localhost:8000');
    $this->seed(AIDatabaseSeeder::class);

    $model = embeddingSetting('features.embeddings.model');
    $serving = embeddingSetting('features.embeddings.serving_model');
    $switch = embeddingSetting('features.embeddings.switch');

    expect($model->group_name)->toBe('ai')
        ->and($model->managed)->toBeFalse()
        ->and($model->choices)->toBe(app(EmbeddingModelRegistry::class)->keys())
        ->and($model->value)->toBe('openai_compatible:intfloat/multilingual-e5-small')
        ->and($serving->group_name)->toBe('ai')
        ->and($serving->managed)->toBeTrue()
        ->and($serving->value)->toBe('openai_compatible:intfloat/multilingual-e5-small')
        ->and($switch->managed)->toBeTrue()
        ->and(json_decode((string) $switch->value, true)['status'])->toBe('idle')
        ->and(Setting::query()->withoutGlobalScopes()->where('name', 'features.embeddings.active')->exists())->toBeFalse()
        ->and(Setting::query()->withoutGlobalScopes()->where('name', 'search.vector.model')->exists())->toBeFalse();
});

it('leaves out the profiles whose provider is not configured', function (): void {
    config()->set('ai.servers.embeddings.url', '');
    config()->set('ai.providers.voyageai.api_key', 'k');
    config()->set('ai.features.embeddings.models', [
        'openai_compatible:intfloat/multilingual-e5-small' => ['dimensions' => 384],
        'voyageai:voyage-3-lite' => ['dimensions' => 512],
    ]);
    config()->set('ai.features.embeddings.serving_model', null);

    $definitions = collect(AIDatabaseSeeder::runtimeSettingDefinitions())->keyBy('name');

    expect($definitions['features.embeddings.model']['choices'])->toBe(['voyageai:voyage-3-lite'])
        ->and($definitions['features.embeddings.model']['value'])->toBe('voyageai:voyage-3-lite')
        ->and($definitions['features.embeddings.serving_model']['value'])->toBe('voyageai:voyage-3-lite');
});

it('always keeps the serving model among the choices', function (): void {
    config()->set('ai.servers.embeddings.url', '');
    config()->set('ai.providers.voyageai.api_key', 'k');
    config()->set('ai.features.embeddings.models', [
        'openai_compatible:intfloat/multilingual-e5-small' => ['dimensions' => 384],
        'voyageai:voyage-3-lite' => ['dimensions' => 512],
    ]);

    $choices = collect(AIDatabaseSeeder::runtimeSettingDefinitions('openai_compatible:intfloat/multilingual-e5-small'))->keyBy('name')['features.embeddings.model']['choices'];

    expect($choices)->toContain('openai_compatible:intfloat/multilingual-e5-small')
        ->and($choices)->toContain('voyageai:voyage-3-lite');
});

it('defaults both the serving model and the model choice to the first configured profile on a fresh install', function (): void {
    config()->set('ai.servers.embeddings.url', '');
    config()->set('ai.providers.voyageai.api_key', 'k');
    config()->set('ai.features.embeddings.models', [
        'openai_compatible:intfloat/multilingual-e5-small' => ['dimensions' => 384],
        'voyageai:voyage-3-lite' => ['dimensions' => 512],
    ]);

    $this->seed(AIDatabaseSeeder::class);

    expect(embeddingSetting('features.embeddings.serving_model')->value)->toBe('voyageai:voyage-3-lite')
        ->and(embeddingSetting('features.embeddings.model')->value)->toBe('voyageai:voyage-3-lite')
        ->and(embeddingSetting('features.embeddings.model')->choices)->toBe(['voyageai:voyage-3-lite']);
});

it('keeps the stored serving model on a second seed and defaults the model choice to it', function (): void {
    config()->set('ai.servers.embeddings.url', '');
    config()->set('ai.providers.voyageai.api_key', 'k');
    config()->set('ai.features.embeddings.models', [
        'openai_compatible:intfloat/multilingual-e5-small' => ['dimensions' => 384],
        'voyageai:voyage-3-lite' => ['dimensions' => 512],
    ]);
    $this->seed(AIDatabaseSeeder::class);

    Setting::writeManaged('features.embeddings.serving_model', 'openai_compatible:intfloat/multilingual-e5-small');
    Setting::query()->withoutGlobalScopes()->where('name', 'features.embeddings.model')->forceDelete();
    $this->seed(AIDatabaseSeeder::class);

    expect(embeddingSetting('features.embeddings.serving_model')->value)->toBe('openai_compatible:intfloat/multilingual-e5-small')
        ->and(embeddingSetting('features.embeddings.model')->value)->toBe('openai_compatible:intfloat/multilingual-e5-small')
        ->and(embeddingSetting('features.embeddings.model')->choices)->toBe(['voyageai:voyage-3-lite', 'openai_compatible:intfloat/multilingual-e5-small']);
});

it('does not overwrite a managed value on a re-seed', function (): void {
    config()->set('ai.servers.embeddings.url', 'http://localhost:8000');
    $this->seed(AIDatabaseSeeder::class);

    $running = new EmbeddingSwitchState('running', 'embeddings', 'openai_compatible:all-MiniLM-L6-v2', 'openai_compatible:intfloat/multilingual-e5-small');
    Setting::writeManaged('features.embeddings.switch', $running->toJson());
    $this->seed(AIDatabaseSeeder::class);

    expect(json_decode((string) embeddingSetting('features.embeddings.switch')->value, true)['status'])->toBe('running');
});

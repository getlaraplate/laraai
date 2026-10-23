<?php

declare(strict_types=1);

use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchState;
use Modules\AI\Database\Seeders\AIDatabaseSeeder;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Models\Setting;

function embeddingSetting(string $name): Setting
{
    return Setting::query()->withoutGlobalScopes()->where('name', $name)->sole();
}

it('seeds the model and switch settings with the right managed flags, and no copy of the serving model', function (): void {
    config()->set('ai.servers.embeddings.url', 'http://localhost:8000');
    $this->seed(AIDatabaseSeeder::class);

    $model = embeddingSetting('features.embeddings.model');
    $switch = embeddingSetting('features.embeddings.switch');

    expect($model->group_name)->toBe('ai')
        ->and($model->managed)->toBeFalse()
        ->and($model->choices)->toBe(app(EmbeddingModelRegistry::class)->keys())
        ->and($model->value)->toBe('openai_compatible:intfloat/multilingual-e5-small')
        ->and($switch->managed)->toBeTrue()
        ->and(json_decode((string) $switch->value, true)['status'])->toBe('idle')
        ->and(Setting::query()->withoutGlobalScopes()->where('name', 'features.embeddings.active')->exists())->toBeFalse()
        ->and(collect(AIDatabaseSeeder::runtimeSettingDefinitions())->pluck('name'))->not->toContain('features.embeddings.active');
});

it('leaves out the profiles whose provider is not configured', function (): void {
    config()->set('ai.servers.embeddings.url', '');
    config()->set('ai.providers.voyageai.api_key', 'k');
    config()->set('ai.features.embeddings.models', [
        'openai_compatible:intfloat/multilingual-e5-small' => ['dimensions' => 384],
        'voyageai:voyage-3-lite' => ['dimensions' => 512],
    ]);
    config()->set('core.search.vector.model', null);

    $definitions = collect(AIDatabaseSeeder::runtimeSettingDefinitions())->keyBy('name');

    expect($definitions['features.embeddings.model']['choices'])->toBe(['voyageai:voyage-3-lite'])
        ->and($definitions['features.embeddings.model']['value'])->toBe('voyageai:voyage-3-lite');
});

it('always keeps the serving model among the choices', function (): void {
    config()->set('ai.servers.embeddings.url', '');
    config()->set('ai.providers.voyageai.api_key', 'k');
    config()->set('ai.features.embeddings.models', [
        'openai_compatible:intfloat/multilingual-e5-small' => ['dimensions' => 384],
        'voyageai:voyage-3-lite' => ['dimensions' => 512],
    ]);
    config()->set('core.search.vector.model', 'openai_compatible:intfloat/multilingual-e5-small');

    $choices = collect(AIDatabaseSeeder::runtimeSettingDefinitions())->keyBy('name')['features.embeddings.model']['choices'];

    expect($choices)->toContain('openai_compatible:intfloat/multilingual-e5-small')
        ->and($choices)->toContain('voyageai:voyage-3-lite');
});

it('defaults the model choice to the Core serving model on a fresh install whose first configured profile differs', function (): void {
    config()->set('ai.servers.embeddings.url', '');
    config()->set('ai.providers.voyageai.api_key', 'k');
    config()->set('ai.features.embeddings.models', [
        'openai_compatible:intfloat/multilingual-e5-small' => ['dimensions' => 384],
        'voyageai:voyage-3-lite' => ['dimensions' => 512],
    ]);
    config()->set('core.search.vector.model', null);

    $this->seed(CoreDatabaseSeeder::class);
    $this->seed(AIDatabaseSeeder::class);

    $model = embeddingSetting('features.embeddings.model');

    expect(embeddingSetting('search.vector.model')->value)->toBe('openai_compatible:intfloat/multilingual-e5-small')
        ->and($model->value)->toBe('openai_compatible:intfloat/multilingual-e5-small')
        ->and($model->choices)->toBe(['voyageai:voyage-3-lite', 'openai_compatible:intfloat/multilingual-e5-small']);
});

it('does not overwrite a managed value on a re-seed', function (): void {
    config()->set('ai.servers.embeddings.url', 'http://localhost:8000');
    $this->seed(AIDatabaseSeeder::class);

    $running = new EmbeddingSwitchState('running', 'embeddings', 'openai_compatible:all-MiniLM-L6-v2', 'openai_compatible:intfloat/multilingual-e5-small');
    Setting::writeManaged('features.embeddings.switch', $running->toJson());
    $this->seed(AIDatabaseSeeder::class);

    expect(json_decode((string) embeddingSetting('features.embeddings.switch')->value, true)['status'])->toBe('running');
});

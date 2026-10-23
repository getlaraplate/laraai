<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Modules\AI\Database\Seeders\AIDatabaseSeeder;
use Modules\Core\Models\Setting;

beforeEach(function (): void {
    foreach (['openai', 'anthropic', 'mistral'] as $provider) {
        config()->set("ai.providers.{$provider}.api_key", '');
    }

    config()->set('core.deepl_api_key', '');
    config()->set('ai.servers.embeddings.url', '');
    config()->set('ai.servers.transcription.url', '');
    config()->set('ai.providers.ollama.api_url', 'http://ollama.test');

    $this->seed(AIDatabaseSeeder::class);
});

function refreshCommandSetting(string $name): Setting
{
    return Setting::query()->withoutGlobalScopes()->where('name', $name)->sole();
}

function fakeOllamaModels(array $names): void
{
    Http::fake([
        'ollama.test/api/tags' => Http::response(['models' => array_map(static fn (string $name): array => ['name' => $name], $names)]),
        'ollama.test/api/show' => Http::response(['capabilities' => ['completion', 'tools']]),
    ]);
}

it('refreshes every model setting from the providers', function (): void {
    fakeOllamaModels(['llama3.2:3b', 'qwen3:8b']);

    $this->artisan('ai:models:refresh')->assertExitCode(0);

    expect(refreshCommandSetting('features.chat.model')->choices)->toBe(['ollama:llama3.2:3b', 'ollama:qwen3:8b'])
        ->and(refreshCommandSetting('features.faq.model')->choices)->toBe(['ollama:llama3.2:3b', 'ollama:qwen3:8b']);
});

it('refreshes only the named setting', function (): void {
    fakeOllamaModels(['qwen3:8b']);
    $faq_before = refreshCommandSetting('features.faq.model')->choices;

    $this->artisan('ai:models:refresh', ['--setting' => 'features.chat.model'])->assertExitCode(0);

    expect(refreshCommandSetting('features.chat.model')->choices)->toBe(['ollama:qwen3:8b'])
        ->and(refreshCommandSetting('features.faq.model')->choices)->toBe($faq_before);
});

it('fails on a setting that is not a model setting and writes nothing', function (): void {
    Http::fake();
    $chat_before = refreshCommandSetting('features.chat.model')->choices;

    $this->artisan('ai:models:refresh', ['--setting' => 'features.faq.enabled'])->assertExitCode(1);

    expect(refreshCommandSetting('features.chat.model')->choices)->toBe($chat_before);
    Http::assertNothingSent();
});

it('fails when a provider fails but keeps its entries', function (): void {
    Setting::query()->withoutGlobalScopes()->where('name', 'features.chat.model')
        ->update(['choices' => json_encode(['ollama:llama3.2:3b'])]);
    Http::fake(['ollama.test/*' => Http::response([], 500)]);

    $this->artisan('ai:models:refresh', ['--setting' => 'features.chat.model'])->assertExitCode(1);

    expect(refreshCommandSetting('features.chat.model')->choices)->toBe(['ollama:llama3.2:3b']);
});

it('warns when the current value is no longer offered', function (): void {
    Setting::query()->withoutGlobalScopes()->where('name', 'features.chat.model')
        ->update(['value' => json_encode('ollama:gone:1b')]);
    fakeOllamaModels(['qwen3:8b']);

    $this->artisan('ai:models:refresh', ['--setting' => 'features.chat.model'])
        ->expectsOutputToContain('is no longer offered')
        ->assertExitCode(0);
});

it('is scheduled every night at 03:00', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(static fn ($event): bool => str_contains((string) $event->command, 'ai:models:refresh'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 3 * * *');
});

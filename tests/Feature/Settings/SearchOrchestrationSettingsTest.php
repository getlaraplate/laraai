<?php

declare(strict_types=1);

use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Database\Seeders\AIDatabaseSeeder;
use Modules\AI\Search\AiSearchStrategyResolver;
use Modules\AI\Services\LlmSearchService;
use Modules\Core\Models\Setting;
use Modules\Core\Search\Enums\SearchMode;
use Modules\Core\Services\DatabaseConfigOverlay;
use Modules\Core\Services\PerModelSettingResolver;
use Modules\Core\Services\SettingsCacheCoordinator;

/**
 * Stores an operator's value on a seeded AI setting and applies the overlay the way a request does.
 */
function ai_store_setting(string $name, mixed $value): void
{
    Setting::query()->withoutGlobalScopes()
        ->where('module', 'AI')->where('name', $name)
        ->update(['value' => json_encode($value)]);

    app(SettingsCacheCoordinator::class)->flushAll();
    app()->forgetScopedInstances();

    app(DatabaseConfigOverlay::class)->applyFromDatabase(app(PerModelSettingResolver::class));
}

/**
 * The seconds the search service asks of the agent it builds for one LLM call.
 */
function ai_search_agent_timeout(): ?float
{
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');

    $agent = (new ReflectionMethod(LlmSearchService::class, 'createAgent'))
        ->invoke(new LlmSearchService('ollama'), 'system');

    expect($agent)->toBeInstanceOf(ChatAgent::class);

    return (new ReflectionProperty($agent, 'timeout'))->getValue($agent);
}

beforeEach(function (): void {
    $this->seed(AIDatabaseSeeder::class);
});

it('serves deep and balanced searches as fast once the stored switch is off', function (): void {
    ai_store_setting('features.search_orchestration.enabled', false);

    $strategy = app(AiSearchStrategyResolver::class)->resolve(SearchMode::Deep);

    expect($strategy->applied_mode)->toBe(SearchMode::Fast)
        ->and($strategy->degraded_reason)->toBe('search_orchestration_disabled');
});

it('keeps the overlay out of the way while the switch is on', function (): void {
    ai_store_setting('features.search_orchestration.enabled', true);

    $strategy = app(AiSearchStrategyResolver::class)->resolve(SearchMode::Deep);

    expect($strategy->degraded_reason)->not->toBe('search_orchestration_disabled');
});

it('asks the agent for the stored timeout', function (): void {
    ai_store_setting('features.search_orchestration.timeout', 25);

    expect(ai_search_agent_timeout())->toBe(25.0);
});

it('asks for the seeded ten seconds before anyone changes the timeout', function (): void {
    expect(ai_search_agent_timeout())->toBe(10.0);
});

it('hands a stored zero to the agent unchanged, because curl reads it as no limit', function (): void {
    ai_store_setting('features.search_orchestration.timeout', 0);

    expect(ai_search_agent_timeout())->toBe(0.0);
});

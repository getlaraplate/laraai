<?php

declare(strict_types=1);

use Modules\AI\Services\Assistance\Policies\AssistantPolicyCatalog;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyCompiler;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyRuleSet;
use Modules\Core\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * The default catalog, with the grant of the in-app profile for the given tools removed and, unless it is
 * kept, the capability itself.
 *
 * @param  list<string>  $tools
 */
function catalogWithoutCapability(string $capability, array $tools, bool $keepCapability): AssistantPolicyCatalog
{
    $defaults = AssistantPolicyCatalog::defaults();
    $profile = $defaults->profiles['in_app_assistance'];
    $capabilities = $defaults->capabilities;

    if (! $keepCapability) {
        unset($capabilities[$capability]);
    }

    return new AssistantPolicyCatalog(
        version: $defaults->version,
        globalPolicy: $defaults->globalPolicy,
        profiles: [
            ...$defaults->profiles,
            'in_app_assistance' => new AssistantPolicyRuleSet(
                instruction: $profile->instruction,
                allowedCorpora: $profile->allowedCorpora,
                allowedTools: array_values(array_diff($profile->allowedTools, $tools)),
                allowedFields: $profile->allowedFields,
                deniedCorpora: $profile->deniedCorpora,
                deniedTools: $profile->deniedTools,
                deniedFields: $profile->deniedFields,
            ),
        ],
        capabilities: $capabilities,
        modules: $defaults->modules,
    );
}

function catalogWithoutProposals(bool $keepCapability): AssistantPolicyCatalog
{
    return catalogWithoutCapability('ui_proposals', ['propose_preference_change', 'propose_view_state'], $keepCapability);
}

function useCatalog(AssistantPolicyCatalog $catalog): void
{
    app()->instance(AssistantPolicyCatalog::class, $catalog);
    app()->instance(AssistantPolicyCompiler::class, new AssistantPolicyCompiler($catalog));
}

beforeEach(function (): void {
    $this->user = User::factory()->create();
    config([
        'ai.features.faq.enabled' => true,
        'ai.features.chat.model' => 'ollama:llama3.2:3b',
        'ai.providers.ollama.api_url' => 'http://localhost:11434',
    ]);
});

it('tells a signed-in user what the assistant offers', function (): void {
    $this->actingAs($this->user)
        ->getJson(route('ai.capabilities'))
        ->assertOk()
        ->assertJsonPath('data', [
            'enabled' => true,
            'configured' => true,
            'features' => ['help' => true, 'proposals' => true, 'writes' => false, 'streaming' => true, 'objects' => ['graph']],
            'limits' => ['aggregate' => ['max_measures' => 3, 'max_groups' => 50, 'max_points' => 400, 'max_period_days' => 1096]],
            'actions' => [],
        ]);
});

it('lists kpi, chart and graph in features.objects when an entity offers summarize, the capability is on and the policy allows graph_expand', function (): void {
    config(['ai.features.tools.crud.entities' => ['core.role' => ['list'], 'core.setting' => ['list', 'summarize']]]);

    $this->actingAs($this->user)
        ->getJson(route('ai.capabilities'))
        ->assertOk()
        ->assertJsonPath('data.features.objects', ['kpi', 'chart', 'graph']);
});

it('lists no object type when the capability is removed', function (Closure $catalog): void {
    config(['ai.features.tools.crud.entities' => ['core.setting' => ['summarize']]]);
    useCatalog($catalog());

    $this->actingAs($this->user)
        ->getJson(route('ai.capabilities'))
        ->assertOk()
        ->assertJsonPath('data.features.objects', []);
})->with([
    'the catalog lacks the capability' => [static fn (): AssistantPolicyCatalog => catalogWithoutCapability('widget_objects', ['show_widget'], keepCapability: false)],
    'the profile does not grant the tool' => [static fn (): AssistantPolicyCatalog => catalogWithoutCapability('widget_objects', ['show_widget'], keepCapability: true)],
]);

it('lists graph alone in features.objects when no entity offers summarize', function (): void {
    config(['ai.features.tools.crud.entities' => ['core.setting' => ['list', 'detail', 'update']]]);

    $this->actingAs($this->user)
        ->getJson(route('ai.capabilities'))
        ->assertOk()
        ->assertJsonPath('data.features.objects', ['graph']);
});

it('lists no graph in features.objects when the policy does not allow graph_expand', function (Closure $catalog, array $entities, array $expected): void {
    config(['ai.features.tools.crud.entities' => $entities]);
    useCatalog($catalog());

    $this->actingAs($this->user)
        ->getJson(route('ai.capabilities'))
        ->assertOk()
        ->assertJsonPath('data.features.objects', $expected);
})->with([
    'the catalog lacks read_only_graph' => [static fn (): AssistantPolicyCatalog => catalogWithoutCapability('read_only_graph', ['graph_expand'], keepCapability: false), ['core.setting' => ['summarize']], ['kpi', 'chart']],
    'the profile does not grant graph_expand' => [static fn (): AssistantPolicyCatalog => catalogWithoutCapability('read_only_graph', ['graph_expand'], keepCapability: true), ['core.setting' => ['summarize']], ['kpi', 'chart']],
    'and no entity offers summarize' => [static fn (): AssistantPolicyCatalog => catalogWithoutCapability('read_only_graph', ['graph_expand'], keepCapability: true), [], []],
]);

it('publishes the four aggregate limits as the configuration sets them', function (): void {
    config([
        'core.aggregate.max_measures' => 2,
        'core.aggregate.max_groups' => 12,
        'core.aggregate.max_points' => 90,
        'core.aggregate.max_period_days' => 366,
        'core.aggregate.max_objects_per_message' => 4,
        'core.aggregate.rate_per_minute' => 10,
    ]);

    $this->actingAs($this->user)
        ->getJson(route('ai.capabilities'))
        ->assertOk()
        ->assertJsonPath('data.limits', ['aggregate' => ['max_measures' => 2, 'max_groups' => 12, 'max_points' => 90, 'max_period_days' => 366]]);
});

it('is not enabled while the assistant feature is off', function (): void {
    config(['ai.features.chat.enabled' => false]);

    $this->actingAs($this->user)
        ->getJson(route('ai.capabilities'))
        ->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.configured', true);
});

it('is not configured while the chat provider lacks what it needs', function (string $model, array $settings): void {
    config(['ai.features.chat.model' => $model, ...$settings]);

    $this->actingAs($this->user)
        ->getJson(route('ai.capabilities'))
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.configured', false);
})->with([
    'ollama without a url' => ['ollama:llama3.2:3b', ['ai.providers.ollama.api_url' => '']],
    'openai without a key' => ['openai:gpt-4o-mini', ['ai.providers.openai.api_key' => '', 'ai.providers.openai.base_url' => '']],
    'an unsupported provider' => ['nowhere:model', []],
]);

it('is configured when the chat provider holds a key', function (): void {
    config(['ai.features.chat.model' => 'openai:gpt-4o-mini', 'ai.providers.openai.api_key' => 'sk-test-not-real']);

    $this->actingAs($this->user)
        ->getJson(route('ai.capabilities'))
        ->assertJsonPath('data.configured', true);
});

it('does not offer proposals when the catalog lacks the capability', function (): void {
    useCatalog(catalogWithoutProposals(keepCapability: false));

    $this->actingAs($this->user)
        ->getJson(route('ai.capabilities'))
        ->assertJsonPath('data.features.proposals', false);
});

it('does not offer proposals when the capability exists but the profile does not grant it', function (): void {
    useCatalog(catalogWithoutProposals(keepCapability: true));

    $this->actingAs($this->user)
        ->getJson(route('ai.capabilities'))
        ->assertJsonPath('data.features.proposals', false);
});

it('refuses a request that is not signed in', function (): void {
    $this->getJson(route('ai.capabilities'))->assertUnauthorized();
});

it('refuses the guest account', function (): void {
    $guest = User::factory()->create(['name' => config('permission.users.guest'), 'username' => config('permission.users.guest')]);

    $this->actingAs($guest)->getJson(route('ai.capabilities'))->assertForbidden();
});

it('answers the same whatever the query string says', function (): void {
    $this->actingAs($this->user)
        ->getJson(route('ai.capabilities', ['enabled' => 'false', 'features' => ['proposals' => true]]))
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.features.proposals', true);
});

it('tells the signed-in user what the assistant may do for them, and what then goes to a vote', function (): void {
    Modules\Core\Tests\Support\HttpContext::pretendHttpRequest();

    foreach (['select', 'update'] as $ability) {
        $name = Modules\Core\Support\PermissionName::forModel(Modules\Core\Models\DynamicEntity::resolve('setting', module: 'core'), $ability);
        Modules\Core\Models\Permission::findOrCreate($name, 'web');
        $this->user->givePermissionTo($name);
    }

    config([
        'ai.features.tools.crud.entities' => ['core.setting' => ['list', 'update', 'delete'], 'core.role' => ['list']],
        'ai.features.tools.crud.unmoderated_writes' => [],
    ]);

    $this->actingAs($this->user)
        ->getJson(route('ai.capabilities'))
        ->assertOk()
        ->assertJsonPath('data.features.writes', true)
        ->assertJsonPath('data.actions', [
            ['entity' => 'core.setting', 'operation' => 'list', 'kind' => 'read', 'requires_approval' => false],
            ['entity' => 'core.setting', 'operation' => 'update', 'kind' => 'write', 'requires_approval' => true],
        ]);
});

it('lists no action for a user who may do nothing the operator opted in', function (): void {
    config(['ai.features.tools.crud.entities' => ['core.setting' => ['list', 'update']]]);

    $this->actingAs($this->user)
        ->getJson(route('ai.capabilities'))
        ->assertJsonPath('data.actions', []);
});

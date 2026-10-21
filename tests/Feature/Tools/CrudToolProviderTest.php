<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\AssistantTenantScope;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Tools\CrudToolProvider;
use Modules\AI\Services\Tools\ToolDefinition;
use Modules\Core\Enums\AggregateErrorCode;
use Modules\Core\Exceptions\AggregateException;
use Modules\Core\Models\DynamicEntity;
use Modules\Core\Models\ImportSession;
use Modules\Core\Models\Permission;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Services\Crud\Aggregate\AggregateLimits;
use Modules\Core\Services\Crud\Aggregate\AggregateQuery;
use Modules\Core\Services\Crud\Aggregate\AggregateResult;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Support\PermissionName;

uses(RefreshDatabase::class);

function makeCrudToolProvider(object $user, ?CrudService $crud = null): CrudToolProvider
{
    $request = Request::create('/app/ai/assist', 'POST');
    $request->setUserResolver(fn (): object => $user);

    return new CrudToolProvider(
        $crud ?? resolve(CrudService::class),
        resolve(AuthorizationService::class),
        $request,
        resolve(Modules\Core\Services\Export\TabularCsvExporter::class),
        resolve(Modules\Core\Services\Export\TabularPdfExporter::class),
        resolve(Modules\AI\Services\Assistance\Writes\WriteProposalService::class),
    );
}

function inAppContext(object $user): AssistantAccessContext
{
    $conversation = Modules\AI\Models\Conversation::query()->create(['user_id' => $user->getKey()]);

    return new AssistantAccessContext(
        AssistantProfile::InAppAssistance,
        (string) $user->getAuthIdentifier(),
        AssistantTenantScope::Global,
        null,
        'en',
        [],
        (string) $conversation->getKey(),
    );
}

/**
 * Grant the acting user a CRUD permission on the core.setting entity.
 */
function grantSettingAbility(object $user, string $ability): void
{
    $model = DynamicEntity::resolve('setting', module: 'core');
    $name = PermissionName::forModel($model, $ability);
    Permission::factory()->create(['name' => $name, 'guard_name' => 'web']);
    $user->givePermissionTo($name);
}

/**
 * @param  list<ToolDefinition>  $tools
 */
function toolNames(array $tools): array
{
    return array_map(static fn (ToolDefinition $t): string => $t->name, $tools);
}

/**
 * @param  list<ToolDefinition>  $tools
 */
function findTool(array $tools, string $name): ?ToolDefinition
{
    foreach ($tools as $definition) {
        if ($definition->name === $name) {
            return $definition;
        }
    }

    return null;
}

beforeEach(function (): void {
    Config::set('ai.features.tools.crud.entities', []);
});

it('offers no tools for a non in-app profile', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['list']]);

    $context = new AssistantAccessContext(
        AssistantProfile::DeveloperHelp,
        null,
        null,
        null,
        'en',
        [],
        null,
    );

    expect(makeCrudToolProvider($user)->tools($context))->toBe([]);
});

it('offers no tools when the request user does not match the context', function (): void {
    $user = user_class()::factory()->create();
    $other = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['list']]);

    expect(makeCrudToolProvider($user)->tools(inAppContext($other)))->toBe([]);
});

it('offers no tools when the allowlist is empty', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');

    expect(makeCrudToolProvider($user)->tools(inAppContext($user)))->toBe([]);
});

it('offers no tools for a user without any permission on the entity', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['list', 'create', 'delete']]);

    expect(makeCrudToolProvider($user)->tools(inAppContext($user)))->toBe([]);
});

it('exposes only the operations the user is permitted to perform', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select'); // covers list/detail/search
    grantSettingAbility($user, 'insert'); // covers create
    // No 'forceDelete' → delete must not be exposed.
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['list', 'create', 'delete', 'bogus']]);

    $tools = makeCrudToolProvider($user)->tools(inAppContext($user));
    $names = toolNames($tools);

    expect($names)->toContain('crud_list_core_setting')
        ->toContain('crud_create_core_setting')
        ->and($names)->not->toContain('crud_delete_core_setting')
        ->and($names)->not->toContain('crud_bogus_core_setting')
        // Every exposed tool runs inline; moderation is handled by the model.
        ->and(findTool($tools, 'crud_create_core_setting')?->riskLevel)->toBe('low')
        ->and(findTool($tools, 'crud_list_core_setting')?->riskLevel)->toBe('low');
});

it('runs the read handler and returns data for a permitted user', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['list']]);

    $list = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_list_core_setting');

    /** @var array<string, mixed> $result */
    $result = ($list->handler)();

    expect($result)->toHaveKey('data')
        ->and($result)->not->toHaveKey('error');
});

it('echoes the executed filters and sort in the request block', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['list']]);

    $list = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_list_core_setting');

    $filters = [['property' => 'group_name', 'operator' => '=', 'value' => 'base']];
    $sort = [['property' => 'name', 'direction' => 'asc']];

    /** @var array<string, mixed> $result */
    $result = ($list->handler)(filters: $filters, sort: $sort, limit: 5);

    expect($result)->toHaveKey('request')
        ->and($result)->not->toHaveKey('error')
        ->and($result['request']['verb'])->toBe('list')
        ->and($result['request']['module'])->toBe('core')
        ->and($result['request']['entity'])->toBe('setting')
        ->and($result['request']['filters'])->toBe($filters)
        ->and($result['request']['sort'])->toBe($sort)
        ->and($result['request']['limit'])->toBe(5);
});

it('proposes a table view without fetching data (configure mode)', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['view']]);

    $view = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_view_core_setting');

    expect($view)->not->toBeNull()
        ->and($view->riskLevel)->toBe('low');

    $filters = [['property' => 'group_name', 'operator' => '=', 'value' => 'base']];

    /** @var array<string, mixed> $result */
    $result = ($view->handler)(filters: $filters, sort: [], limit: 10, page: 1);

    expect($result['apply'])->toBeTrue()
        ->and($result)->not->toHaveKey('data') // configure mode does not fetch
        ->and($result['request']['verb'])->toBe('view')
        ->and($result['request']['filters'])->toBe($filters)
        ->and($result['request']['limit'])->toBe(10)
        ->and($result['request']['page'])->toBe(1);
});

it('lists pending approvals only with the approve permission, and never offers a decision', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['pending_approvals', 'approve', 'disapprove']]);

    // Without the approve permission → no approval tools.
    expect(makeCrudToolProvider($user)->tools(inAppContext($user)))->toBe([]);

    grantSettingAbility($user, 'approve');
    $names = toolNames(makeCrudToolProvider($user)->tools(inAppContext($user)));

    // Holding `approve` is not enough for the model to vote: a decision is made by a person in the panel.
    expect($names)->toBe(['crud_pending_approvals_core_setting']);
});

it('offers writes on an entity with approvals without a second opt-in', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'update');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['update']]);
    Config::set('ai.features.tools.crud.unmoderated_writes', []);

    expect(toolNames(makeCrudToolProvider($user)->tools(inAppContext($user))))->toBe(['crud_update_core_setting']);
});

it('summarizes records with a group-by and a per-group count', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['summarize']]);

    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['id' => 10, 'name' => 's1', 'group_name' => 'alpha']);
    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['id' => 20, 'name' => 's2', 'group_name' => 'alpha']);
    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['id' => 30, 'name' => 's3', 'group_name' => 'beta']);

    $tool = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_summarize_core_setting');

    /** @var array<string, mixed> $result */
    $result = ($tool->handler)(group_by: ['group_name']);

    expect($result)->not->toHaveKey('error')
        ->and($result['request']['verb'])->toBe('summarize')
        ->and($result['request']['group_by'])->toBe(['group_name'])
        ->and($result['meta']['total_records'])->toBe(3)
        ->and($result['meta']['aggregated_rows'])->toBe(3)
        ->and($result['meta']['truncated'])->toBeFalse()
        ->and($result['total'])->toBe(['count' => 3, 'metrics' => []]);

    $byGroup = collect($result['data'])->keyBy(fn (array $b): string => $b['group']['group_name']);

    expect($byGroup['alpha'])->toBe(['group' => ['group_name' => 'alpha'], 'count' => 2, 'metrics' => []])
        ->and($byGroup['beta'])->toBe(['group' => ['group_name' => 'beta'], 'count' => 1, 'metrics' => []]);
});

it('summarizes numeric metrics over a column that is not a key', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantEntityAbility($user, 'import_session', 'select');
    Config::set('ai.features.tools.crud.entities', ['core.import_session' => ['summarize']]);

    ImportSession::factory()->create(['entity_key' => 'core.user', 'created_rows' => 10]);
    ImportSession::factory()->create(['entity_key' => 'core.user', 'created_rows' => 30]);
    ImportSession::factory()->create(['entity_key' => 'core.role', 'created_rows' => 5]);

    $tool = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_summarize_core_import_session');

    /** @var array<string, mixed> $sums */
    $sums = ($tool->handler)(group_by: ['entity_key'], metrics: [
        ['property' => 'created_rows', 'function' => 'sum'],
        ['property' => 'created_rows', 'function' => 'max'],
    ]);

    expect($sums)->not->toHaveKey('error')
        ->and($sums['request']['metrics'])->toBe([
            ['property' => 'created_rows', 'function' => 'sum'],
            ['property' => 'created_rows', 'function' => 'max'],
        ])
        ->and($sums['total']['count'])->toBe(3)
        ->and($sums['total']['metrics'])->toEqual(['sum(created_rows)' => 45, 'max(created_rows)' => 30]);

    $byGroup = collect($sums['data'])->keyBy(fn (array $b): string => $b['group']['entity_key']);

    expect($byGroup['core.user']['count'])->toBe(2)
        ->and($byGroup['core.user']['metrics'])->toEqual(['sum(created_rows)' => 40, 'max(created_rows)' => 30])
        ->and($byGroup['core.role']['count'])->toBe(1)
        ->and($byGroup['core.role']['metrics'])->toEqual(['sum(created_rows)' => 5, 'max(created_rows)' => 5]);

    /** @var array<string, mixed> $others */
    $others = ($tool->handler)(group_by: ['entity_key'], metrics: [
        ['property' => 'created_rows', 'function' => 'avg'],
        ['property' => 'created_rows', 'function' => 'min'],
    ]);

    $byGroup = collect($others['data'])->keyBy(fn (array $b): string => $b['group']['entity_key']);

    expect($others)->not->toHaveKey('error')
        ->and($byGroup['core.user']['metrics'])->toEqual(['avg(created_rows)' => 20, 'min(created_rows)' => 10]);
});

it('summarizes into one overall entry when no column is given, under the structured filters', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['summarize']]);

    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 's1', 'group_name' => 'alpha']);
    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 's2', 'group_name' => 'alpha']);
    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 's3', 'group_name' => 'beta']);

    $tool = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_summarize_core_setting');

    /** @var array<string, mixed> $result */
    $result = ($tool->handler)(filters: [['property' => 'group_name', 'operator' => '=', 'value' => 'alpha']]);

    expect($result)->not->toHaveKey('error')
        ->and($result['data'])->toBe([['group' => [], 'count' => 2, 'metrics' => []]])
        ->and($result['total'])->toBe(['count' => 2, 'metrics' => []])
        ->and($result['meta']['total_records'])->toBe(2);
});

it('summarizes without reading rows through the list', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['summarize']]);

    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 's1', 'group_name' => 'alpha']);
    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 's2', 'group_name' => 'beta']);

    $crud = Mockery::mock(resolve(CrudService::class));
    $crud->shouldReceive('list')->never();

    $tool = findTool(makeCrudToolProvider($user, $crud)->tools(inAppContext($user)), 'crud_summarize_core_setting');

    /** @var array<string, mixed> $result */
    $result = ($tool->handler)(group_by: ['group_name']);

    expect($result)->not->toHaveKey('error')
        ->and($result['meta']['total_records'])->toBe(2);
});

it('summarizes by time bucket over a period', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['summarize']]);

    foreach (['2026-01-05 09:00:00', '2026-01-20 18:30:00', '2026-03-02 11:00:00'] as $index => $createdAt) {
        Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 'bucket-' . $index, 'group_name' => 'alpha', 'created_at' => $createdAt]);
    }

    $tool = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_summarize_core_setting');

    $period = ['field' => 'created_at', 'from' => '2026-01-01', 'to' => '2026-03-31'];

    /** @var array<string, mixed> $result */
    $result = ($tool->handler)(group_by: ['created_at'], bucket: 'month', period: $period);

    expect($result)->not->toHaveKey('error')
        ->and($result['request']['bucket'])->toBe('month')
        ->and($result['request']['period'])->toBe($period)
        ->and($result['data'])->toBe([
            ['group' => ['created_at' => '2026-01-01'], 'count' => 2, 'metrics' => []],
            ['group' => ['created_at' => '2026-02-01'], 'count' => 0, 'metrics' => []],
            ['group' => ['created_at' => '2026-03-01'], 'count' => 1, 'metrics' => []],
        ])
        ->and($result['total']['count'])->toBe(3)
        ->and($result['meta']['total_records'])->toBe(3)
        ->and($result['meta']['truncated'])->toBeFalse();
});

it('summarizes a period without a group as one entry over the days it covers', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['summarize']]);

    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 'in', 'created_at' => '2026-01-05 09:00:00']);
    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 'out', 'created_at' => '2026-02-05 09:00:00']);

    $tool = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_summarize_core_setting');

    /** @var array<string, mixed> $result */
    $result = ($tool->handler)(period: ['field' => 'created_at', 'from' => '2026-01-01', 'to' => '2026-01-31']);

    expect($result)->not->toHaveKey('error')
        ->and($result['data'])->toBe([['group' => [], 'count' => 1, 'metrics' => []]]);
});

it('rejects more than one group_by column with the tool error shape', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['summarize']]);

    $tool = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_summarize_core_setting');

    /** @var array<string, mixed> $result */
    $result = ($tool->handler)(group_by: ['group_name', 'module']);

    expect($result)->toHaveKeys(['request', 'error'])
        ->and($result)->not->toHaveKey('data')
        ->and($result['request']['group_by'])->toBe(['group_name', 'module'])
        ->and($result['error'])->toContain('one');
});

it('refuses a bucket that has no column to apply to', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['summarize']]);

    $tool = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_summarize_core_setting');

    /** @var array<string, mixed> $result */
    $result = ($tool->handler)(bucket: 'month');

    expect($result)->toHaveKeys(['request', 'error'])
        ->and($result)->not->toHaveKey('data')
        ->and($result['error'])->toContain('group_by');
});

it('reports NOT_MEASURABLE for a sum over a key in the error shape', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['summarize']]);

    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 's1']);

    $tool = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_summarize_core_setting');

    /** @var array<string, mixed> $result */
    $result = ($tool->handler)(group_by: ['group_name'], metrics: [['property' => 'id', 'function' => 'sum']]);

    expect($result)->toHaveKeys(['request', 'error', 'code', 'field'])
        ->and($result)->not->toHaveKey('data')
        ->and($result['code'])->toBe('NOT_MEASURABLE')
        ->and($result['field'])->toBe('measures.1.field')
        ->and($result['error'])->toContain('"id"')
        ->and($result['request']['metrics'])->toBe([['property' => 'id', 'function' => 'sum']]);
});

it('reports a request the validator refuses with its code and field', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['summarize']]);

    $tool = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_summarize_core_setting');

    /** @var array<string, mixed> $result */
    $result = ($tool->handler)(group_by: ['created_at'], bucket: 'fortnight', period: ['field' => 'created_at', 'last' => ['n' => 3, 'unit' => 'month']]);

    expect($result)->toHaveKeys(['request', 'error', 'code', 'field'])
        ->and($result['code'])->toBe('INVALID_FIELD')
        ->and($result['field'])->toBe('group_by.bucket');
});

it('hands the model the total, the rest of the groups, the currency and the warnings of the engine', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['summarize']]);

    $warning = ['code' => 'MIXED_CURRENCY', 'field' => 'sum_value', 'message' => '"sum_value" adds amounts in more than one currency, so it has no value.'];
    $crud = Mockery::mock(resolve(CrudService::class));
    $crud->shouldReceive('aggregate')->once()->andReturn(new AggregateResult(
        series: [
            ['key' => 'alpha', 'label' => null, 'values' => ['count' => 2, 'sum_value' => 30.5]],
            ['key' => null, 'label' => null, 'values' => ['count' => 1, 'sum_value' => null]],
        ],
        total: ['count' => 5, 'sum_value' => 30.5],
        other: ['count' => 2, 'sum_value' => null, 'groups' => 1],
        compare: null,
        meta: ['row_count' => 5, 'group_count' => 3, 'truncated' => true, 'currency' => 'EUR', 'warnings' => [$warning], 'generated_at' => '2026-10-21T08:30:00Z'],
    ));

    $tool = findTool(makeCrudToolProvider($user, $crud)->tools(inAppContext($user)), 'crud_summarize_core_setting');

    /** @var array<string, mixed> $result */
    $result = ($tool->handler)(group_by: ['group_name'], metrics: [['property' => 'value', 'function' => 'sum']]);

    expect($result['data'])->toBe([
        ['group' => ['group_name' => 'alpha'], 'count' => 2, 'metrics' => ['sum(value)' => 30.5]],
        ['group' => ['group_name' => null], 'count' => 1, 'metrics' => ['sum(value)' => null]],
    ])
        ->and($result['total'])->toBe(['count' => 5, 'metrics' => ['sum(value)' => 30.5]])
        ->and($result['other'])->toBe(['count' => 2, 'metrics' => ['sum(value)' => null], 'groups' => 1])
        ->and($result['meta'])->toBe([
            'total_records' => 5,
            'aggregated_rows' => 5,
            'truncated' => true,
            'currency' => 'EUR',
            'warnings' => [$warning],
        ]);
});

it('runs an aggregate query for the acting user under the structured filters', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');

    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 's1', 'group_name' => 'alpha']);
    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 's2', 'group_name' => 'alpha']);
    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 's3', 'group_name' => 'beta']);

    $query = AggregateQuery::fromArray(['measures' => [['fn' => 'count']], 'group_by' => ['field' => 'group_name']], AggregateLimits::fromConfig());

    $result = makeCrudToolProvider($user)->aggregate('core', 'setting', [['property' => 'group_name', 'operator' => '=', 'value' => 'alpha']], $query);

    expect($result)->toBeInstanceOf(AggregateResult::class)
        ->and($result->total)->toBe(['count' => 2])
        ->and($result->series)->toBe([['key' => 'alpha', 'label' => null, 'values' => ['count' => 2]]]);
});

it('lets the refusal of the engine through when it is asked for an aggregate directly', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');

    $query = AggregateQuery::fromArray(['measures' => [['fn' => 'sum', 'field' => 'id']]], AggregateLimits::fromConfig());
    $provider = makeCrudToolProvider($user);

    expect(fn (): AggregateResult => $provider->aggregate('core', 'setting', [], $query))
        ->toThrow(fn (AggregateException $exception): bool => $exception->errorCode === AggregateErrorCode::NotMeasurable);
});

it('describes summarize with one group_by column, an optional bucket and an optional period', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['summarize']]);

    $tool = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_summarize_core_setting');
    $parameters = collect($tool->parameters)->keyBy('name');

    expect($parameters->keys()->all())->toBe(['filters', 'group_by', 'metrics', 'bucket', 'period'])
        ->and($parameters['group_by']['maxItems'])->toBe(1)
        ->and($parameters['group_by']['description'])->toContain('one')
        ->and($parameters['bucket']['type'])->toBe('string')
        ->and($parameters['bucket']['enum'])->toBe(['day', 'week', 'month', 'quarter', 'year'])
        ->and($parameters['bucket']['required'])->toBeFalse()
        ->and($parameters['period']['type'])->toBe('object')
        ->and($parameters['period']['required'])->toBeFalse()
        ->and($tool->description)->toContain('one column')
        ->and($tool->description)->toContain('period');
});

it('exports records to a csv file with the selected columns', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['export']]);

    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 'alpha', 'group_name' => 'g1']);
    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 'beta', 'group_name' => 'g2']);

    $tool = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_export_core_setting');

    /** @var array<string, mixed> $result */
    $result = ($tool->handler)(format: 'csv', columns: ['name', 'group_name']);

    expect($result)->not->toHaveKey('error')
        ->and($result['request']['verb'])->toBe('export')
        ->and($result['request']['format'])->toBe('csv')
        ->and($result['request']['columns'])->toBe(['name', 'group_name'])
        ->and($result['file']['mime'])->toBe('text/csv')
        ->and($result['file']['encoding'])->toBe('base64')
        ->and($result['file']['filename'])->toBe('core_setting_export.csv')
        ->and($result['meta']['exported_rows'])->toBe(2)
        ->and($result['meta']['truncated'])->toBeFalse();

    $csv = base64_decode($result['file']['contents'], true);

    expect($csv)->toContain('name,group_name')
        ->and($csv)->toContain('alpha,g1')
        ->and($csv)->toContain('beta,g2');
});

it('exports records to a pdf file', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['export']]);

    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 'alpha', 'group_name' => 'g1']);

    $tool = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_export_core_setting');

    /** @var array<string, mixed> $result */
    $result = ($tool->handler)(format: 'pdf', columns: ['name']);

    expect($result)->not->toHaveKey('error')
        ->and($result['file']['mime'])->toBe('application/pdf')
        ->and($result['file']['filename'])->toBe('core_setting_export.pdf');

    $pdf = base64_decode($result['file']['contents'], true);

    expect($pdf)->toStartWith('%PDF-1.4');
});

it('exposes bulk tools only with select plus the matching write permission', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'update'); // write ability, but no select yet
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['bulk_update', 'bulk_delete']]);

    // Without select, bulk cannot resolve the affected set → not offered.
    expect(toolNames(makeCrudToolProvider($user)->tools(inAppContext($user))))->toBe([]);

    grantSettingAbility($user, 'select'); // now bulk_update is fully permitted
    $names = toolNames(makeCrudToolProvider($user)->tools(inAppContext($user)));

    expect($names)->toContain('crud_bulk_update_core_setting')
        ->and($names)->not->toContain('crud_bulk_delete_core_setting'); // no forceDelete
});

it('proposes a bulk update without changing anything and lists what matched', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    grantSettingAbility($user, 'update');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['bulk_update']]);

    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 'b1', 'group_name' => 'bulk', 'description' => 'orig']);
    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 'b2', 'group_name' => 'bulk', 'description' => 'orig']);

    $tool = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_bulk_update_core_setting');

    /** @var array<string, mixed> $result */
    $result = ($tool->handler)(
        filters: [['property' => 'group_name', 'operator' => '=', 'value' => 'bulk']],
        attributes: ['description' => 'changed'],
    );

    expect($result['status'])->toBe('awaiting_confirmation')
        ->and($result['summary']['matched_records'])->toBe(2)
        ->and($result['summary']['changes'])->toBe(['description' => 'changed'])
        ->and($result['message'])->toContain('Nothing has been changed');

    expect(Modules\Core\Models\Setting::where('group_name', 'bulk')->where('description', 'orig')->count())->toBe(2);
});

it('refuses a bulk operation without filters', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    grantSettingAbility($user, 'forceDelete');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['bulk_delete']]);

    $tool = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_bulk_delete_core_setting');

    /** @var array<string, mixed> $result */
    $result = ($tool->handler)();

    expect($result)->toHaveKey('error')
        ->and($result['error'])->toContain('at least one filter');
});

it('lists pending approvals and echoes the author filter', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'approve');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['pending_approvals']]);

    $tool = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_pending_approvals_core_setting');

    /** @var array<string, mixed> $result */
    $result = ($tool->handler)(author: 'Marco');

    expect($result)->not->toHaveKey('error')
        ->and($result['request']['verb'])->toBe('pending_approvals')
        ->and($result['request']['author'])->toBe('Marco')
        ->and($result['data'])->toBeArray();
});

function grantEntityAbility(object $user, string $entity, string $ability): void
{
    $name = PermissionName::forModel(DynamicEntity::resolve($entity, module: 'core'), $ability);
    Permission::findOrCreate($name, 'web');
    $user->givePermissionTo($name);
}

it('offers no write on an entity without approvals unless the operator listed it, and still offers its reads', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);

    foreach (['select', 'update', 'insert', 'forceDelete'] as $ability) {
        grantEntityAbility($user, 'template', $ability);
    }

    Config::set('ai.features.tools.crud.entities', ['core.template' => ['list', 'create', 'update', 'delete', 'bulk_update', 'bulk_delete']]);
    Config::set('ai.features.tools.crud.unmoderated_writes', []);

    expect(toolNames(makeCrudToolProvider($user)->tools(inAppContext($user))))->toBe(['crud_list_core_template']);

    Config::set('ai.features.tools.crud.unmoderated_writes', ['core.template']);

    expect(toolNames(makeCrudToolProvider($user)->tools(inAppContext($user))))->toBe([
        'crud_list_core_template',
        'crud_create_core_template',
        'crud_update_core_template',
        'crud_delete_core_template',
        'crud_bulk_update_core_template',
        'crud_bulk_delete_core_template',
    ]);
});

it('matches the unmoderated opt-in case-insensitively and ignores a malformed value', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantEntityAbility($user, 'template', 'update');
    Config::set('ai.features.tools.crud.entities', ['core.template' => ['update']]);

    Config::set('ai.features.tools.crud.unmoderated_writes', ['Core.Template']);
    expect(toolNames(makeCrudToolProvider($user)->tools(inAppContext($user))))->toBe(['crud_update_core_template']);

    Config::set('ai.features.tools.crud.unmoderated_writes', 'core.template');
    expect(makeCrudToolProvider($user)->tools(inAppContext($user)))->toBe([]);
});

it('still requires the ability of the user for an opted-in unmoderated write', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    Config::set('ai.features.tools.crud.entities', ['core.template' => ['update']]);
    Config::set('ai.features.tools.crud.unmoderated_writes', ['core.template']);

    expect(makeCrudToolProvider($user)->tools(inAppContext($user)))->toBe([]);
});

it('fetches a record through the detail tool for a permitted user', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['detail']]);
    $setting = Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 'detail-me']);

    $detail = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_detail_core_setting');

    /** @var array<string, mixed> $result */
    $result = ($detail->handler)(id: (string) $setting->getKey());

    expect($result)->not->toHaveKey('error')
        ->and($result['data']['name'])->toBe('detail-me');
});

it('does not fetch a record through the detail tool for a user who may not read it', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['detail']]);
    $setting = Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 'hidden']);
    $detail = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_detail_core_setting');

    $user->revokePermissionTo(PermissionName::forModel(DynamicEntity::resolve('setting', module: 'core'), 'select'));

    /** @var array<string, mixed> $result */
    $result = ($detail->handler)(id: (string) $setting->getKey());

    expect($result)->toHaveKey('error')
        ->and($result)->not->toHaveKey('data');
});

it('refuses through the list tool a scope the entity exposes only to ACLs, without naming the model class', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    $model = DynamicEntity::resolve('cron_job', module: 'core');
    $name = PermissionName::forModel($model, 'select');
    Permission::factory()->create(['name' => $name, 'guard_name' => 'web']);
    $user->givePermissionTo($name);
    Config::set('ai.features.tools.crud.entities', ['core.cron_job' => ['list']]);

    $list = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_list_core_cron_job');

    /** @var array<string, mixed> $result */
    $result = ($list->handler)(filters: [['scope' => 'active']]);

    expect($result)->toHaveKey('error')
        ->and($result)->not->toHaveKey('data')
        ->and($result['error'])->toContain('"active"')
        ->and($result['error'])->not->toContain('Modules\\');
});

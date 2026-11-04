<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\Message;
use Modules\AI\Services\Assistance\Contracts\InAppAssistanceServiceInterface;
use Modules\AI\Services\Assistance\InAppAssistanceService;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyCatalog;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyCompiler;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyRuleSet;
use Modules\AI\Services\Tools\CrudToolProvider;
use Modules\AI\Tests\Stubs\Assistance\ScriptedAssistantFixtures;
use Modules\Core\Graph\Contracts\GraphToolGatewayInterface;
use Modules\Core\Models\DynamicEntity;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Support\PermissionName;
use NeuronAI\Tools\Tool;

/**
 * A user who may read the settings, the entity these tests opt into summaries.
 */
function widgetFlowReader(): User
{
    $user = User::factory()->create();
    widgetFlowGrant($user, 'setting', 'select');

    return $user;
}

/**
 * Gives the user one ability on a core entity.
 */
function widgetFlowGrant(User $user, string $entity, string $ability): void
{
    $name = PermissionName::forModel(DynamicEntity::resolve($entity, module: 'core'), $ability);
    Permission::findOrCreate($name, 'web');
    $user->givePermissionTo($name);
}

/**
 * The request of a page that declares the object types it renders, as the HTTP kernel binds it: the tools
 * the service builds for the turn read the same request.
 *
 * @param  mixed  $objects  what the page sends in `context.page.objects`; null sends no such key
 * @param  list<array<string, mixed>>  $proposable
 */
function widgetFlowRequest(User $user, mixed $objects, array $proposable = []): Request
{
    $page = [
        ...$objects === null ? [] : ['objects' => $objects],
        ...$proposable === [] ? [] : ['proposable' => $proposable],
    ];
    $request = Request::create('/app/ai/assistance', 'POST', ['message' => 'hello', 'context' => ['page' => $page]]);
    app()->instance('request', $request);
    $request->setUserResolver(fn (): User => $user);

    return $request;
}

/**
 * The names of the tools the model is offered in a turn of the given request.
 *
 * @return list<string>
 */
function widgetFlowOfferedTools(Request $request, Conversation $conversation, User $user, ?AssistantPolicyCompiler $compiler = null): array
{
    $names = [];

    ScriptedAssistantFixtures::inAppService(
        $request,
        function (string $input, string $system, mixed $context, array $tools) use (&$names): string {
            $names = array_map(static fn (Tool $tool): string => $tool->getName(), $tools);

            return 'There are a few settings.';
        },
        compiler: $compiler,
    )->respond($conversation, $user, 'How many settings are there?');

    return $names;
}

/**
 * `show_widget` as the model is offered it in a turn of the given request, or null when the tool is not offered.
 */
function widgetFlowOfferedWidgetTool(Request $request, Conversation $conversation, User $user, ?AssistantPolicyCompiler $compiler = null): ?Tool
{
    $offered = null;

    ScriptedAssistantFixtures::inAppService(
        $request,
        function (string $input, string $system, mixed $context, array $tools) use (&$offered): string {
            $offered = collect($tools)->first(static fn (Tool $tool): bool => $tool->getName() === 'show_widget');

            return 'There are a few settings.';
        },
        compiler: $compiler,
    )->respond($conversation, $user, 'How are the roles of Alice related?');

    return $offered;
}

/**
 * The JSON schema of one parameter of an offered tool.
 *
 * @return array<string, mixed>
 */
function widgetFlowParameter(Tool $tool, string $name): array
{
    return collect($tool->getProperties())->first(static fn (mixed $property): bool => $name === $property->getName())->getJsonSchema();
}

/**
 * The arguments of a graph of the roles of one user, as the model writes them.
 *
 * @return array<string, string>
 */
function widgetFlowGraph(): array
{
    return ['type' => 'graph', 'title' => 'Roles of Alice', 'request' => '{"resource":"core/users","id":7,"relations":["roles"]}'];
}

/**
 * What the model does when it calls a tool it was offered: the text it reads back, an answer of the tool or the
 * feedback on an argument the schema refused.
 *
 * @param  list<Tool>  $tools
 * @param  array<string, mixed>  $inputs
 */
function widgetFlowToolCall(array $tools, string $name, array $inputs): string
{
    foreach ($tools as $tool) {
        if ($name === $tool->getName()) {
            $tool->setInputs($inputs)->execute();

            return (string) $tool->getResult();
        }
    }

    throw new RuntimeException("The model was not offered the tool {$name}.");
}

/**
 * The arguments of a kpi counting the settings, as the model writes them.
 *
 * @return array<string, string>
 */
function widgetFlowKpi(): array
{
    return ['type' => 'kpi', 'title' => 'Settings', 'request' => '{"resource":"core/setting","measures":[{"fn":"count"}]}'];
}

/**
 * The arguments of a bar chart of the settings by group, as the model writes them.
 *
 * @return array<string, string>
 */
function widgetFlowChart(): array
{
    return [
        'type' => 'chart',
        'title' => 'Settings by group',
        'variant' => 'bar',
        'request' => '{"resource":"core/setting","measures":[{"fn":"count"}],"group_by":{"field":"group_name"},"limit":5}',
    ];
}

/**
 * The default policy, with the given tools taken from what the in-app profile allows.
 *
 * @param  list<string>  $tools
 */
function widgetFlowCompilerWithout(array $tools): AssistantPolicyCompiler
{
    $defaults = AssistantPolicyCatalog::defaults();
    $profile = $defaults->profiles['in_app_assistance'];

    return new AssistantPolicyCompiler(new AssistantPolicyCatalog(
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
        capabilities: $defaults->capabilities,
        modules: $defaults->modules,
    ));
}

beforeEach(function (): void {
    config(['ai.features.tools.crud.entities' => ['core.setting' => ['summarize']]]);
    $this->user = widgetFlowReader();
    $this->conversation = Conversation::query()->create(['user_id' => $this->user->id, 'system_message' => null]);
});

it('offers show_widget only when the page declares an object type and an entity offers summarize', function (mixed $objects, array $entities, array $expected): void {
    config(['ai.features.tools.crud.entities' => $entities]);

    $names = widgetFlowOfferedTools(widgetFlowRequest($this->user, $objects), $this->conversation, $this->user);

    expect(array_values(array_intersect($names, ['show_widget'])))->toBe($expected);
})->with([
    'kpi and chart, an entity offers summarize' => [['kpi', 'chart'], ['core.setting' => ['summarize']], ['show_widget']],
    'one type, summarize among other operations' => [['chart'], ['core.setting' => ['list', 'summarize']], ['show_widget']],
    'summarize written in capitals' => [['kpi'], ['core.setting' => ['SUMMARIZE']], ['show_widget']],
    'an empty list' => [[], ['core.setting' => ['summarize']], []],
    'no objects key' => [null, ['core.setting' => ['summarize']], []],
    'only types the backend does not know' => [['table', 'map'], ['core.setting' => ['summarize']], []],
    'only graph, which the policy lets the tool show' => [['graph'], ['core.setting' => ['summarize']], ['show_widget']],
    'only graph, and no entity offers summarize' => [['graph'], [], ['show_widget']],
    'graph beside a type the tool shows' => [['graph', 'kpi'], ['core.setting' => ['summarize']], ['show_widget']],
    'a text instead of a list' => ['kpi', ['core.setting' => ['summarize']], []],
    'no entity offers summarize' => [['kpi', 'chart'], ['core.setting' => ['list', 'detail']], []],
    'kpi and graph, and no entity offers summarize' => [['kpi', 'graph'], [], ['show_widget']],
    'no entity opted in' => [['kpi', 'chart'], [], []],
]);

it('offers show_widget only to a user who may summarize one of the entities opted into summarize', function (bool $reads, array $expected): void {
    $user = User::factory()->create();

    if ($reads) {
        widgetFlowGrant($user, 'setting', 'select');
    }

    $conversation = Conversation::query()->create(['user_id' => $user->id, 'system_message' => null]);

    $names = widgetFlowOfferedTools(widgetFlowRequest($user, ['kpi', 'chart']), $conversation, $user);

    expect(array_values(array_intersect($names, ['show_widget'])))->toBe($expected);
})->with([
    'a user who may read the entity' => [true, ['show_widget']],
    'a user who may read no entity opted into summarize' => [false, []],
]);

it('offers no show_widget when the policy of the in-app profile does not allow it', function (): void {
    $names = widgetFlowOfferedTools(widgetFlowRequest($this->user, ['kpi', 'chart']), $this->conversation, $this->user, widgetFlowCompilerWithout(['show_widget']));

    expect($names)->not->toContain('show_widget');
});

it('offers show_widget for a graph only when the policy of the in-app profile allows graph_expand', function (array $objects, array $expected): void {
    $names = widgetFlowOfferedTools(widgetFlowRequest($this->user, $objects), $this->conversation, $this->user, widgetFlowCompilerWithout(['graph_expand']));

    expect(array_values(array_intersect($names, ['show_widget'])))->toBe($expected);
})->with([
    'only graph' => [['graph'], []],
    'only chart, as before' => [['chart'], ['show_widget']],
    'graph beside kpi' => [['graph', 'kpi'], ['show_widget']],
]);

it('offers the model exactly the types it can use in the turn, and describes only those', function (array $objects, array $entities, ?Closure $compiler, array $expected): void {
    config(['ai.features.tools.crud.entities' => $entities]);

    $tool = widgetFlowOfferedWidgetTool(widgetFlowRequest($this->user, $objects), $this->conversation, $this->user, $compiler instanceof Closure ? $compiler() : null);
    $request = widgetFlowParameter($tool, 'request')['description'];
    $graphs = in_array('graph', $expected, true);
    $figures = array_intersect($expected, ['kpi', 'chart']) !== [];

    expect(widgetFlowParameter($tool, 'type')['enum'])->toBe($expected)
        ->and(str_contains($request, '"relations"'))->toBe($graphs)
        ->and(str_contains($request, '"measures"'))->toBe($figures)
        ->and(str_contains($tool->getDescription(), 'graph'))->toBe($graphs)
        ->and(str_contains($tool->getDescription(), 'summarize'))->toBe($figures);
})->with([
    'kpi and chart, graph_expand allowed' => [['kpi', 'chart'], ['core.setting' => ['summarize']], null, ['kpi', 'chart']],
    'only graph, no entity offers summarize' => [['graph'], [], null, ['graph']],
    'kpi and graph, no entity offers summarize' => [['kpi', 'graph'], [], null, ['graph']],
    'kpi and graph, graph_expand not allowed' => [['kpi', 'graph'], ['core.setting' => ['summarize']], fn (): AssistantPolicyCompiler => widgetFlowCompilerWithout(['graph_expand']), ['kpi']],
    'every type, everything allowed' => [['kpi', 'chart', 'graph'], ['core.setting' => ['summarize']], null, ['kpi', 'chart', 'graph']],
]);

it('puts a graph object in message.metadata.objects, expanded through the graph gateway, without any entity offering summarize', function (): void {
    config(['ai.features.tools.crud.entities' => []]);
    $gateway = Mockery::mock(GraphToolGatewayInterface::class);
    $gateway->shouldReceive('expand')->once()->andReturn(['available' => true, 'nodes' => [['id' => 'core:users:7', 'module' => 'core', 'entity' => 'users', 'label' => 'Alice Example', 'attributes' => []]], 'edges' => [], 'truncated' => false]);
    app()->instance(GraphToolGatewayInterface::class, $gateway);
    $answer = null;

    $reply = ScriptedAssistantFixtures::inAppService(
        widgetFlowRequest($this->user, ['graph']),
        function (string $input, string $system, mixed $context, array $tools) use (&$answer): string {
            $answer = widgetFlowToolCall($tools, 'show_widget', widgetFlowGraph());

            return 'Here is how the roles of Alice relate.';
        },
    )->respond($this->conversation, $this->user, 'How are the roles of Alice related?');

    $objects = $reply->metadata['objects'];

    expect($answer)->toStartWith('Widget recorded.')->not->toContain('Alice Example');
    expect($objects)->toHaveCount(1)
        ->and($objects[0]['id'])->toBeUuid()
        ->and($objects[0])->toBe([
            'id' => $objects[0]['id'],
            'type' => 'graph',
            'title' => 'Roles of Alice',
            'ref' => ['request' => ['kind' => 'graph', 'resource' => 'core/users', 'id' => 7, 'relations' => ['roles'], 'depth' => 1, 'limit' => 25, 'relation_limit' => 10]],
        ]);
});

it('puts each object the model shows through the tool in message.metadata.objects, with its id, type, title and ref', function (): void {
    $answers = [];

    $reply = ScriptedAssistantFixtures::inAppService(
        widgetFlowRequest($this->user, ['kpi', 'chart']),
        function (string $input, string $system, mixed $context, array $tools) use (&$answers): string {
            $answers[] = widgetFlowToolCall($tools, 'show_widget', widgetFlowKpi());
            $answers[] = widgetFlowToolCall($tools, 'show_widget', widgetFlowChart());

            return 'Here is how many settings there are, and how they split by group.';
        },
    )->respond($this->conversation, $this->user, 'How many settings are there?');

    $objects = $reply->metadata['objects'];

    expect($answers)->each->toStartWith('Widget recorded.');
    expect($objects)->toHaveCount(2)
        ->and($objects[0]['id'])->toBeUuid()
        ->and($objects[0])->toBe([
            'id' => $objects[0]['id'],
            'type' => 'kpi',
            'title' => 'Settings',
            'ref' => ['request' => ['kind' => 'aggregate', 'resource' => 'core/setting', 'measures' => [['fn' => 'count']], 'sort' => ['by' => 'measure', 'dir' => 'desc']]],
        ])
        ->and($objects[1]['id'])->toBeUuid()->not->toBe($objects[0]['id'])
        ->and($objects[1])->toBe([
            'id' => $objects[1]['id'],
            'type' => 'chart',
            'title' => 'Settings by group',
            'ref' => [
                'request' => [
                    'kind' => 'aggregate',
                    'resource' => 'core/setting',
                    'measures' => [['fn' => 'count']],
                    'group_by' => ['field' => 'group_name'],
                    'sort' => ['by' => 'measure', 'dir' => 'desc'],
                    'limit' => 5,
                ],
                'variant' => 'bar',
            ],
        ]);
    expect($reply->fresh()->metadata['objects'])->toBe($objects)
        ->and($reply->content)->toBe('Here is how many settings there are, and how they split by group.');
});

it('keeps the objects after the proposals and the writes of the same message', function (): void {
    config([
        'ai.features.tools.crud.entities' => ['core.setting' => ['summarize'], 'core.role' => ['update']],
        'ai.features.tools.crud.unmoderated_writes' => ['core.role'],
    ]);
    widgetFlowGrant($this->user, 'role', 'select');
    widgetFlowGrant($this->user, 'role', 'update');
    $role = Role::factory()->create(['name' => 'before']);
    $layout = [
        'kind' => 'preference',
        'target' => ['namespace' => 'ui', 'key' => 'defaultListLayout'],
        'schema' => ['type' => 'string', 'enum' => ['table', 'cards']],
        'current' => 'table',
        'description' => 'Default layout of lists',
    ];

    $reply = ScriptedAssistantFixtures::inAppService(
        widgetFlowRequest($this->user, ['kpi'], [$layout]),
        function (string $input, string $system, mixed $context, array $tools) use ($role): string {
            widgetFlowToolCall($tools, 'show_widget', widgetFlowKpi());
            expect(widgetFlowToolCall($tools, 'crud_update_core_role', ['id' => (string) $role->getKey(), 'attributes' => ['name' => 'after']]))->not->toContain('error');
            widgetFlowToolCall($tools, 'propose_preference_change', ['namespace' => 'ui', 'key' => 'defaultListLayout', 'proposed' => '"cards"', 'reason' => 'Cards read better on a phone.']);

            return 'Here is the count. I also propose renaming the role and suggest cards: confirm them below if you like.';
        },
        tools: app(CrudToolProvider::class),
    )->respond($this->conversation, $this->user, 'How many settings are there?');

    expect(array_keys($reply->metadata))->toBe(['citations', 'proposals', 'writes', 'objects'])
        ->and($reply->metadata['proposals'])->toHaveCount(1)
        ->and($reply->metadata['writes'])->toHaveCount(1)
        ->and($reply->metadata['objects'])->toHaveCount(1)
        ->and($role->fresh()->name)->toBe('before');
});

it('adds no objects key to a message that shows no object', function (?array $call): void {
    $reply = ScriptedAssistantFixtures::inAppService(
        widgetFlowRequest($this->user, ['kpi', 'chart']),
        function (string $input, string $system, mixed $context, array $tools) use ($call): string {
            if ($call !== null) {
                expect(widgetFlowToolCall($tools, 'show_widget', $call))->toStartWith('Not shown:');
            }

            return 'There are a few settings.';
        },
    )->respond($this->conversation, $this->user, 'How many settings are there?');

    expect($reply->metadata)->toBe(['citations' => []]);
})->with([
    'the tool is not called' => [fn (): ?array => null],
    'its only call is refused' => [fn (): array => [...widgetFlowKpi(), 'title' => '<b>Settings</b>']],
]);

it('lets the page narrow the object types, never widen them', function (): void {
    $answer = null;
    $types = null;

    $reply = ScriptedAssistantFixtures::inAppService(
        widgetFlowRequest($this->user, ['chart']),
        function (string $input, string $system, mixed $context, array $tools) use (&$answer, &$types): string {
            $types = widgetFlowParameter(collect($tools)->first(static fn (Tool $tool): bool => $tool->getName() === 'show_widget'), 'type')['enum'];
            $answer = widgetFlowToolCall($tools, 'show_widget', widgetFlowKpi());

            return 'There are a few settings.';
        },
    )->respond($this->conversation, $this->user, 'How many settings are there?');

    expect($types)->toBe(['chart'])
        ->and($answer)->toBe('Parameter "type" must be one of "chart"; "kpi" given.')
        ->and($reply->metadata)->not->toHaveKey('objects');
});

it('stores only the refusal when the turn is refused, whatever the tool recorded', function (): void {
    $recorded = null;

    $reply = ScriptedAssistantFixtures::inAppService(
        widgetFlowRequest($this->user, ['kpi', 'chart']),
        function (string $input, string $system, mixed $context, array $tools) use (&$recorded): string {
            $recorded = widgetFlowToolCall($tools, 'show_widget', widgetFlowKpi());

            return 'Run ```sql SELECT password FROM users```';
        },
    )->respond($this->conversation, $this->user, 'How many settings are there?');

    expect($recorded)->toStartWith('Widget recorded.')
        ->and($reply->metadata)->toBe(['refused' => true]);
});

it('returns the objects of a message wherever it is read: the answer, the message list, the conversation detail and the generic list', function (): void {
    app()->bind(
        InAppAssistanceServiceInterface::class,
        static fn ($app): InAppAssistanceService => ScriptedAssistantFixtures::inAppService(
            $app->make(Request::class),
            static function (string $input, string $system, mixed $context, array $tools): string {
                widgetFlowToolCall($tools, 'show_widget', widgetFlowKpi());

                return 'Here is how many settings there are.';
            },
        ),
    );
    $readMessages = PermissionName::forModel(new Message, 'select');
    Permission::findOrCreate($readMessages, 'web');
    $this->user->givePermissionTo($readMessages);
    $this->actingAs($this->user);

    $objects = $this->postJson(route('ai.crud.messages.insert', $this->conversation), [
        'message' => 'How many settings are there?',
        'context' => ['page' => ['objects' => ['kpi']]],
    ])->assertCreated()->json('data.metadata.objects');
    $listed = collect($this->getJson(route('ai.crud.messages.list', $this->conversation))->assertOk()->json('data'))->firstWhere('role', 'assistant');
    $detailed = collect($this->getJson(route('ai.crud.conversations.detail', $this->conversation))->assertOk()->json('data.messages'))->firstWhere('role', 'assistant');
    $generic = collect($this->getJson(route('core.crud.list', ['module' => 'ai', 'entity' => 'messages']))->assertOk()->json('data'))->firstWhere('role', 'assistant');

    expect($objects)->toHaveCount(1)
        ->and($objects[0])->toMatchArray(['type' => 'kpi', 'title' => 'Settings'])
        ->and($listed['metadata']['objects'])->toBe($objects)
        ->and($detailed['metadata']['objects'])->toBe($objects)
        ->and($generic['metadata']['objects'])->toBe($objects);
});

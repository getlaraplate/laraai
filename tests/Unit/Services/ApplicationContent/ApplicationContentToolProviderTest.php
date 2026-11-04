<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\AI\Concurrency\ApplicationContentSignalDeadline;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\AssistantTenantScope;
use Modules\AI\Services\ApplicationContent\ApplicationContentCitationMapper;
use Modules\AI\Services\ApplicationContent\ApplicationContentDeadlineExecutor;
use Modules\AI\Services\ApplicationContent\ApplicationContentPromptProjector;
use Modules\AI\Services\ApplicationContent\ApplicationContentSourceRouter;
use Modules\AI\Services\ApplicationContent\ApplicationContentToolProvider;
use Modules\AI\Services\ApplicationContent\Data\ApplicationContentRequestContext;
use Modules\AI\Services\ApplicationContent\Enums\ApplicationContentRoutingStatus;
use Modules\AI\Services\Assistance\AssistanceGuardrailPipeline;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Tools\CompositeContextualToolProvider;
use Modules\AI\Services\Tools\ContextualToolProviderInterface;
use Modules\AI\Services\Tools\ToolDefinition;
use Modules\AI\Tests\Stubs\ApplicationContent\ApplicationContentToolFakeProvider;
use Modules\AI\Tests\Stubs\Assistance\StaticToolsProvider;
use Modules\Core\ApplicationContent\ApplicationContentRetrievalProviderRegistry;
use Modules\Core\ApplicationContent\ApplicationContentRetrievalService;
use Modules\Core\ApplicationContent\Contracts\ApplicationContentRetrievalProviderInterface;
use Modules\Core\ApplicationContent\Data\ApplicationContentHit;
use Modules\Core\ApplicationContent\Data\ApplicationContentResult;
use Modules\Core\ApplicationContent\Data\ApplicationContentSourceDescriptor;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Services\Authorization\AuthorizationService;

uses(RefreshDatabase::class);

function applicationContentToolDescriptor(
    string $source = 'cms.contents',
    string $module = 'cms',
    string $entity = 'contents',
    array $intents = ['cms', 'content'],
): ApplicationContentSourceDescriptor {
    return new ApplicationContentSourceDescriptor(
        $source,
        $module,
        $entity,
        ['en', 'it'],
        ['lexical', 'hybrid'],
        $intents,
    );
}

function applicationContentToolAccess(
    AssistantProfile $profile = AssistantProfile::InAppAssistance,
    string $userId = '7',
): AssistantAccessContext {
    if ($profile === AssistantProfile::DeveloperHelp) {
        return new AssistantAccessContext($profile, null, null, null, 'en', [], null);
    }

    return new AssistantAccessContext(
        $profile,
        $userId,
        AssistantTenantScope::Global,
        null,
        'en',
        ['default.contents.select'],
        '42',
    );
}

/**
 * @return array{User, Request}
 */
function applicationContentToolLogin(): array
{
    $role = Role::factory()->create([
        'name' => config('permission.roles.superadmin'),
        'guard_name' => 'web',
    ]);
    $user = User::factory()->create(['id' => 7]);
    $user->assignRole($role);
    auth()->login($user);

    $request = Request::create('/app/ai', 'POST');
    $request->setUserResolver(static fn (): User => $user);

    return [$user, $request];
}

function applicationContentToolProvider(
    ApplicationContentRetrievalProviderInterface|array $provider,
    Request $request,
): ApplicationContentToolProvider {
    $registry = new ApplicationContentRetrievalProviderRegistry;

    foreach (is_array($provider) ? $provider : [$provider] as $item) {
        $registry->register($item);
    }

    return new ApplicationContentToolProvider(
        $registry,
        new ApplicationContentRetrievalService($registry, app(AuthorizationService::class)),
        app(AuthorizationService::class),
        new ApplicationContentSourceRouter,
        new ApplicationContentPromptProjector,
        new ApplicationContentDeadlineExecutor,
        new ApplicationContentCitationMapper,
        AssistanceGuardrailPipeline::defaults(),
        $request,
    );
}

it('registers content retrieval only for approved authenticated sources', function (): void {
    [, $request] = applicationContentToolLogin();
    $provider = applicationContentToolProvider(
        new ApplicationContentToolFakeProvider(applicationContentToolDescriptor()),
        $request,
    );

    $tools = $provider->tools(applicationContentToolAccess());

    expect($tools)->toHaveCount(1)
        ->and($tools[0]->name)->toBe('application_content_search')
        ->and(array_column($tools[0]->parameters, 'name'))->toBe(['source', 'query', 'locale', 'limit'])
        ->and($tools[0]->parameters[0]['enum'])->toBe(['cms.contents']);

    $forbidden = ['user_id', 'tenant_id', 'role', 'roles', 'permission', 'permissions', 'acl', 'filter', 'index', 'class', 'system_prompt'];

    expect(array_column($tools[0]->parameters, 'name'))->not->toContain(...$forbidden);
});

it('excludes application data from developer help and inconsistent identities', function (): void {
    [, $request] = applicationContentToolLogin();
    $provider = applicationContentToolProvider(
        new ApplicationContentToolFakeProvider(applicationContentToolDescriptor()),
        $request,
    );

    expect($provider->tools(applicationContentToolAccess(AssistantProfile::DeveloperHelp)))->toBe([]);

    $request->setUserResolver(static fn (): User => tap(new User, static fn (User $user) => $user->setAttribute('id', 99)));

    expect($provider->tools(applicationContentToolAccess()))->toBe([]);
});

it('invokes the authorized gateway and projects bounded instruction-neutral evidence', function (): void {
    [, $request] = applicationContentToolLogin();
    $result = new ApplicationContentResult('cms.contents', [
        new ApplicationContentHit(
            id: 'cms.contents:5',
            source: 'cms.contents',
            module: 'cms',
            entity: 'contents',
            recordKey: 5,
            excerpt: 'Open the visible content editor and select the publishing action.',
            label: 'Visible content',
            canonicalReference: '/app/cms/contents/5',
            locale: 'en',
            strategy: 'lexical',
            score: 0.9,
            revision: 'rev-1',
            truncated: false,
        ),
    ], 'lexical', false);
    $fake = new ApplicationContentToolFakeProvider(applicationContentToolDescriptor(), $result);
    $provider = applicationContentToolProvider($fake, $request);
    $handler = $provider->tools(applicationContentToolAccess())[0]->handler;

    $output = $handler('cms.contents', 'visible content', 'en', 999);

    expect($fake->calls)->toBe(1)
        ->and($fake->capturedQuery?->limit)->toBe(8)
        ->and($output)->toMatchArray(['available' => true, 'source' => 'cms.contents', 'truncated' => false])
        ->and($output['items'])->toHaveCount(1)
        ->and($output['items'][0]['trust'])->toBe('untrusted_application_data')
        ->and($output['items'][0]['content'])->toBe([
            'kind' => 'application_evidence',
            'value' => 'Open the visible content editor and select the publishing action.',
        ])
        ->and($output['items'][0]['safe_citation'])->toBe([
            'label' => 'Visible content',
            'reference' => '/app/cms/contents/5',
        ])
        ->and($output['items'][0])->not->toHaveKeys(['score', 'record_key', 'permission', 'acl', '_source']);
});

it('omits sources when the user lacks select permission or the provider module is disabled', function (): void {
    $user = User::factory()->create(['id' => 7]);
    auth()->login($user);
    $request = Request::create('/app/ai', 'POST');
    $request->setUserResolver(static fn (): User => $user);
    $denied = applicationContentToolProvider(
        new ApplicationContentToolFakeProvider(applicationContentToolDescriptor()),
        $request,
    );

    expect($denied->tools(applicationContentToolAccess()))->toBe([]);

    $role = Role::factory()->create([
        'name' => config('permission.roles.superadmin'),
        'guard_name' => 'web',
    ]);
    $user->assignRole($role);
    auth()->login($user);
    $disabled = applicationContentToolProvider(
        new ApplicationContentToolFakeProvider(
            applicationContentToolDescriptor('missing.records', 'missing', 'records'),
        ),
        $request,
    );

    expect($disabled->tools(applicationContentToolAccess()))->toBe([]);
});

it('fails closed for a source outside the request allowlist', function (): void {
    [, $request] = applicationContentToolLogin();
    $fake = new ApplicationContentToolFakeProvider(applicationContentToolDescriptor());
    $provider = applicationContentToolProvider($fake, $request);
    $handler = $provider->tools(applicationContentToolAccess())[0]->handler;

    expect($handler('erp.orders', 'orders', 'en', 8))->toBe([
        'available' => false,
        'items' => [],
        'truncated' => false,
        'reason_code' => 'application_content_unavailable',
    ])->and($fake->calls)->toBe(0);
});

it('fails closed on provider denial and interrupts retrieval at the configured deadline', function (): void {
    [, $request] = applicationContentToolLogin();
    $denied = new ApplicationContentToolFakeProvider(
        applicationContentToolDescriptor(),
        fail: true,
    );
    $denied_handler = applicationContentToolProvider($denied, $request)
        ->tools(applicationContentToolAccess())[0]->handler;

    expect($denied_handler('cms.contents', 'visible', 'en', 8))->toMatchArray([
        'available' => false,
        'reason_code' => 'application_content_unavailable',
    ]);

    config()->set('ai.features.application_content.timeout_seconds', 1);
    $slow = new ApplicationContentToolFakeProvider(
        applicationContentToolDescriptor(),
        delayMicroseconds: 2_000_000,
    );
    $slow_handler = applicationContentToolProvider($slow, $request)
        ->tools(applicationContentToolAccess())[0]->handler;
    $started_at = hrtime(true);
    $output = $slow_handler('cms.contents', 'visible', 'en', 8);
    $elapsed_milliseconds = (hrtime(true) - $started_at) / 1_000_000;

    expect($output)->toMatchArray([
        'available' => false,
        'reason_code' => 'application_content_unavailable',
    ])->and($elapsed_milliseconds)->toBeLessThan(1500);
});

it('preserves signal state and never replaces an active process alarm', function (): void {
    $executor = new ApplicationContentDeadlineExecutor;
    $previous_handler = pcntl_signal_get_handler(SIGALRM);

    expect($executor->run(static fn (): string => 'ok', 1))->toBe('ok')
        ->and(pcntl_signal_get_handler(SIGALRM))->toBe($previous_handler)
        ->and(pcntl_alarm(0))->toBe(0);

    expect(fn () => $executor->run(
        static function (): never {
            throw new RuntimeException('Expected test exception.');
        },
        1,
    ))->toThrow(RuntimeException::class)
        ->and(pcntl_signal_get_handler(SIGALRM))->toBe($previous_handler)
        ->and(pcntl_alarm(0))->toBe(0);

    pcntl_alarm(5);

    try {
        expect($executor->run(static fn (): string => 'soft deadline', 1))->toBe('soft deadline')
            ->and(fn () => (new ApplicationContentSignalDeadline)->run(static fn (): string => 'unreachable', 1))
            ->toThrow(LogicException::class)
            ->and(pcntl_alarm(0))->toBeGreaterThan(0);
    } finally {
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, $previous_handler);
    }
});

it('routes verified context and generic requests without registry-order fallback', function (): void {
    $router = new ApplicationContentSourceRouter;
    $cms = applicationContentToolDescriptor();
    $erp = applicationContentToolDescriptor('erp.orders', 'erp', 'orders', ['erp', 'orders']);

    $contextual = $router->route(
        'come modifico questo contenuto?',
        [$erp, $cms],
        new ApplicationContentRequestContext('cms', 'contents', 5),
    );
    $explicit = $router->route('show orders', [$cms, $erp], null, 'erp.orders');
    $ambiguous = $router->route('show application records', [$cms, $erp]);
    $sole = $router->route('generic question', [$cms]);

    expect($contextual->status)->toBe(ApplicationContentRoutingStatus::Selected)
        ->and($contextual->selectedSource)->toBe('cms.contents')
        ->and($explicit->selectedSource)->toBe('erp.orders')
        ->and($ambiguous->status)->toBe(ApplicationContentRoutingStatus::ClarificationRequired)
        ->and($ambiguous->selectedSource)->toBeNull()
        ->and($sole->selectedSource)->toBe('cms.contents');
});

it('narrows the tool schema from verified context and rejects a conflicting model proposal', function (): void {
    [, $request] = applicationContentToolLogin();
    $cms = new ApplicationContentToolFakeProvider(applicationContentToolDescriptor());
    $erp = new ApplicationContentToolFakeProvider(
        applicationContentToolDescriptor('erp.orders', 'erp', 'orders', ['erp', 'orders']),
    );
    $provider = applicationContentToolProvider([$erp, $cms], $request);
    $tools = $provider->toolsForRequest(
        applicationContentToolAccess(),
        'come modifico questo contenuto?',
        new ApplicationContentRequestContext('cms', 'contents', 5),
    );

    expect($tools)->toHaveCount(1)
        ->and($tools[0]->parameters[0]['enum'])->toBe(['cms.contents']);

    $handler = $tools[0]->handler;

    expect($handler('erp.orders', 'show orders', 'en', 8))->toMatchArray(['available' => false])
        ->and($cms->calls)->toBe(0)
        ->and($erp->calls)->toBe(0);
});

it('binds a composite contextual provider so graph and application tools remain independent', function (): void {
    expect(app(ContextualToolProviderInterface::class))->toBeInstanceOf(CompositeContextualToolProvider::class);

    $first = new StaticToolsProvider([new ToolDefinition('graph_search', 'Graph', [], 'low', static fn (): array => [])]);
    $second = new StaticToolsProvider([new ToolDefinition('application_content_search', 'Content', [], 'low', static fn (): array => [])]);

    expect(array_column(
        (new CompositeContextualToolProvider([$first, $second]))->tools(applicationContentToolAccess()),
        'name',
    ))->toBe(['graph_search', 'application_content_search']);
});

it('marks an application content citation as content and keeps its canonical reference', function (): void {
    $result = new ApplicationContentResult('cms.contents', [
        new ApplicationContentHit(
            id: 'cms.contents:5',
            source: 'cms.contents',
            module: 'cms',
            entity: 'contents',
            recordKey: 5,
            excerpt: 'Open the visible content editor.',
            label: 'Visible content',
            canonicalReference: '/app/cms/contents/5',
            locale: 'en',
            strategy: 'lexical',
            score: 0.9,
            revision: 'rev-1',
            truncated: false,
        ),
    ], 'lexical', false);

    expect((new ApplicationContentCitationMapper)->citationsFor($result))->toBe([[
        'kind' => 'content',
        'label' => 'Visible content',
        'reference' => '/app/cms/contents/5',
        'excerpt' => 'Open the visible content editor.',
    ]]);
});

<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\AssistantTenantScope;
use Modules\AI\Models\Conversation;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Assistance\AssistantAccessContextFactory;
use Modules\AI\Services\Assistance\AssistantPromptContext;
use Modules\AI\Services\Assistance\Contracts\AssistantTenantResolverInterface;
use Modules\AI\Services\Assistance\ResolvedAssistantTenant;
use Modules\Core\Models\User;

function assistanceUserMock(
    int $id = 7,
    string $locale = 'it',
    ?Collection $permissions = null,
    bool $guest = false,
): User {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getKey')->andReturn($id);
    $user->shouldReceive('getAttribute')->with('lang')->andReturn($locale);
    $user->shouldReceive('isGuest')->andReturn($guest);
    $user->shouldReceive('getAllPermissions')->andReturn($permissions ?? collect());

    return $user;
}

function assistanceConversation(int $user_id = 7, int $id = 11): Conversation
{
    $conversation = new Conversation;
    $conversation->forceFill(['id' => $id, 'user_id' => $user_id]);
    $conversation->exists = true;

    return $conversation;
}

it('defines only the two server-owned assistant profiles', function (): void {
    expect(AssistantProfile::cases())->toEqual([
        AssistantProfile::DeveloperHelp,
        AssistantProfile::InAppAssistance,
    ]);
});

it('builds in-app context from authenticated server state', function (): void {
    config()->set('app.available_locales', ['en', 'it']);

    $permissions = collect([
        (object) ['name' => 'default.contents.select'],
        (object) ['name' => 'default.dashboard.select'],
        (object) ['name' => 'default.contents.select'],
    ]);
    $user = assistanceUserMock(permissions: $permissions);

    $resolver = Mockery::mock(AssistantTenantResolverInterface::class);
    $resolver->shouldReceive('resolveFor')->once()->with($user)
        ->andReturn(ResolvedAssistantTenant::global());

    $context = (new AssistantAccessContextFactory($resolver))->forInApp(
        assistanceConversation(),
        $user,
    );

    expect($context->profile)->toBe(AssistantProfile::InAppAssistance)
        ->and($context->userId)->toBe('7')
        ->and($context->conversationId)->toBe('11')
        ->and($context->tenantScope)->toBe(AssistantTenantScope::Global)
        ->and($context->tenantId)->toBeNull()
        ->and($context->locale)->toBe('it')
        ->and($context->effectivePermissions)->toBe([
            'default.contents.select',
            'default.dashboard.select',
        ]);
});

it('rejects an authenticated user who does not own the conversation', function (): void {
    $resolver = Mockery::mock(AssistantTenantResolverInterface::class);
    $resolver->shouldNotReceive('resolveFor');

    expect(fn () => (new AssistantAccessContextFactory($resolver))->forInApp(
        assistanceConversation(user_id: 8),
        assistanceUserMock(id: 7),
    ))->toThrow(AuthorizationException::class);
});

it('rejects the configured guest before resolving tenant or permissions', function (): void {
    $user = assistanceUserMock(guest: true);
    $resolver = Mockery::mock(AssistantTenantResolverInterface::class);
    $resolver->shouldNotReceive('resolveFor');

    expect(fn () => (new AssistantAccessContextFactory($resolver))->forInApp(
        assistanceConversation(),
        $user,
    ))->toThrow(AuthorizationException::class);
});

it('fails closed when guest classification fails', function (): void {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getKey')->andReturn(7);
    $user->shouldReceive('isGuest')->once()
        ->andThrow(new UnexpectedValueException('invalid guest configuration'));
    $resolver = Mockery::mock(AssistantTenantResolverInterface::class);
    $resolver->shouldNotReceive('resolveFor');

    expect(fn () => (new AssistantAccessContextFactory($resolver))->forInApp(
        assistanceConversation(),
        $user,
    ))->toThrow(AuthorizationException::class, 'Assistant access context is unavailable.');
});

it('fails closed when tenant resolution fails', function (): void {
    $user = assistanceUserMock();
    $resolver = Mockery::mock(AssistantTenantResolverInterface::class);
    $resolver->shouldReceive('resolveFor')->once()->with($user)
        ->andThrow(new RuntimeException('resolver unavailable'));

    expect(fn () => (new AssistantAccessContextFactory($resolver))->forInApp(
        assistanceConversation(),
        $user,
    ))->toThrow(AuthorizationException::class);
});

it('fails closed when effective permissions cannot be resolved', function (): void {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getKey')->andReturn(7);
    $user->shouldReceive('getAttribute')->with('lang')->andReturn('it');
    $user->shouldReceive('isGuest')->andReturnFalse();
    $user->shouldReceive('getAllPermissions')->once()
        ->andThrow(new RuntimeException('permission store unavailable'));

    $resolver = Mockery::mock(AssistantTenantResolverInterface::class);
    $resolver->shouldReceive('resolveFor')->once()->with($user)
        ->andReturn(ResolvedAssistantTenant::global());

    expect(fn () => (new AssistantAccessContextFactory($resolver))->forInApp(
        assistanceConversation(),
        $user,
    ))->toThrow(AuthorizationException::class);
});

it('builds a search context with no conversation', function (): void {
    config()->set('app.available_locales', ['en', 'it']);

    $user = assistanceUserMock(permissions: collect([
        (object) ['name' => 'default.dashboard.select'],
        (object) ['name' => 'default.contents.select'],
    ]));
    $resolver = Mockery::mock(AssistantTenantResolverInterface::class);
    $resolver->shouldReceive('resolveFor')->once()->with($user)
        ->andReturn(ResolvedAssistantTenant::global());

    $context = (new AssistantAccessContextFactory($resolver))->forDocumentationSearch($user);

    expect($context->profile)->toBe(AssistantProfile::InAppAssistance)
        ->and($context->userId)->toBe('7')
        ->and($context->conversationId)->toBeNull()
        ->and($context->tenantScope)->toBe(AssistantTenantScope::Global)
        ->and($context->tenantId)->toBeNull()
        ->and($context->locale)->toBe('it')
        ->and($context->effectivePermissions)->toBe([
            'default.contents.select',
            'default.dashboard.select',
        ]);
});

it('refuses the guest for a search context', function (): void {
    $resolver = Mockery::mock(AssistantTenantResolverInterface::class);
    $resolver->shouldNotReceive('resolveFor');

    expect(fn () => (new AssistantAccessContextFactory($resolver))->forDocumentationSearch(
        assistanceUserMock(guest: true),
    ))->toThrow(AuthorizationException::class);
});

it('still requires an owned conversation for the assistant', function (): void {
    $resolver = Mockery::mock(AssistantTenantResolverInterface::class);
    $resolver->shouldNotReceive('resolveFor');

    expect(fn () => (new AssistantAccessContextFactory($resolver))->forInApp(
        assistanceConversation(user_id: 9),
        assistanceUserMock(id: 7),
    ))->toThrow(AuthorizationException::class);
});

it('rejects a blank conversation id', function (): void {
    expect(fn () => new AssistantAccessContext(
        profile: AssistantProfile::InAppAssistance,
        userId: '7',
        tenantScope: AssistantTenantScope::Global,
        tenantId: null,
        locale: 'it',
        effectivePermissions: [],
        conversationId: '',
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => new AssistantAccessContext(
        profile: AssistantProfile::DeveloperHelp,
        userId: null,
        tenantScope: null,
        tenantId: null,
        locale: 'it',
        effectivePermissions: [],
        conversationId: '11',
    ))->toThrow(InvalidArgumentException::class);
});

it('keeps prompt context free from control-plane data recursively', function (): void {
    $context = new AssistantPromptContext(
        policyVersion: 'in-app-v1',
        presentationPreferences: ['verbosity' => 'short'],
        safeCitations: [['label' => 'Dashboard help', 'reference' => '/app/dashboard']],
        authorizedResults: [['excerpt' => 'Use the filters button.']],
    );

    expect($context->policyVersion)->toBe('in-app-v1');

    expect(fn () => new AssistantPromptContext(
        policyVersion: 'in-app-v1',
        presentationPreferences: [],
        safeCitations: [],
        authorizedResults: [['metadata' => ['permissions' => ['secret.select']]]],
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => new AssistantPromptContext(
        policyVersion: 'in-app-v1',
        presentationPreferences: [],
        safeCitations: [],
        authorizedResults: [['identity_claims' => ['user_id' => 9]]],
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => new AssistantPromptContext(
        policyVersion: 'in-app-v1',
        presentationPreferences: [],
        safeCitations: [],
        authorizedResults: [['payload' => new stdClass]],
    ))->toThrow(InvalidArgumentException::class);
});

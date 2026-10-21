<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance;

use Illuminate\Auth\Access\AuthorizationException;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Models\Conversation;
use Modules\AI\Services\Assistance\Contracts\AssistantTenantResolverInterface;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Models\User;
use Throwable;
use UnexpectedValueException;

final readonly class AssistantAccessContextFactory
{
    public function __construct(
        private AssistantTenantResolverInterface $tenant_resolver,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function forInApp(Conversation $conversation, User $authenticated_user): AssistantAccessContext
    {
        $user_id = $this->normalizedKey($authenticated_user->getKey());
        $conversation_id = $this->normalizedKey($conversation->getKey());
        $owner_id = $this->normalizedKey($conversation->getAttribute('user_id'));

        if ($user_id === null || $conversation_id === null || $owner_id === null || $owner_id !== $user_id) {
            throw new AuthorizationException('Assistant access context is unavailable.');
        }

        return $this->resolveContext($authenticated_user, $user_id, $conversation_id);
    }

    /**
     * Builds the in-app context for a documentation search, which has no conversation.
     *
     * @throws AuthorizationException
     */
    public function forDocumentationSearch(User $authenticated_user): AssistantAccessContext
    {
        $user_id = $this->normalizedKey($authenticated_user->getKey());

        if ($user_id === null) {
            throw new AuthorizationException('Assistant access context is unavailable.');
        }

        return $this->resolveContext($authenticated_user, $user_id, null);
    }

    /**
     * @throws AuthorizationException
     */
    private function resolveContext(User $authenticated_user, string $user_id, ?string $conversation_id): AssistantAccessContext
    {
        try {
            if ($authenticated_user->isGuest()) {
                throw new AuthorizationException('Assistant access context is unavailable.');
            }

            $tenant = $this->tenant_resolver->resolveFor($authenticated_user);
            $permissions = $this->effectivePermissions($authenticated_user);
        } catch (Throwable $exception) {
            throw new AuthorizationException(
                'Assistant access context is unavailable.',
                previous: $exception,
            );
        }

        return new AssistantAccessContext(
            profile: AssistantProfile::InAppAssistance,
            userId: $user_id,
            tenantScope: $tenant->scope,
            tenantId: $tenant->tenantId,
            locale: $this->localeFor($authenticated_user),
            effectivePermissions: $permissions,
            conversationId: $conversation_id,
        );
    }

    /**
     * @return list<string>
     */
    private function effectivePermissions(User $user): array
    {
        $names = [];

        foreach ($user->getAllPermissions() as $permission) {
            $name = $permission->name ?? null;

            if (! is_string($name) || mb_trim($name) === '') {
                throw new UnexpectedValueException('Effective permission name is unavailable.');
            }

            $names[] = mb_trim($name);
        }

        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);

        return $names;
    }

    private function localeFor(User $user): string
    {
        $fallback = LocaleContext::getDefault();
        $locale = $user->getAttribute('lang');

        if (! is_string($locale) || mb_trim($locale) === '') {
            return $fallback;
        }

        $available_locales = config('app.available_locales', []);

        if (is_array($available_locales) && $available_locales !== [] && ! in_array($locale, $available_locales, true)) {
            return $fallback;
        }

        return $locale;
    }

    private function normalizedKey(mixed $key): ?string
    {
        if (! is_int($key) && ! is_string($key)) {
            return null;
        }

        $normalized = mb_trim((string) $key);

        return $normalized === '' ? null : $normalized;
    }
}

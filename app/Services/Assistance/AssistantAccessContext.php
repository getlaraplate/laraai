<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance;

use InvalidArgumentException;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\AssistantTenantScope;

final readonly class AssistantAccessContext
{
    /**
     * @param  list<string>  $effectivePermissions
     */
    public function __construct(
        public AssistantProfile $profile,
        public ?string $userId,
        public ?AssistantTenantScope $tenantScope,
        public ?string $tenantId,
        public string $locale,
        public array $effectivePermissions,
        public ?string $conversationId,
    ) {
        if (mb_trim($locale) === '') {
            throw new InvalidArgumentException('Assistant locale cannot be blank.');
        }

        if ($profile === AssistantProfile::DeveloperHelp) {
            $this->assertDeveloperContext();

            return;
        }

        $this->assertInAppContext();
    }

    private function assertDeveloperContext(): void
    {
        if (
            $this->userId !== null
            || $this->tenantScope !== null
            || $this->tenantId !== null
            || $this->conversationId !== null
            || $this->effectivePermissions !== []
        ) {
            throw new InvalidArgumentException('Developer help cannot carry runtime access context.');
        }
    }

    private function assertInAppContext(): void
    {
        if (
            $this->userId === null
            || mb_trim($this->userId) === ''
            || $this->tenantScope === null
        ) {
            throw new InvalidArgumentException('In-app assistance requires resolved server context.');
        }

        if ($this->conversationId !== null && mb_trim($this->conversationId) === '') {
            throw new InvalidArgumentException('In-app assistance conversation ID cannot be blank.');
        }

        if ($this->tenantScope === AssistantTenantScope::Global && $this->tenantId !== null) {
            throw new InvalidArgumentException('Global assistant scope cannot have a tenant ID.');
        }

        if (
            $this->tenantScope === AssistantTenantScope::Tenant
            && ($this->tenantId === null || mb_trim($this->tenantId) === '')
        ) {
            throw new InvalidArgumentException('Tenant assistant scope requires a tenant ID.');
        }

        foreach ($this->effectivePermissions as $permission) {
            if (! is_string($permission) || mb_trim($permission) === '') {
                throw new InvalidArgumentException('Effective permissions must be non-empty strings.');
            }
        }
    }
}

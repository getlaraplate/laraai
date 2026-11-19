<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag\Retrieval;

use function ai_config_int;

use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\AssistantTenantScope;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Assistance\Scope\AssistantScope;
use Modules\AI\Services\Assistance\Scope\DocScope;
use Modules\AI\Services\Documentation\PermissionRequirementPatterns;

final readonly class DocumentationRetrievalContext
{
    /**
     * The language the user guides are written in: a caller whose locale has no translation of a guide is shown
     * this one. A class constant, not a setting; see `2026-11-19-documentation-locale-fallback-design.md`.
     */
    public const string FALLBACK_LOCALE = 'en';

    /**
     * @param  list<string>  $effectivePermissions
     */
    private function __construct(
        public AssistantTenantScope $tenantScope,
        public ?string $tenantId,
        public string $locale,
        public array $effectivePermissions,
        public int $topK,
        public ?string $moduleKey = null,
        public DocScope $docScope = DocScope::Application,
    ) {
        if ($topK < 1 || $topK > 10) {
            throw new InvalidArgumentException('In-app documentation retrieval topK must be between 1 and 10.');
        }
    }

    /**
     * @throws AuthorizationException
     */
    public static function fromAccessContext(AssistantAccessContext $access): self
    {
        if ($access->profile !== AssistantProfile::InAppAssistance || $access->tenantScope === null) {
            throw new AuthorizationException('In-app documentation retrieval context is unavailable.');
        }

        $permissions = array_values(array_unique($access->effectivePermissions));
        sort($permissions, SORT_STRING);

        return new self(
            tenantScope: $access->tenantScope,
            tenantId: $access->tenantId,
            locale: $access->locale,
            effectivePermissions: $permissions,
            topK: min(max(ai_config_int('ai.features.faq.max_documents', 5), 1), 10),
        );
    }

    /**
     * @throws AuthorizationException
     */
    public static function fromAccessContextAndScope(AssistantAccessContext $access, AssistantScope $scope): self
    {
        $base = self::fromAccessContext($access);

        return new self(
            tenantScope: $base->tenantScope,
            tenantId: $base->tenantId,
            locale: $base->locale,
            effectivePermissions: $base->effectivePermissions,
            topK: $base->topK,
            moduleKey: $scope->moduleKey,
            docScope: $scope->docScope,
        );
    }

    /**
     * The locales a guide may be in for this caller: their own, then the fallback.
     *
     * @return list<string>
     */
    public function acceptedLocales(): array
    {
        return $this->locale === self::FALLBACK_LOCALE ? [$this->locale] : [$this->locale, self::FALLBACK_LOCALE];
    }

    /**
     * @return array<string, mixed>
     */
    public function elasticsearchFilter(string $classificationVersion): array
    {
        if (mb_trim($classificationVersion) === '') {
            throw new InvalidArgumentException('Documentation classification version cannot be blank.');
        }

        $filter = [
            ['terms' => ['metadata.audience' => ['user', 'shared']]],
            ['terms' => ['metadata.locale' => $this->acceptedLocales()]],
            ['term' => ['metadata.policy_classification' => 'user_safe']],
            ['term' => ['metadata.policy_classification_version' => $classificationVersion]],
            ['term' => ['metadata.permissions_metadata_validated' => true]],
            $this->tenantFilter(),
            $this->permissionFilter(),
        ];

        if ($this->docScope === DocScope::Module && $this->moduleKey !== null) {
            $filter[] = [
                'bool' => [
                    'should' => [
                        ['term' => ['metadata.module' => $this->moduleKey]],
                        ['term' => ['metadata.cross_cutting_user' => true]],
                    ],
                    'minimum_should_match' => 1,
                ],
            ];
        }

        return ['bool' => ['filter' => $filter]];
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantFilter(): array
    {
        if ($this->tenantScope === AssistantTenantScope::Global) {
            return ['term' => ['metadata.tenant_scope' => 'global']];
        }

        return [
            'bool' => [
                'should' => [
                    ['term' => ['metadata.tenant_scope' => 'global']],
                    [
                        'bool' => [
                            'filter' => [
                                ['term' => ['metadata.tenant_scope' => 'tenant']],
                                ['term' => ['metadata.tenant_id' => $this->tenantId]],
                            ],
                        ],
                    ],
                ],
                'minimum_should_match' => 1,
            ],
        ];
    }

    /**
     * A page with no requirement is visible to every authenticated user; otherwise it is visible when the caller
     * satisfies at least one of its requirements ({@see PermissionRequirementPatterns}). A requirement nobody
     * satisfies, a misspelled one included, shows the page to no one.
     *
     * @return array<string, mixed>
     */
    private function permissionFilter(): array
    {
        $should = [
            ['term' => ['metadata.required_permissions_count' => 0]],
        ];

        if ($this->effectivePermissions !== []) {
            $should[] = [
                'terms' => [
                    'metadata.required_permissions' => PermissionRequirementPatterns::satisfiedBy($this->effectivePermissions),
                ],
            ];
        }

        return [
            'bool' => [
                'should' => $should,
                'minimum_should_match' => 1,
            ],
        ];
    }
}

<?php

declare(strict_types=1);

use Modules\AI\Ai\Rag\Retrieval\DocumentationRetrievalContext;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\AssistantTenantScope;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Tests\Stubs\Documentation\FakeDocumentationSearch;

beforeEach(function (): void {
    config()->set('ai.features.faq.max_documents', 5);
    config()->set('ai.features.faq.policy_classification_version', 'in-app-docs-v1');
});

/**
 * @param  list<string>  $permissions
 */
function requirements_access(array $permissions): AssistantAccessContext
{
    return new AssistantAccessContext(
        profile: AssistantProfile::InAppAssistance,
        userId: 'u1',
        tenantScope: AssistantTenantScope::Global,
        tenantId: null,
        locale: 'en',
        effectivePermissions: $permissions,
        conversationId: 'c1',
    );
}

/**
 * @param  list<string>  $requirements
 */
function requirements_retrieval(array $requirements): Modules\AI\Ai\Rag\Retrieval\InAppDocumentationRetrieval
{
    return FakeDocumentationSearch::forInAppRetrieval([
        'q' => [FakeDocumentationSearch::document('Core · Page', 'en', 'Body.', ['Core', 'Page'], $requirements)],
    ]);
}

/**
 * @param  list<string>  $requirements
 * @param  list<string>  $permissions
 */
function requirements_visible(array $requirements, array $permissions): bool
{
    return requirements_retrieval($requirements)->retrieve('q', requirements_access($permissions)) !== [];
}

it('filters on a plain terms query over the requirements the permissions satisfy', function (): void {
    $context = DocumentationRetrievalContext::fromAccessContext(requirements_access(['default.cms_contents.delete']));
    $permission_clause = null;

    foreach ($context->elasticsearchFilter('in-app-docs-v1')['bool']['filter'] as $clause) {
        if (isset($clause['bool']['should'][0]['term']['metadata.required_permissions_count'])) {
            $permission_clause = $clause['bool'];
        }
    }

    expect($permission_clause)->not->toBeNull()
        ->and($permission_clause['should'][0])->toBe(['term' => ['metadata.required_permissions_count' => 0]])
        ->and($permission_clause['should'][1]['terms']['metadata.required_permissions'])->toBe([
            '*.*',
            '*.delete',
            'cms_contents.*',
            'cms_contents.delete',
            'default.cms_contents.delete',
        ])
        ->and(json_encode($permission_clause))->not->toContain('terms_set');
});

it('keeps only the no-requirement branch when the caller has no permission', function (): void {
    $context = DocumentationRetrievalContext::fromAccessContext(requirements_access([]));
    $json = json_encode($context->elasticsearchFilter('in-app-docs-v1'));

    expect($json)->toContain('required_permissions_count')
        ->and($json)->not->toContain('"terms":{"metadata.required_permissions"');
});

it('shows a page with no requirement to every authenticated user', function (): void {
    expect(requirements_visible([], []))->toBeTrue();
});

it('matches a table wildcard against any operation on that table', function (): void {
    expect(requirements_visible(['cms_contents.*'], ['default.cms_contents.update']))->toBeTrue()
        ->and(requirements_visible(['cms_contents.*'], ['default.erp_orders.update']))->toBeFalse();
});

it('matches an operation wildcard against that operation on any table', function (): void {
    expect(requirements_visible(['*.delete'], ['default.erp_orders.delete']))->toBeTrue()
        ->and(requirements_visible(['*.delete'], ['default.erp_orders.update']))->toBeFalse();
});

it('matches the exact short form and the long form', function (): void {
    expect(requirements_visible(['cms_contents.select'], ['default.cms_contents.select']))->toBeTrue()
        ->and(requirements_visible(['default.cms_contents.select'], ['default.cms_contents.select']))->toBeTrue()
        ->and(requirements_visible(['cms_contents.select'], ['default.cms_contents.update']))->toBeFalse();
});

it('shows a page to a caller who satisfies any one of several requirements', function (): void {
    $requirements = ['*.delete', 'cms_contents.select'];

    expect(requirements_visible($requirements, ['default.erp_orders.delete']))->toBeTrue()
        ->and(requirements_visible($requirements, ['default.cms_contents.select']))->toBeTrue()
        ->and(requirements_visible($requirements, ['default.erp_orders.update']))->toBeFalse();
});

it('shows a page whose requirements are all misspelled to nobody, administrator included', function (): void {
    $administrator = ['default.cms_contents.select', 'default.cms_contents.delete', 'default.erp_orders.update'];

    expect(requirements_visible(['cms_contenst.*'], $administrator))->toBeFalse()
        ->and(requirements_visible(['cms_contenst.*', '*.delet'], $administrator))->toBeFalse();
});

it('shows a page with one valid and one misspelled requirement to the audience of the valid one', function (): void {
    $requirements = ['cms_contenst.*', 'cms_contents.*'];

    expect(requirements_visible($requirements, ['default.cms_contents.update']))->toBeTrue()
        ->and(requirements_visible($requirements, ['default.erp_orders.update']))->toBeFalse();
});

it('never treats a partial wildcard or a different case as a match', function (): void {
    expect(requirements_visible(['erp_*.update'], ['default.erp_orders.update']))->toBeFalse()
        ->and(requirements_visible(['*_orders.update'], ['default.erp_orders.update']))->toBeFalse()
        ->and(requirements_visible(['erp_invoices.forcedelete'], ['default.erp_invoices.forceDelete']))->toBeFalse()
        ->and(requirements_visible(['erp_invoices.forceDelete'], ['default.erp_invoices.forceDelete']))->toBeTrue();
});

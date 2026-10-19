<?php

declare(strict_types=1);

use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\AssistantTenantScope;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Assistance\Scope\AssistantScope;
use Modules\AI\Services\Assistance\Scope\DataAccess;
use Modules\AI\Services\Assistance\Scope\DocScope;
use Modules\AI\Tests\Stubs\Documentation\FakeDocumentationSearch;

beforeEach(function (): void {
    config()->set('ai.features.faq.max_documents', 5);
    config()->set('ai.features.faq.policy_classification_version', 'in-app-docs-v1');
});

function erpAccess(): AssistantAccessContext
{
    return new AssistantAccessContext(
        profile: AssistantProfile::InAppAssistance, userId: 'u1',
        tenantScope: AssistantTenantScope::Global, tenantId: null,
        locale: 'en', effectivePermissions: [], conversationId: 'c1',
    );
}

it('under ERP module scope returns ERP and cross-cutting docs but excludes CMS', function (): void {
    $retrieval = FakeDocumentationSearch::forInAppRetrieval([
        'q' => [
            FakeDocumentationSearch::document('ERP · Orders', 'en', 'erp', ['ERP', 'Orders'], module: 'erp'),
            FakeDocumentationSearch::document('Core · Approve modification', 'en', 'x', ['Core'], module: 'core', crossCuttingUser: true),
            FakeDocumentationSearch::document('CMS · Blocks', 'en', 'cms', ['CMS'], module: 'cms'),
        ],
    ]);

    $docs = $retrieval->retrieve('q', erpAccess(), new AssistantScope('erp', DataAccess::Module, DocScope::Module));
    $labels = array_map(static fn ($d): string => $d->getSourceName(), $docs);

    expect($labels)->toContain('ERP · Orders')
        ->and($labels)->toContain('Core · Approve modification')
        ->and($labels)->not->toContain('CMS · Blocks');
});

it('with generic scope returns all modules (no module clause)', function (): void {
    $retrieval = FakeDocumentationSearch::forInAppRetrieval([
        'q' => [
            FakeDocumentationSearch::document('ERP · Orders', 'en', 'erp', ['ERP'], module: 'erp'),
            FakeDocumentationSearch::document('CMS · Blocks', 'en', 'cms', ['CMS'], module: 'cms'),
        ],
    ]);

    $docs = $retrieval->retrieve('q', erpAccess(), AssistantScope::generic());
    expect($docs)->toHaveCount(2);
});

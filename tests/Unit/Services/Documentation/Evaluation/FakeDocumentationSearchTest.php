<?php

declare(strict_types=1);

use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\AssistantTenantScope;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Tests\Stubs\Documentation\FakeDocumentationSearch;

beforeEach(function (): void {
    config()->set('ai.features.faq.max_documents', 5);
    config()->set('ai.features.faq.policy_classification_version', 'in-app-docs-v1');
});

function docAccess(array $permissions = [], AssistantTenantScope $scope = AssistantTenantScope::Global, ?string $tenantId = null): AssistantAccessContext
{
    return new AssistantAccessContext(
        profile: AssistantProfile::InAppAssistance,
        userId: 'u1',
        tenantScope: $scope,
        tenantId: $tenantId,
        locale: 'en',
        effectivePermissions: $permissions,
        conversationId: 'c1',
    );
}

it('returns ranked safe documents and strips unsafe metadata', function (): void {
    $retrieval = FakeDocumentationSearch::forInAppRetrieval([
        'how do I export a grid?' => [
            FakeDocumentationSearch::document('Core · Grid export', 'en', 'Use the export action.', ['Core', 'Grid export']),
        ],
    ]);

    $docs = $retrieval->retrieve('how do I export a grid?', docAccess());

    expect($docs)->toHaveCount(1)
        ->and($docs[0]->getSourceName())->toBe('Core · Grid export')
        ->and($docs[0]->getSourceType())->toBe('documentation')
        ->and(array_key_exists('canonical_source', $docs[0]->getMetadata()))->toBeFalse();
});

it('excludes a permission-gated document the principal cannot see', function (): void {
    $retrieval = FakeDocumentationSearch::forInAppRetrieval([
        'secret settings?' => [
            FakeDocumentationSearch::document('Core · Secret', 'en', 'Restricted.', ['Core', 'Secret'], ['core.secret.view']),
        ],
    ]);

    expect($retrieval->retrieve('secret settings?', docAccess()))->toBe([]);
    expect($retrieval->retrieve('secret settings?', docAccess(['core.secret.view'])))->toHaveCount(1);
});

it('excludes a document in another locale', function (): void {
    $retrieval = FakeDocumentationSearch::forInAppRetrieval([
        'ciao?' => [FakeDocumentationSearch::document('Core · IT', 'it', 'Contenuto.', ['Core', 'IT'])],
    ]);

    expect($retrieval->retrieve('ciao?', docAccess()))->toBe([]); // access locale is en
});

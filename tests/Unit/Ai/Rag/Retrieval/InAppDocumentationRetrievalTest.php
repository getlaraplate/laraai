<?php

declare(strict_types=1);

use Elastic\Elasticsearch\Client;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Ai\Rag\ElasticsearchRagVectorStore;
use Modules\AI\Ai\Rag\Retrieval\DocumentationRetrievalContext;
use Modules\AI\Ai\Rag\Retrieval\InAppDocumentationRetrieval;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\AssistantTenantScope;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use NeuronAI\RAG\Document;

function in_app_retrieval_context(
    AssistantTenantScope $scope = AssistantTenantScope::Global,
    ?string $tenant_id = null,
    array $permissions = ['cms.content.edit', 'cms.content.view'],
): DocumentationRetrievalContext {
    return DocumentationRetrievalContext::fromAccessContext(in_app_access_context($scope, $tenant_id, $permissions));
}

function in_app_access_context(
    AssistantTenantScope $scope = AssistantTenantScope::Global,
    ?string $tenant_id = null,
    array $permissions = ['cms.content.edit', 'cms.content.view'],
): AssistantAccessContext {
    return new AssistantAccessContext(
        profile: AssistantProfile::InAppAssistance,
        userId: '7',
        tenantScope: $scope,
        tenantId: $tenant_id,
        locale: 'it',
        effectivePermissions: $permissions,
        conversationId: '11',
    );
}

function safe_retrieval_hit_metadata(): array
{
    return [
        'audience' => 'user',
        'module' => 'CMS',
        'locale' => 'it',
        'canonical_source' => 'cms/content/editing',
        'safe_source_label' => 'Modifica dei contenuti',
        'required_permissions' => ['cms.content.view'],
        'permissions_metadata_validated' => true,
        'required_permissions_count' => 1,
        'tenant_scope' => 'global',
        'version' => '1.0',
        'heading_breadcrumb' => ['Contenuti', 'Modifica'],
        'policy_classification' => 'user_safe',
        'policy_classification_version' => 'in-app-docs-v1',
    ];
}

it('builds retrieval identity only from an in-app access context', function (): void {
    $context = in_app_retrieval_context();

    expect($context->locale)->toBe('it')
        ->and($context->tenantScope)->toBe(AssistantTenantScope::Global)
        ->and($context->effectivePermissions)->toBe(['cms.content.edit', 'cms.content.view'])
        ->and($context->topK)->toBeGreaterThan(0)->toBeLessThanOrEqual(10);

    $developer = new AssistantAccessContext(
        profile: AssistantProfile::DeveloperHelp,
        userId: null,
        tenantScope: null,
        tenantId: null,
        locale: 'en',
        effectivePermissions: [],
        conversationId: null,
    );

    expect(fn () => DocumentationRetrievalContext::fromAccessContext($developer))
        ->toThrow(AuthorizationException::class);
});

it('puts locale tenant classification and all required permissions in the Elasticsearch query', function (): void {
    config()->set('ai.features.faq.elasticsearch.developer_index', 'developer_docs');
    config()->set('ai.features.faq.elasticsearch.user_index', 'user_docs');
    config()->set('ai.features.faq.policy_classification_version', 'in-app-docs-v1');

    $client = Mockery::mock(Client::class);
    $client->shouldReceive('search')
        ->once()
        ->with(Mockery::on(function (array $params): bool {
            $filter = $params['body']['knn']['filter']['bool']['filter'] ?? [];
            $encoded = json_encode($filter);

            return $params['index'] === 'user_docs'
                && str_contains($encoded, 'metadata.locale')
                && str_contains($encoded, 'metadata.tenant_scope')
                && str_contains($encoded, 'metadata.policy_classification_version')
                && str_contains($encoded, 'metadata.required_permissions')
                && str_contains($encoded, 'metadata.permissions_metadata_validated')
                && str_contains($encoded, 'metadata.required_permissions_count')
                && str_contains($encoded, 'cms.content.edit')
                && str_contains($encoded, 'cms.content.view');
        }))
        ->andReturn(make_elasticsearch_response(['hits' => ['hits' => []]]));

    $store = new ElasticsearchRagVectorStore(
        client: $client,
        indexProfile: DocumentationIndexProfile::User,
        topK: 5,
        embedding_dims: 3,
    );

    expect($store->similaritySearchForContext(
        [0.1, 0.2, 0.3],
        in_app_retrieval_context(AssistantTenantScope::Tenant, 'tenant-9'),
    ))->toBe([]);
});

it('requires an explicit server validation marker even for documents without permissions', function (): void {
    $context = in_app_retrieval_context(permissions: []);
    $encoded = json_encode($context->elasticsearchFilter('in-app-docs-v1'));

    expect($encoded)->toContain('permissions_metadata_validated')
        ->and($encoded)->toContain('required_permissions_count')
        ->and($encoded)->toContain('true');
});

it('uses a global-only tenant filter for globally scoped assistance', function (): void {
    $context = in_app_retrieval_context();
    $filter = $context->elasticsearchFilter('in-app-docs-v1');
    $encoded = json_encode($filter);

    expect($encoded)->toContain('global')
        ->not->toContain('tenant_id');
});

it('returns only safe citations from authorized scoped hits', function (): void {
    $embedding_service = Mockery::mock(IEmbeddingService::class);
    $embedding_service->shouldReceive('embedText')->once()->with('Come modifico un contenuto?')->andReturn([0.1, 0.2, 0.3]);

    $hit = (new Document('Apri il contenuto e seleziona Modifica.'))
        ->setSourceName('/internal/path/content.md')
        ->setMetadata(safe_retrieval_hit_metadata())
        ->setScore(0.91);

    $retrieval = new InAppDocumentationRetrieval(
        embedding_service: $embedding_service,
        search: static function (array $embedding, DocumentationRetrievalContext $context) use ($hit): array {
            expect($embedding)->toBe([0.1, 0.2, 0.3])
                ->and($context->locale)->toBe('it');

            return [$hit];
        },
    );

    $documents = $retrieval->retrieve('Come modifico un contenuto?', in_app_access_context());

    expect($documents)->toHaveCount(1)
        ->and($documents[0]->getSourceName())->toBe('Modifica dei contenuti')
        ->and($documents[0]->getMetadata())->not->toHaveKeys(['required_permissions', 'tenant_id', 'canonical_source'])
        ->and($documents[0]->getScore())->toBe(0.91);
});

it('drops documents scoring below the configured minimum similarity', function (): void {
    config()->set('ai.features.faq.min_similarity', 0.95);

    $embedding_service = Mockery::mock(IEmbeddingService::class);
    $embedding_service->shouldReceive('embedText')->once()->andReturn([0.1, 0.2, 0.3]);

    $hit = (new Document('Weakly related passage.'))
        ->setSourceName('/internal/path/content.md')
        ->setMetadata(safe_retrieval_hit_metadata())
        ->setScore(0.90);

    $retrieval = new InAppDocumentationRetrieval(
        embedding_service: $embedding_service,
        search: static fn (): array => [$hit],
    );

    expect($retrieval->retrieve('Come modifico un contenuto?', in_app_access_context()))->toBe([]);
});

it('fails closed without retrying another corpus when scoped retrieval fails', function (): void {
    $embedding_service = Mockery::mock(IEmbeddingService::class);
    $embedding_service->shouldReceive('embedText')->once()->andReturn([0.1, 0.2, 0.3]);

    $retrieval = new InAppDocumentationRetrieval(
        embedding_service: $embedding_service,
        search: static fn (): never => throw new RuntimeException('developer index unavailable'),
    );

    expect(fn () => $retrieval->retrieve('Aiuto', in_app_access_context()))
        ->toThrow(RuntimeException::class, 'In-app documentation retrieval is unavailable.');
});

<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Modules\AI\Ai\Rag\Retrieval\DocumentationRetrievalContext;
use Modules\AI\Ai\Rag\Retrieval\InAppDocumentationRetrieval;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Services\Assistance\AssistantAccessContextFactory;
use Modules\AI\Services\Assistance\Contracts\AssistantTenantResolverInterface;
use Modules\AI\Services\Assistance\ResolvedAssistantTenant;
use Modules\AI\Services\Assistance\Scope\AssistantScopeResolver;
use Modules\AI\Services\Assistance\Scope\DocScope;
use Modules\AI\Services\Documentation\Search\DocumentationPassage;
use Modules\AI\Services\Documentation\Search\DocumentationSearch;
use Modules\AI\Services\DocumentationService;
use Modules\Core\Models\User;
use NeuronAI\RAG\Document;

function doc_search_user(int $id, array $permission_names): User
{
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getKey')->andReturn($id);
    $user->shouldReceive('getAttribute')->with('lang')->andReturn('it');
    $user->shouldReceive('isGuest')->andReturn(false);
    $user->shouldReceive('getAllPermissions')->andReturn(
        new Collection(array_map(static fn (string $name): object => (object) ['name' => $name], $permission_names)),
    );

    return $user;
}

/**
 * @param  Closure(list<float>, DocumentationRetrievalContext): array<Document>  $search
 */
function doc_search_service(Closure $search): DocumentationSearch
{
    $embedding = Mockery::mock(IEmbeddingService::class);
    $embedding->shouldReceive('embedText')->andReturn([0.1, 0.2, 0.3]);

    $tenants = Mockery::mock(AssistantTenantResolverInterface::class);
    $tenants->shouldReceive('resolveFor')->andReturn(ResolvedAssistantTenant::global());

    return new DocumentationSearch(
        new DocumentationService(in_app_retrieval: new InAppDocumentationRetrieval($embedding, $search)),
        new AssistantAccessContextFactory($tenants),
        new AssistantScopeResolver,
    );
}

/**
 * @param  array<string, mixed>  $overrides
 */
function doc_search_hit(string $label, string $content = 'Open the item.', array $overrides = []): Document
{
    return (new Document($content))
        ->setSourceName('/internal/path.md')
        ->setMetadata([
            'audience' => 'user',
            'module' => 'cms',
            'locale' => 'it',
            'canonical_source' => 'cms/approvals',
            'safe_source_label' => $label,
            'required_permissions' => [],
            'permissions_metadata_validated' => true,
            'required_permissions_count' => 0,
            'tenant_scope' => 'global',
            'version' => '1.0',
            'heading_breadcrumb' => ['Approvals', 'Approve'],
            'policy_classification' => 'user_safe',
            'policy_classification_version' => 'in-app-docs-v1',
            ...$overrides,
        ])
        ->setScore(0.9);
}

it('maps a document to a passage', function (): void {
    $long = str_repeat('a', 500);
    $search = doc_search_service(static fn (): array => [
        doc_search_hit('Approve a modification', $long),
    ]);

    $passages = $search->search(doc_search_user(1, []), 'cms', 'approve');

    expect($passages)->toHaveCount(1)
        ->and($passages[0])->toBeInstanceOf(DocumentationPassage::class)
        ->and($passages[0]->label)->toBe('Approve a modification')
        ->and($passages[0]->reference)->toBe('Approve a modification')
        ->and($passages[0]->excerpt)->toBe(str_repeat('a', 300) . '...')
        ->and($passages[0]->headingBreadcrumb)->toBe('Approvals > Approve')
        ->and($passages[0]->module)->toBe('cms')
        ->and($passages[0]->toArray())->toBe([
            'label' => 'Approve a modification',
            'reference' => 'Approve a modification',
            'excerpt' => $passages[0]->excerpt,
            'heading_breadcrumb' => 'Approvals > Approve',
            'module' => 'cms',
        ]);
});

it('omits breadcrumb and module when the document does not carry them', function (): void {
    $document = (new Document('Plain text.'))
        ->setSourceName('Plain')
        ->setMetadata(['heading_breadcrumb' => 42]);

    $passage = DocumentationPassage::fromDocument($document);

    expect($passage)->not->toBeNull()
        ->and($passage->headingBreadcrumb)->toBeNull()
        ->and($passage->module)->toBeNull()
        ->and($passage->toArray())->toBe([
            'label' => 'Plain',
            'reference' => 'Plain',
            'excerpt' => 'Plain text.',
        ]);
});

it('skips a document with a blank source name', function (): void {
    $blank = (new Document('Text.'))->setSourceName('   ');
    $kept = (new Document('Text.'))->setSourceName('Kept');

    expect(DocumentationPassage::fromDocument($blank))->toBeNull();

    $search = doc_search_service(static fn (): array => [doc_search_hit('Kept')]);

    expect($search->search(doc_search_user(1, []), 'cms', 'q'))->toHaveCount(1)
        ->and(DocumentationPassage::fromDocument($kept)?->label)->toBe('Kept');
});

it('asks for the module scope', function (): void {
    $captured = null;
    $search = doc_search_service(static function (array $embedding, DocumentationRetrievalContext $context) use (&$captured): array {
        $captured = $context;

        return [];
    });

    $search->search(doc_search_user(1, []), 'cms', 'approve');

    expect($captured)->toBeInstanceOf(DocumentationRetrievalContext::class)
        ->and($captured->moduleKey)->toBe('cms')
        ->and($captured->docScope)->toBe(DocScope::Module);
});

it("uses each user's own access context", function (): void {
    $seen = [];
    $search = doc_search_service(static function (array $embedding, DocumentationRetrievalContext $context) use (&$seen): array {
        $seen[] = $context->effectivePermissions;

        return [];
    });

    $search->search(doc_search_user(1, ['cms.content.view']), 'cms', 'q');
    $search->search(doc_search_user(2, ['cms.content.edit', 'cms.content.view']), 'cms', 'q');

    expect($seen)->toBe([['cms.content.view'], ['cms.content.edit', 'cms.content.view']]);
});

it('lets a retrieval failure through as RuntimeException', function (): void {
    $search = doc_search_service(static fn (): array => throw new Exception('down'));

    expect(fn (): array => $search->search(doc_search_user(1, []), 'cms', 'q'))
        ->toThrow(RuntimeException::class);
});

it('turns a Markdown chunk into plain text before cutting the excerpt', function (): void {
    $markdown = "# Approvals\n\n| Step | Who |\n|------|-----|\n| One | **Editor** |\n\n```php\n\$x = 1;\n```\n\nSee the [guide](https://example.test/guide) for _details_.\n\n<script>alert('x')</script>";
    $document = (new Document($markdown))->setSourceName('Approvals');

    $excerpt = DocumentationPassage::fromDocument($document)?->excerpt;

    expect($excerpt)->toBeString()
        ->not->toContain('#')
        ->not->toContain('|')
        ->not->toContain('```')
        ->not->toContain('](')
        ->not->toContain('**')
        ->not->toContain('_details_')
        ->not->toContain('<')
        ->not->toContain('>')
        ->toContain('Approvals')
        ->toContain('Editor')
        ->toContain('See the guide for details.');
});

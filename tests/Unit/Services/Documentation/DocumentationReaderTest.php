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
use Modules\AI\Services\Documentation\Search\DocumentationRead;
use Modules\AI\Services\Documentation\Search\DocumentationReader;
use Modules\AI\Services\Documentation\Search\DocumentationSection;
use Modules\AI\Services\DocumentationService;
use Modules\Core\Models\User;
use NeuronAI\RAG\Document;

const READER_KEY = 'd_0123456789abcdef0123456789abcdef';

function reader_user(array $permission_names = []): User
{
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getKey')->andReturn(5);
    $user->shouldReceive('getAttribute')->with('lang')->andReturn('it');
    $user->shouldReceive('isGuest')->andReturn(false);
    $user->shouldReceive('getAllPermissions')->andReturn(
        new Collection(array_map(static fn (string $name): object => (object) ['name' => $name], $permission_names)),
    );

    return $user;
}

/**
 * @param  Closure(string, DocumentationRetrievalContext, int|null, int|null): array{documents: array<Document>, total: int|null}  $read
 */
function reader_service(Closure $read): DocumentationReader
{
    $tenants = Mockery::mock(AssistantTenantResolverInterface::class);
    $tenants->shouldReceive('resolveFor')->andReturn(ResolvedAssistantTenant::global());

    return new DocumentationReader(
        new DocumentationService(in_app_retrieval: new InAppDocumentationRetrieval(Mockery::mock(IEmbeddingService::class), read: $read)),
        new AssistantAccessContextFactory($tenants),
        new AssistantScopeResolver,
    );
}

function reader_chunk(int $section, string $content, array $overrides = []): Document
{
    return (new Document($content))
        ->setSourceName('/internal/path.md')
        ->setMetadata([
            'audience' => 'user',
            'module' => 'cms',
            'locale' => 'it',
            'canonical_source' => 'cms/approvals',
            'safe_source_label' => 'Approve a modification',
            'required_permissions' => [],
            'permissions_metadata_validated' => true,
            'required_permissions_count' => 0,
            'tenant_scope' => 'global',
            'version' => '1.0',
            'heading_breadcrumb' => ['Approvals', 'Approve'],
            'policy_classification' => 'user_safe',
            'policy_classification_version' => 'in-app-docs-v1',
            'document_key' => READER_KEY,
            'section' => $section,
            ...$overrides,
        ]);
}

it('reads the sections of a document in order with the label and the section count', function (): void {
    $reader = reader_service(static fn (): array => [
        'documents' => [reader_chunk(0, 'Intro.'), reader_chunk(1, "> Section: Approvals / Approve\n\nOpen the item."), reader_chunk(2, 'Confirm.')],
        'total' => 9,
    ]);

    $read = $reader->read(reader_user(), 'cms', READER_KEY);

    expect($read)->toBeInstanceOf(DocumentationRead::class)
        ->and($read->reference)->toBe(READER_KEY)
        ->and($read->label)->toBe('Approve a modification')
        ->and($read->module)->toBe('cms')
        ->and($read->total)->toBe(9)
        ->and($read->from)->toBe(0)
        ->and(array_map(static fn (DocumentationSection $section): int => $section->section, $read->sections))->toBe([0, 1, 2])
        ->and($read->sections[1]->content)->toBe('Open the item.')
        ->and($read->sections[1]->headingBreadcrumb)->toBe('Approvals > Approve');
});

it('asks for the section and one neighbour on each side, and for every section without one', function (?int $section, ?int $from, ?int $to): void {
    $seen = null;
    $reader = reader_service(static function (string $key, DocumentationRetrievalContext $context, ?int $a, ?int $b) use (&$seen): array {
        $seen = [$key, $a, $b];

        return ['documents' => [reader_chunk(0, 'Intro.')], 'total' => 1];
    });

    $reader->read(reader_user(), 'cms', READER_KEY, $section);

    expect($seen)->toBe([READER_KEY, $from, $to]);
})->with([
    'a middle section' => [4, 3, 5],
    'the first section' => [0, 0, 1],
    'the whole document' => [null, null, null],
]);

it('reads with the access context and the module scope of the person', function (): void {
    $context = null;
    $reader = reader_service(static function (string $key, DocumentationRetrievalContext $received) use (&$context): array {
        $context = $received;

        return ['documents' => [reader_chunk(0, 'Intro.')], 'total' => 1];
    });

    $reader->read(reader_user(['cms.content.view', 'cms.content.edit']), 'cms', READER_KEY);

    expect($context->locale)->toBe('it')
        ->and($context->effectivePermissions)->toBe(['cms.content.edit', 'cms.content.view'])
        ->and($context->moduleKey)->toBe('cms')
        ->and($context->docScope)->toBe(DocScope::Module);
});

it('answers nothing for a document the index does not return to this person', function (): void {
    $reader = reader_service(static fn (): array => ['documents' => [], 'total' => null]);

    expect($reader->read(reader_user(), 'cms', READER_KEY))->toBeNull();
});

it('does not even ask the index for a reference that is not a document key', function (string $reference): void {
    $reader = reader_service(static function (): never {
        throw new LogicException('The index must not be read.');
    });

    expect($reader->read(reader_user(), 'cms', $reference))->toBeNull();
})->with([
    'a label' => ['Approve a modification'],
    'a path' => ['/internal/path.md'],
    'a key with uppercase' => ['d_0123456789ABCDEF0123456789abcdef'],
    'a short key' => ['d_0123'],
]);

it('leaves out a chunk that carries no position and never returns a path', function (): void {
    $reader = reader_service(static fn (): array => [
        'documents' => [reader_chunk(0, 'Intro.'), reader_chunk(1, 'No position.', ['section' => null])],
        'total' => 2,
    ]);

    $read = $reader->read(reader_user(), 'cms', READER_KEY);

    expect($read->sections)->toHaveCount(1)
        ->and(json_encode($read->toArray()))->not->toContain('canonical_source')->not->toContain('/internal/path.md');
});

it('never reports fewer sections than it returns', function (): void {
    $reader = reader_service(static fn (): array => ['documents' => [reader_chunk(3, 'Fourth.')], 'total' => null]);

    $read = $reader->read(reader_user(), 'cms', READER_KEY, 3);

    expect($read->total)->toBe(4)->and($read->from)->toBe(3);
});

it('reports a retrieval that is unavailable', function (): void {
    $reader = reader_service(static function (): never {
        throw new RuntimeException('index down');
    });

    expect(fn () => $reader->read(reader_user(), 'cms', READER_KEY))->toThrow(RuntimeException::class);
});

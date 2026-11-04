<?php

declare(strict_types=1);

use Modules\AI\Services\Documentation\DocumentationMetadata;
use NeuronAI\RAG\Document;

function legacy_documentation_document(array $metadata = ['heading_breadcrumb' => []]): Document
{
    return (new Document('# Legacy'))
        ->setSourceType('files')
        ->setSourceName('faq-module-Core/nested/LEGACY.md')
        ->setMetadata($metadata);
}

it('gives a legacy document the neutral developer corpus metadata', function (): void {
    $document = legacy_documentation_document();

    DocumentationMetadata::applyDeveloperDefaults($document);

    expect($document->getMetadata())->toBe([
        'heading_breadcrumb' => [],
        'audience' => 'shared',
        'module' => 'app',
        'locale' => 'und',
        'canonical_source' => 'faq-module-Core/nested/LEGACY.md',
        'source_type' => 'file',
    ]);
});

it('keeps the metadata a document declares and fills only what is missing or blank', function (): void {
    $document = legacy_documentation_document([
        'audience' => 'developer',
        'module' => 'core',
        'locale' => '',
        'canonical_source' => null,
        'heading_breadcrumb' => ['Import'],
        'cross_cutting_user' => true,
    ]);

    DocumentationMetadata::applyDeveloperDefaults($document);

    expect($document->getMetadata())->toBe([
        'audience' => 'developer',
        'module' => 'core',
        'locale' => 'und',
        'canonical_source' => 'faq-module-Core/nested/LEGACY.md',
        'heading_breadcrumb' => ['Import'],
        'cross_cutting_user' => true,
        'source_type' => 'file',
    ]);
});

it('knows exactly the user, developer and shared audiences', function (mixed $audience, bool $known): void {
    expect(DocumentationMetadata::isKnownAudience($audience))->toBe($known);
})->with([
    'user' => ['user', true],
    'developer' => ['developer', true],
    'shared' => ['shared', true],
    'unknown' => ['admin', false],
    'wrong case' => ['Shared', false],
    'not a string' => [['user'], false],
]);

it('derives an opaque document key from the module, the locale and the canonical source', function (): void {
    $metadata = ['module' => 'cms', 'locale' => 'en', 'canonical_source' => 'cms/content/editing'];

    $key = DocumentationMetadata::documentKey($metadata);

    expect($key)->toMatch('/\Ad_[0-9a-f]{32}\z/')
        ->and($key)->not->toContain('editing')
        ->and(DocumentationMetadata::documentKey($metadata))->toBe($key)
        ->and(DocumentationMetadata::documentKey([...$metadata, 'locale' => 'it']))->not->toBe($key)
        ->and(DocumentationMetadata::documentKey([...$metadata, 'module' => 'core']))->not->toBe($key)
        ->and(DocumentationMetadata::documentKey([...$metadata, 'canonical_source' => 'cms/content/other']))->not->toBe($key);
});

it('gives no document key to a document that does not declare its identity', function (array $metadata): void {
    expect(DocumentationMetadata::documentKey($metadata))->toBeNull();
})->with([
    'nothing declared' => [[]],
    'no canonical source' => [['module' => 'cms', 'locale' => 'en']],
    'blank canonical source' => [['module' => 'cms', 'locale' => 'en', 'canonical_source' => ' ']],
    'no locale' => [['module' => 'cms', 'canonical_source' => 'cms/a']],
    'no module' => [['locale' => 'en', 'canonical_source' => 'cms/a']],
    'not a string' => [['module' => 'cms', 'locale' => ['en'], 'canonical_source' => 'cms/a']],
]);

it('numbers the chunks of a document and gives each the document key', function (): void {
    $source = (new Document('# A'))->setMetadata(['module' => 'cms', 'locale' => 'en', 'canonical_source' => 'cms/a']);
    $chunks = [
        (new Document('one'))->setMetadata($source->getMetadata()),
        (new Document('two'))->setMetadata($source->getMetadata()),
        (new Document('three'))->setMetadata($source->getMetadata()),
    ];

    DocumentationMetadata::markPositions($chunks);

    $key = DocumentationMetadata::documentKey($source->getMetadata());

    expect(array_map(static fn (Document $chunk): mixed => $chunk->getMetadata()['section'], $chunks))->toBe([0, 1, 2])
        ->and(array_unique(array_map(static fn (Document $chunk): mixed => $chunk->getMetadata()['document_key'], $chunks)))->toBe([$key]);
});

it('numbers the chunks of a document without identity but gives them no key', function (): void {
    $chunks = [(new Document('one'))->setMetadata([]), (new Document('two'))->setMetadata([])];

    DocumentationMetadata::markPositions($chunks);

    expect($chunks[1]->getMetadata())->toBe(['section' => 1]);
});

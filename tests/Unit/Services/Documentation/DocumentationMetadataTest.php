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

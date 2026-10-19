<?php

declare(strict_types=1);

use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Services\Documentation\DocumentationMetadata;
use Modules\AI\Services\Documentation\DocumentAudiencePolicy;
use NeuronAI\RAG\Document;

function documentation_document(array $metadata): Document
{
    return (new Document('Safe application usage guidance.'))->setMetadata($metadata);
}

function valid_user_documentation_metadata(string $audience = 'user'): array
{
    return [
        'audience' => $audience,
        'module' => 'CMS',
        'locale' => 'it',
        'canonical_source' => 'cms/content/editing',
        'safe_source_label' => 'Modifica dei contenuti',
        'required_permissions' => ['cms.content.view'],
        'tenant_scope' => 'global',
        'tenant_id' => null,
        'version' => '1.0',
        'heading_breadcrumb' => ['Contenuti', 'Modifica'],
        'policy_classification' => 'user_safe',
        'policy_classification_version' => 'in-app-docs-v1',
    ];
}

it('keeps every documentation audience in the developer corpus', function (array $metadata): void {
    $policy = new DocumentAudiencePolicy('in-app-docs-v1');

    expect($policy->allows(documentation_document($metadata), DocumentationIndexProfile::Developer))->toBeTrue();
})->with([
    'developer' => [['audience' => 'developer']],
    'shared' => [['audience' => 'shared']],
    'user' => [['audience' => 'user']],
    'unclassified legacy documentation' => [[]],
]);

it('allows only explicitly safe user or shared documentation in the user corpus', function (string $audience): void {
    $policy = new DocumentAudiencePolicy('in-app-docs-v1');

    expect($policy->allows(
        documentation_document(valid_user_documentation_metadata($audience)),
        DocumentationIndexProfile::User,
    ))->toBeTrue();
})->with(['user', 'shared']);

it('denies unsafe or incomplete documentation from the user corpus', function (array $metadata): void {
    $policy = new DocumentAudiencePolicy('in-app-docs-v1');

    expect($policy->allows(documentation_document($metadata), DocumentationIndexProfile::User))->toBeFalse();
})->with([
    'missing metadata' => [[]],
    'developer audience' => [valid_user_documentation_metadata('developer')],
    'missing permission declaration' => [array_diff_key(valid_user_documentation_metadata(), ['required_permissions' => true])],
    'invalid permission declaration' => [array_replace(valid_user_documentation_metadata(), ['required_permissions' => 'cms.content.view'])],
    'restricted classification' => [array_replace(valid_user_documentation_metadata(), ['policy_classification' => 'restricted'])],
    'stale policy version' => [array_replace(valid_user_documentation_metadata(), ['policy_classification_version' => 'legacy'])],
    'tenant scope without tenant' => [array_replace(valid_user_documentation_metadata(), ['tenant_scope' => 'tenant'])],
]);

it('never admits a document to the user corpus on the developer defaults alone', function (): void {
    $document = documentation_document(['heading_breadcrumb' => []])->setSourceName('faq-module-Core/LEGACY.md');

    DocumentationMetadata::applyDeveloperDefaults($document);

    expect($document->getMetadata()['audience'])->toBe('shared')
        ->and((new DocumentAudiencePolicy('in-app-docs-v1'))->allows($document, DocumentationIndexProfile::User))->toBeFalse()
        ->and((new DocumentAudiencePolicy('in-app-docs-v1'))->allows($document, DocumentationIndexProfile::Developer))->toBeTrue();
});

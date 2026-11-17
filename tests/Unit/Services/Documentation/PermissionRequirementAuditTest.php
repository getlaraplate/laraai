<?php

declare(strict_types=1);

use Modules\AI\Services\Documentation\PermissionRequirementAudit;
use NeuronAI\RAG\Document;

/**
 * @param  list<string>  $requirements
 */
function audit_document(string $source, array $requirements): Document
{
    return (new Document('Body.'))->setMetadata([
        'canonical_source' => $source,
        'required_permissions' => $requirements,
    ]);
}

const AUDIT_KNOWN = [
    'default.cms_contents.select',
    'default.cms_contents.delete',
    'default.erp_orders.update',
];

it('reports a requirement that no known permission satisfies', function (): void {
    $unmatched = PermissionRequirementAudit::unmatched(AUDIT_KNOWN, [
        audit_document('core/page.md', ['cms_contenst.*']),
    ]);

    expect($unmatched)->toBe([['document' => 'core/page.md', 'requirement' => 'cms_contenst.*']]);
});

it('does not report the valid forms', function (): void {
    $unmatched = PermissionRequirementAudit::unmatched(AUDIT_KNOWN, [
        audit_document('core/a.md', ['cms_contents.delete', 'cms_contents.*', '*.update', '*.*', 'default.erp_orders.update']),
    ]);

    expect($unmatched)->toBe([]);
});

it('reports partial wildcards, wrong case and unknown operations', function (): void {
    $unmatched = PermissionRequirementAudit::unmatched(AUDIT_KNOWN, [
        audit_document('core/a.md', ['erp_*.update', 'cms_contents.Delete', '*.forceDelete']),
    ]);

    expect(array_column($unmatched, 'requirement'))->toBe(['*.forceDelete', 'cms_contents.Delete', 'erp_*.update']);
});

it('does not report a document without requirements', function (): void {
    expect(PermissionRequirementAudit::unmatched(AUDIT_KNOWN, [audit_document('core/a.md', [])]))->toBe([]);
});

it('reports only the misspelled entry of a document that also has a valid one', function (): void {
    $unmatched = PermissionRequirementAudit::unmatched(AUDIT_KNOWN, [
        audit_document('core/a.md', ['cms_contents.*', 'cms_contenst.*']),
    ]);

    expect($unmatched)->toBe([['document' => 'core/a.md', 'requirement' => 'cms_contenst.*']]);
});

it('reports once per document and requirement and sorts by document, then requirement', function (): void {
    $unmatched = PermissionRequirementAudit::unmatched(AUDIT_KNOWN, [
        audit_document('core/b.md', ['zzz.*']),
        audit_document('core/a.md', ['yyy.*', 'xxx.*']),
        audit_document('core/b.md', ['zzz.*']),
    ]);

    expect($unmatched)->toBe([
        ['document' => 'core/a.md', 'requirement' => 'xxx.*'],
        ['document' => 'core/a.md', 'requirement' => 'yyy.*'],
        ['document' => 'core/b.md', 'requirement' => 'zzz.*'],
    ]);
});

it('reports every requirement when no permission is known', function (): void {
    expect(PermissionRequirementAudit::unmatched([], [audit_document('core/a.md', ['cms_contents.*'])]))
        ->toBe([['document' => 'core/a.md', 'requirement' => 'cms_contents.*']]);
});

it('names a document without a canonical source by its position', function (): void {
    $document = (new Document('Body.'))->setMetadata(['required_permissions' => ['nope.*']]);

    expect(PermissionRequirementAudit::unmatched(AUDIT_KNOWN, [$document]))
        ->toBe([['document' => '(unnamed document)', 'requirement' => 'nope.*']]);
});

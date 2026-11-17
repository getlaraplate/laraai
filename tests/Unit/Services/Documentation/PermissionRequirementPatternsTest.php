<?php

declare(strict_types=1);

use Modules\AI\Services\Documentation\PermissionRequirementPatterns;

it('derives the four short requirements a three-segment permission satisfies', function (): void {
    expect(PermissionRequirementPatterns::satisfiedBy(['default.cms_contents.delete']))->toBe([
        '*.*',
        '*.delete',
        'cms_contents.*',
        'cms_contents.delete',
        'default.cms_contents.delete',
    ]);
});

it('keeps every original name so the long form still matches', function (): void {
    $names = ['default.cms_contents.select', 'default.erp_orders.update'];

    expect(PermissionRequirementPatterns::satisfiedBy($names))->toContain(...$names);
});

it('passes a name without three segments through and gives it no short form', function (): void {
    expect(PermissionRequirementPatterns::satisfiedBy(['cms.content.view.extra', 'single']))
        ->toBe(['cms.content.view.extra', 'single']);
});

it('compares segments exactly, capitals included', function (): void {
    $satisfied = PermissionRequirementPatterns::satisfiedBy(['default.erp_invoices.forceDelete']);

    expect($satisfied)->toContain('erp_invoices.forceDelete', '*.forceDelete')
        ->and($satisfied)->not->toContain('erp_invoices.forcedelete', '*.forcedelete');
});

it('never produces or matches a partial wildcard', function (): void {
    $satisfied = PermissionRequirementPatterns::satisfiedBy(['default.erp_orders.update']);

    expect($satisfied)->not->toContain('erp_*.update', 'erp_*.*', '*_orders.update', 'erp_*');
});

it('returns a sorted list without duplicates', function (): void {
    $satisfied = PermissionRequirementPatterns::satisfiedBy([
        'default.cms_contents.update',
        'default.cms_contents.delete',
        'default.cms_contents.update',
    ]);

    expect($satisfied)->toBe(array_values(array_unique($satisfied)))
        ->and($satisfied)->toBe((static function () use ($satisfied): array {
            $sorted = $satisfied;
            sort($sorted, SORT_STRING);

            return $sorted;
        })())
        ->and(array_count_values($satisfied)['cms_contents.*'])->toBe(1);
});

it('returns an empty list for no permissions', function (): void {
    expect(PermissionRequirementPatterns::satisfiedBy([]))->toBe([]);
});

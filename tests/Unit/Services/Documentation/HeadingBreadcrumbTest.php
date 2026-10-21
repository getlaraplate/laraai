<?php

declare(strict_types=1);

use Modules\AI\Services\Documentation\HeadingBreadcrumb;

it('joins a list of headings with a separator', function (): void {
    expect(HeadingBreadcrumb::fromMetadata(['Approvals', 'Approve a modification']))->toBe('Approvals > Approve a modification');
});

it('takes a string as it is, trimmed', function (): void {
    expect(HeadingBreadcrumb::fromMetadata('  Settings > Profile '))->toBe('Settings > Profile');
});

it('gives nothing for an empty or unusable value', function (mixed $value): void {
    expect(HeadingBreadcrumb::fromMetadata($value))->toBeNull();
})->with([
    'empty list' => [[]],
    'list with a non-string' => [['Approvals', 3]],
    'list with an empty heading' => [['Approvals', ' ']],
    'keyed array' => [['a' => 'Approvals']],
    'empty string' => ['  '],
    'null' => [null],
    'int' => [7],
]);

<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation;

/**
 * Reads the heading breadcrumb of a documentation chunk from its metadata. The index stores it as a
 * list of headings; a plain string is taken as it is.
 */
final class HeadingBreadcrumb
{
    private const string SEPARATOR = ' > ';

    public static function fromMetadata(mixed $value): ?string
    {
        if (is_array($value) && array_is_list($value) && $value !== []
            && array_all($value, static fn (mixed $item): bool => is_string($item) && mb_trim($item) !== '')) {
            return implode(self::SEPARATOR, $value);
        }

        if (is_string($value) && mb_trim($value) !== '') {
            return mb_trim($value);
        }

        return null;
    }
}

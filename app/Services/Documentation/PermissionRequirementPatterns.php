<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation;

/**
 * Short-form permission requirements of a user document.
 *
 * A requirement is written `<table>.<operation>`, each segment a literal name or `*` for the whole segment
 * (`cms_contents.delete`, `cms_contents.*`, `*.delete`, `*.*`). The connection segment of the permission name
 * (`default.`) is left out; the long form still matches. A requirement that is misspelled or malformed is
 * satisfied by nobody.
 */
final readonly class PermissionRequirementPatterns
{
    private const int NAME_SEGMENTS = 3;

    /**
     * The requirement strings satisfied by a set of concrete permission names.
     *
     * Every name is kept as it is. A name of exactly three segments `{connection}.{table}.{operation}` adds the
     * four short forms it satisfies. Segments are compared exactly, case included.
     *
     * @param  list<string>  $permissions
     * @return list<string> sorted, unique
     */
    public static function satisfiedBy(array $permissions): array
    {
        $satisfied = [];

        foreach ($permissions as $permission) {
            $satisfied[$permission] = true;

            $segments = explode('.', $permission);

            if (count($segments) !== self::NAME_SEGMENTS) {
                continue;
            }

            [, $table, $operation] = $segments;

            if ($table === '' || $operation === '') {
                continue;
            }

            foreach (["{$table}.{$operation}", "{$table}.*", "*.{$operation}", '*.*'] as $requirement) {
                $satisfied[$requirement] = true;
            }
        }

        $names = array_map(strval(...), array_keys($satisfied));
        sort($names, SORT_STRING);

        return $names;
    }
}

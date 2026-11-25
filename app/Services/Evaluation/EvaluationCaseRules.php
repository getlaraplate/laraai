<?php

declare(strict_types=1);

namespace Modules\AI\Services\Evaluation;

/**
 * What every case of an evaluation dataset must satisfy, whatever the dataset measures: an identity
 * (a slug id, a question of bounded length, a locale) and lists of bounded, non-blank, unique texts.
 */
final class EvaluationCaseRules
{
    public const int MAX_QUERY_LENGTH = 2000;

    public static function isValidIdentity(string $id, string $query, string $locale): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/', $id) === 1
            && mb_trim($query) !== ''
            && mb_strlen($query) <= self::MAX_QUERY_LENGTH
            && preg_match('/^[a-z]{2,3}(?:[-_][A-Z]{2})?$/', $locale) === 1;
    }

    /**
     * @param  array<mixed>  $values
     */
    public static function isValidStringList(array $values, int $maximumLength): bool
    {
        if (! array_is_list($values) || count(array_unique($values)) !== count($values)) {
            return false;
        }

        foreach ($values as $value) {
            if (! is_string($value) || mb_trim($value) === '' || $maximumLength < mb_strlen($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A list of lower case slugs, the form of a slice name.
     *
     * @param  array<mixed>  $values
     */
    public static function isValidSlugList(array $values, int $maximumLength): bool
    {
        if (! self::isValidStringList($values, $maximumLength)) {
            return false;
        }

        foreach ($values as $value) {
            if (! is_string($value) || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $value) !== 1) {
                return false;
            }
        }

        return true;
    }
}

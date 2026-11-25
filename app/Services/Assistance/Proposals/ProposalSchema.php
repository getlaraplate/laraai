<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Proposals;

/**
 * The JSON Schema subset a client may declare for a value it lets the assistant propose.
 *
 * Fail closed: a schema that uses a keyword outside the subset, or that does not constrain the
 * value at all, is not supported, and a target declared with it cannot be proposed. The subset
 * is `type`, `enum`, `const`, `minimum`, `maximum`, `exclusiveMinimum`, `exclusiveMaximum`,
 * `minLength`, `maxLength`, `minItems`, `maxItems`, `items`, `properties`, `required` and
 * `additionalProperties` (a boolean); `title`, `description`, `default`, `examples` and
 * `$comment` are annotations and ignored. There is no `pattern`: a regular expression written by
 * a client is not run on the server.
 */
final class ProposalSchema
{
    public const int MAX_BYTES = 2000;

    public const int MAX_DEPTH = 4;

    private const array ANNOTATIONS = ['title', 'description', 'default', 'examples', '$comment'];

    private const array TYPES = ['string', 'number', 'integer', 'boolean', 'null', 'array', 'object'];

    /**
     * @param  array<array-key, mixed>  $schema
     */
    public static function isSupported(array $schema): bool
    {
        $encoded = json_encode($schema);

        if ($encoded === false || mb_strlen($encoded) > self::MAX_BYTES) {
            return false;
        }

        $constrains = array_key_exists('type', $schema) || array_key_exists('enum', $schema) || array_key_exists('const', $schema);

        return $constrains && self::nodeIsSupported($schema, 1);
    }

    /**
     * Whether the value satisfies a supported schema. An unsupported schema accepts nothing.
     *
     * @param  array<array-key, mixed>  $schema
     */
    public static function accepts(array $schema, mixed $value): bool
    {
        return self::isSupported($schema) && self::valid($schema, $value);
    }

    /**
     * @param  array<array-key, mixed>  $schema
     */
    private static function nodeIsSupported(array $schema, int $depth): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return false;
        }

        foreach ($schema as $keyword => $argument) {
            if (! is_string($keyword) || in_array($keyword, self::ANNOTATIONS, true)) {
                continue;
            }

            $supported = match ($keyword) {
                'type' => self::typeIsSupported($argument),
                'enum' => is_array($argument) && array_is_list($argument) && $argument !== [] && count($argument) <= 50,
                'const' => true,
                'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum' => is_int($argument) || is_float($argument),
                'minLength', 'maxLength', 'minItems', 'maxItems' => is_int($argument) && $argument >= 0,
                'items' => is_array($argument) && ! array_is_list($argument) && self::nodeIsSupported($argument, $depth + 1),
                'properties' => is_array($argument) && self::propertiesAreSupported($argument, $depth),
                'required' => is_array($argument) && array_is_list($argument) && $argument === array_filter($argument, is_string(...)),
                'additionalProperties' => is_bool($argument),
                default => false,
            };

            if (! $supported) {
                return false;
            }
        }

        return true;
    }

    private static function typeIsSupported(mixed $type): bool
    {
        if (is_string($type)) {
            return in_array($type, self::TYPES, true);
        }

        return is_array($type) && array_is_list($type) && $type !== [] && array_diff($type, self::TYPES) === [];
    }

    /**
     * @param  array<array-key, mixed>  $properties
     */
    private static function propertiesAreSupported(array $properties, int $depth): bool
    {
        foreach ($properties as $name => $property) {
            if (! is_string($name) || ! is_array($property) || ! self::nodeIsSupported($property, $depth + 1)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<array-key, mixed>  $schema
     */
    private static function valid(array $schema, mixed $value): bool
    {
        if (array_key_exists('type', $schema) && ! self::matchesType($schema['type'], $value)) {
            return false;
        }

        if (array_key_exists('enum', $schema) && ! in_array($value, $schema['enum'], true)) {
            return false;
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            return false;
        }

        return self::numberIsValid($schema, $value)
            && self::stringIsValid($schema, $value)
            && self::listIsValid($schema, $value)
            && self::objectIsValid($schema, $value);
    }

    private static function matchesType(mixed $types, mixed $value): bool
    {
        foreach ((array) $types as $type) {
            $matches = match ($type) {
                'string' => is_string($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                'integer' => is_int($value) || (is_float($value) && is_finite($value) && $value === floor($value)),
                'number' => is_int($value) || (is_float($value) && is_finite($value)),
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) && ! array_is_list($value),
                default => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $schema
     */
    private static function numberIsValid(array $schema, mixed $value): bool
    {
        if (! is_int($value) && ! is_float($value)) {
            return true;
        }

        return (! isset($schema['minimum']) || $value >= $schema['minimum'])
            && (! isset($schema['maximum']) || $value <= $schema['maximum'])
            && (! isset($schema['exclusiveMinimum']) || $value > $schema['exclusiveMinimum'])
            && (! isset($schema['exclusiveMaximum']) || $value < $schema['exclusiveMaximum']);
    }

    /**
     * @param  array<array-key, mixed>  $schema
     */
    private static function stringIsValid(array $schema, mixed $value): bool
    {
        if (! is_string($value)) {
            return true;
        }

        $length = mb_strlen($value);

        return (! isset($schema['minLength']) || $length >= $schema['minLength'])
            && (! isset($schema['maxLength']) || $length <= $schema['maxLength']);
    }

    /**
     * @param  array<array-key, mixed>  $schema
     */
    private static function listIsValid(array $schema, mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return true;
        }

        if ((isset($schema['minItems']) && $schema['minItems'] > count($value))
            || (isset($schema['maxItems']) && $schema['maxItems'] < count($value))) {
            return false;
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $item) {
                if (! self::valid($schema['items'], $item)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<array-key, mixed>  $schema
     */
    private static function objectIsValid(array $schema, mixed $value): bool
    {
        if (! is_array($value) || array_is_list($value) && $value !== []) {
            return true;
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        foreach ((array) ($schema['required'] ?? []) as $name) {
            if (! (is_int($name) || is_string($name)) || ! array_key_exists($name, $value)) {
                return false;
            }
        }

        foreach ($value as $name => $property) {
            if (array_key_exists($name, $properties)) {
                if (! is_array($properties[$name]) || ! self::valid($properties[$name], $property)) {
                    return false;
                }
            } elseif (($schema['additionalProperties'] ?? true) === false) {
                return false;
            }
        }

        return true;
    }
}

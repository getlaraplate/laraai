<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs;

use RuntimeException;

/**
 * The OpenAPI document of a server the AI module talks to (Text Embeddings Inference, Infinity, speaches), as
 * copied in `tests/Fixtures/StandardServers` with the version it comes from, and the little JSON Schema the
 * contract test needs. Any keyword it does not check or know as an annotation fails the check, so a subtree is
 * never left unchecked without a word. Strict on objects: a field the schema does not declare is a violation unless
 * it allows additional properties, because these servers ignore unknown fields silently and a typo would then
 * never be noticed. `oneOf` and `anyOf` hold when at least one alternative does.
 */
final readonly class OpenApiContract
{
    /**
     * @var list<string>
     */
    private const array KEYWORDS = [
        '$ref', 'type', 'required', 'properties', 'additionalProperties', 'items', 'enum', 'const', 'pattern',
        'oneOf', 'anyOf', 'allOf', 'nullable', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum',
        'minLength', 'maxLength', 'minItems', 'maxItems',
        'description', 'default', 'format', 'title', 'example', 'examples', 'deprecated', 'readOnly', 'writeOnly',
    ];

    /**
     * @param  array<string, mixed>  $document
     */
    private function __construct(
        private array $document,
        private string $version,
    ) {}

    /**
     * @param  string  $server  `tei`, `infinity` or `speaches`
     */
    public static function load(string $server): self
    {
        $directory = dirname(__DIR__) . '/Fixtures/StandardServers/';
        $document = json_decode((string) file_get_contents($directory . $server . '.openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        $version = mb_trim((string) file_get_contents($directory . $server . '.version'));

        return new self($document, $version);
    }

    /**
     * The release of the server the fixture was copied from.
     */
    public function version(): string
    {
        return $this->version;
    }

    /**
     * @return array<string, mixed>
     */
    public function requestSchema(string $path, string $method, string $contentType = 'application/json'): array
    {
        return $this->resolve($this->operation($path, $method)['requestBody']['content'][$contentType]['schema']
            ?? throw new RuntimeException("{$method} {$path} has no {$contentType} request body."));
    }

    /**
     * @return array<string, mixed>
     */
    public function responseSchema(string $path, string $method, int $status = 200): array
    {
        return $this->resolve($this->operation($path, $method)['responses'][(string) $status]['content']['application/json']['schema']
            ?? throw new RuntimeException("{$method} {$path} {$status} has no JSON schema."));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(string $name): array
    {
        return $this->resolve(['$ref' => '#/components/schemas/' . $name]);
    }

    /**
     * The schema with its `$ref` followed, for a test that reads a subschema of an answer.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function resolveForTest(array $schema): array
    {
        return $this->resolve($schema);
    }

    /**
     * Whether an object schema (or one of the alternatives of a union) declares `$field`.
     *
     * @param  array<string, mixed>  $schema
     */
    public function declares(array $schema, string $field): bool
    {
        return array_key_exists($field, $schema['properties'] ?? []);
    }

    /**
     * Whether an object schema requires `$field`.
     *
     * @param  array<string, mixed>  $schema
     */
    public function requires(array $schema, string $field): bool
    {
        return in_array($field, $schema['required'] ?? [], true);
    }

    /**
     * A value that conforms to `$schema`: the declared example where there is one and it conforms, else the simplest
     * value of the type. With `$requiredOnly`, an object carries only the fields the schema requires, at every depth: the least a
     * server may answer.
     *
     * @param  array<string, mixed>  $schema
     */
    public function sample(array $schema, bool $requiredOnly = false): mixed
    {
        $schema = $this->resolve($schema);

        // Some documents type their examples loosely (`"example": "0"` for an integer): such an example is not a sample.
        if (array_key_exists('example', $schema) && $this->violations($schema, $schema['example']) === []) {
            return $schema['example'];
        }

        foreach (['oneOf', 'anyOf'] as $union) {
            if (isset($schema[$union])) {
                foreach ($schema[$union] as $alternative) {
                    $resolved = $this->resolve($alternative);

                    if (($resolved['type'] ?? null) !== 'null') {
                        return $this->sample($resolved, $requiredOnly);
                    }
                }
            }
        }

        if (isset($schema['allOf'])) {
            return $this->sample($schema['allOf'][0], $requiredOnly);
        }

        if (array_key_exists('const', $schema)) {
            return $schema['const'];
        }

        if (isset($schema['enum'])) {
            return $schema['enum'][0];
        }

        $type = (array) ($schema['type'] ?? 'null');
        $type = array_values(array_filter($type, static fn (string $name): bool => $name !== 'null'))[0] ?? 'null';

        return match ($type) {
            'object' => $this->sampleObject($schema, $requiredOnly),
            'array' => [$this->sample($schema['items'] ?? ['type' => 'string'], $requiredOnly)],
            'string' => str_repeat('x', max(1, (int) ($schema['minLength'] ?? 1))),
            'integer' => (int) ($schema['minimum'] ?? 0),
            'number' => (float) max(0.5, (float) ($schema['minimum'] ?? 0.5)),
            'boolean' => true,
            default => null,
        };
    }

    /**
     * What in `$value` the schema does not allow, one message per problem; empty when it conforms.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public function violations(array $schema, mixed $value, string $at = '$'): array
    {
        $schema = $this->resolve($schema);
        $unsupported = array_diff(array_keys($schema), self::KEYWORDS);

        if ($unsupported !== []) {
            throw new RuntimeException('Schema keyword ' . implode(', ', $unsupported) . " at {$at} is not supported by this helper: teach it before relying on it.");
        }

        foreach (['oneOf', 'anyOf'] as $union) {
            if (isset($schema[$union])) {
                $alternatives = array_map(fn (array $alternative): array => $this->violations($alternative, $value, $at), $schema[$union]);

                if (! in_array([], $alternatives, true)) {
                    return ["{$at} matches none of the alternatives: " . implode(' | ', array_map(static fn (array $found): string => implode('; ', $found), $alternatives))];
                }
            }
        }

        $violations = [];

        foreach ($schema['allOf'] ?? [] as $part) {
            array_push($violations, ...$this->violations($part, $value, $at));
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            return ["{$at} must be " . json_encode($schema['const'])];
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return ["{$at} must be one of " . json_encode($schema['enum'])];
        }

        if (isset($schema['type'])) {
            $types = (array) $schema['type'];

            if (($schema['nullable'] ?? false) === true) {
                $types[] = 'null';
            }

            $matched = array_find($types, static fn (string $type): bool => self::isOfType($value, $type));

            if ($matched === null) {
                return [...$violations, "{$at} must be " . implode('|', $types)];
            }

            array_push($violations, ...$this->constraintViolations($schema, $matched, $value, $at));

            if ($matched === 'object') {
                array_push($violations, ...$this->objectViolations($schema, $value, $at));
            }

            if ($matched === 'array' && isset($schema['items'])) {
                foreach ($value as $index => $item) {
                    array_push($violations, ...$this->violations($schema['items'], $item, "{$at}[{$index}]"));
                }
            }
        }

        return $violations;
    }

    private static function isOfType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => throw new RuntimeException("Unsupported schema type {$type}."),
        };
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function constraintViolations(array $schema, string $type, mixed $value, string $at): array
    {
        $violations = [];

        if ($type === 'string') {
            if (isset($schema['pattern']) && preg_match('~' . str_replace('~', '\~', $schema['pattern']) . '~u', $value) !== 1) {
                $violations[] = "{$at} must match {$schema['pattern']}";
            }

            if (isset($schema['minLength']) && $schema['minLength'] > mb_strlen($value)) {
                $violations[] = "{$at} must have at least {$schema['minLength']} characters";
            }

            if (isset($schema['maxLength']) && $schema['maxLength'] < mb_strlen($value)) {
                $violations[] = "{$at} must have at most {$schema['maxLength']} characters";
            }
        }

        if ($type === 'integer' || $type === 'number') {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $violations[] = "{$at} must be at least {$schema['minimum']}";
            }

            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $violations[] = "{$at} must be at most {$schema['maximum']}";
            }

            if (isset($schema['exclusiveMinimum']) && is_numeric($schema['exclusiveMinimum']) && $value <= $schema['exclusiveMinimum']) {
                $violations[] = "{$at} must be above {$schema['exclusiveMinimum']}";
            }

            if (isset($schema['exclusiveMaximum']) && is_numeric($schema['exclusiveMaximum']) && $value >= $schema['exclusiveMaximum']) {
                $violations[] = "{$at} must be below {$schema['exclusiveMaximum']}";
            }
        }

        if ($type === 'array') {
            if (isset($schema['minItems']) && $schema['minItems'] > count($value)) {
                $violations[] = "{$at} must have at least {$schema['minItems']} items";
            }

            if (isset($schema['maxItems']) && $schema['maxItems'] < count($value)) {
                $violations[] = "{$at} must have at most {$schema['maxItems']} items";
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $value
     * @return list<string>
     */
    private function objectViolations(array $schema, array $value, string $at): array
    {
        $violations = [];
        $properties = $schema['properties'] ?? [];
        $additional = $schema['additionalProperties'] ?? false;

        foreach ($schema['required'] ?? [] as $required) {
            if (! array_key_exists($required, $value)) {
                $violations[] = "{$at}.{$required} is required";
            }
        }

        foreach ($value as $field => $item) {
            if (array_key_exists($field, $properties)) {
                array_push($violations, ...$this->violations($properties[$field], $item, "{$at}.{$field}"));
            } elseif ($additional === false) {
                $violations[] = "{$at}.{$field} is not declared";
            } elseif (is_array($additional)) {
                array_push($violations, ...$this->violations($additional, $item, "{$at}.{$field}"));
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function sampleObject(array $schema, bool $requiredOnly): array
    {
        $sample = [];

        foreach ($schema['properties'] ?? [] as $field => $property) {
            if (! $requiredOnly || in_array($field, $schema['required'] ?? [], true)) {
                $sample[$field] = $this->sample($property, $requiredOnly);
            }
        }

        return $sample;
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(string $path, string $method): array
    {
        return $this->document['paths'][$path][$method] ?? throw new RuntimeException("The document has no {$method} {$path}.");
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function resolve(array $schema): array
    {
        while (isset($schema['$ref'])) {
            $name = str_replace('#/components/schemas/', '', $schema['$ref']);
            $schema = $this->document['components']['schemas'][$name] ?? throw new RuntimeException("Unknown schema {$schema['$ref']}.");
        }

        return $schema;
    }
}

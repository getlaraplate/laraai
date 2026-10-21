<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs;

use RuntimeException;

/**
 * The OpenAPI document of a self-hosted AI service, as copied in `tests/Fixtures/SelfHostedServices`, with
 * the little JSON Schema the contract test needs: `type`, `required`, `properties`, `additionalProperties`,
 * `items`, `enum`, `const` and local `$ref`s. Strict on objects: a field the schema does not declare is a
 * violation unless the schema allows additional properties, because the services ignore unknown fields
 * silently and a typo would then never be noticed.
 */
final readonly class SelfHostedServiceContract
{
    /**
     * @param  array<string, mixed>  $document
     */
    private function __construct(
        private array $document,
        private string $version,
    ) {}

    /**
     * @param  string  $service  `sentence-transformers-api` or `whisper-api`
     */
    public static function load(string $service): self
    {
        $directory = dirname(__DIR__) . '/Fixtures/SelfHostedServices/';
        $document = json_decode((string) file_get_contents($directory . $service . '.openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        $version = mb_trim((string) file_get_contents($directory . $service . '.version'));

        return new self($document, $version);
    }

    /**
     * The service `VERSION` the fixture was copied from.
     */
    public function version(): string
    {
        return $this->version;
    }

    public function documentVersion(): string
    {
        return $this->document['info']['version'];
    }

    public function apiVersion(): int
    {
        return $this->document['info']['x-api-version'];
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
    public function responseSchema(string $path, string $method, int $status): array
    {
        return $this->resolve($this->response($path, $method, $status)['schema']
            ?? throw new RuntimeException("{$method} {$path} {$status} has no schema."));
    }

    /**
     * @return array<string, mixed>
     */
    public function responseExample(string $path, string $method, int $status): array
    {
        return $this->response($path, $method, $status)['example']
            ?? throw new RuntimeException("{$method} {$path} {$status} has no example.");
    }

    /**
     * Whether an object schema declares `$field`.
     *
     * @param  array<string, mixed>  $schema
     */
    public function declares(array $schema, string $field): bool
    {
        return array_key_exists($field, $schema['properties'] ?? []);
    }

    /**
     * `$value` reduced to what `$schema` requires, at every depth: the least a service may answer.
     *
     * @param  array<string, mixed>  $schema
     */
    public function requiredOnly(array $schema, mixed $value): mixed
    {
        $schema = $this->resolve($schema);

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value) && $value !== []) {
            return isset($schema['items'])
                ? array_map(fn (mixed $item): mixed => $this->requiredOnly($schema['items'], $item), $value)
                : $value;
        }

        if (! isset($schema['properties'])) {
            return $value;
        }

        $reduced = [];

        foreach ($schema['required'] ?? [] as $required) {
            if (array_key_exists($required, $value)) {
                $reduced[$required] = $this->requiredOnly($schema['properties'][$required] ?? [], $value[$required]);
            }
        }

        return $reduced;
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

        if (isset($schema['const']) && $value !== $schema['const']) {
            return ["{$at} must be " . json_encode($schema['const'])];
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return ["{$at} must be one of " . json_encode($schema['enum'])];
        }

        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            $matched = array_find($types, static fn (string $type): bool => self::isOfType($value, $type));

            if ($matched === null) {
                return ["{$at} must be " . implode('|', $types)];
            }

            if ($matched === 'object') {
                return $this->objectViolations($schema, $value, $at);
            }

            if ($matched === 'array' && isset($schema['items'])) {
                $violations = [];

                foreach ($value as $index => $item) {
                    array_push($violations, ...$this->violations($schema['items'], $item, "{$at}[{$index}]"));
                }

                return $violations;
            }
        }

        return [];
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
     * @return array<string, mixed>
     */
    private function operation(string $path, string $method): array
    {
        return $this->document['paths'][$path][$method] ?? throw new RuntimeException("The document has no {$method} {$path}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function response(string $path, string $method, int $status): array
    {
        return $this->operation($path, $method)['responses'][(string) $status]['content']['application/json']
            ?? throw new RuntimeException("{$method} {$path} has no JSON {$status} answer.");
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

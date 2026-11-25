<?php

declare(strict_types=1);

namespace Modules\AI\Services\Evaluation;

use InvalidArgumentException;
use JsonException;

/**
 * The strict reading of an evaluation dataset that every dataset of the module shares: a bounded JSON
 * file, an exact set of keys, and typed values. A dataset is data that someone authored by hand, so
 * nothing in it is trusted until it has been read here. The messages name the kind of dataset (the
 * label) and say nothing of the content, which can be private.
 */
final readonly class EvaluationDatasetReader
{
    private const int MAX_BYTES = 2_000_000;

    private const int MAX_DEPTH = 32;

    /**
     * @param  string  $label  the kind of dataset the messages speak of, for example `Documentation`
     */
    public function __construct(private string $label) {}

    public static function isValidRevision(string $value): bool
    {
        return mb_trim($value) !== ''
            && mb_strlen($value) <= 200
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $value) === 1;
    }

    /**
     * The decoded content of a dataset file: a readable file of at most 2,000,000 bytes holding JSON
     * nested at most 32 levels, with an array at its root.
     *
     * @return array<array-key, mixed>
     */
    public function readFile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("{$this->label} evaluation dataset is unavailable.");
        }

        $contents = file_get_contents($path);

        if (! is_string($contents) || mb_strlen($contents, '8bit') > self::MAX_BYTES) {
            throw $this->invalid('dataset');
        }

        try {
            $data = json_decode($contents, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->invalid('dataset');
        }

        if (! is_array($data)) {
            throw $this->invalid('dataset');
        }

        return $data;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $keys
     */
    public function assertExactKeys(array $data, array $keys): void
    {
        $actual = array_keys($data);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);

        if ($actual !== $keys) {
            throw $this->invalid('schema');
        }
    }

    /**
     * Every required key and no key outside the required and optional ones.
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $required
     * @param  list<string>  $optional
     */
    public function assertKeys(array $data, array $required, array $optional): void
    {
        $actual = array_keys($data);

        if (array_diff($required, $actual) !== [] || array_diff($actual, $required, $optional) !== []) {
            throw $this->invalid('schema');
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw $this->invalid('value');
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value)) {
            throw $this->invalid('value');
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function boolean(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;

        if (! is_bool($value)) {
            throw $this->invalid('value');
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    public function stringList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            throw $this->invalid('value');
        }

        $strings = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw $this->invalid('value');
            }

            $strings[] = $item;
        }

        return $strings;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>|null
     */
    public function optionalObject(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        if ($value !== null && (! is_array($value) || array_is_list($value))) {
            throw $this->invalid('value');
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function optionalInteger(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        if ($value !== null && ! is_int($value)) {
            throw $this->invalid('value');
        }

        return $value;
    }

    private function invalid(string $what): InvalidArgumentException
    {
        return new InvalidArgumentException("{$this->label} evaluation {$what} is invalid.");
    }
}

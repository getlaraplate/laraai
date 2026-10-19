<?php

declare(strict_types=1);

namespace Modules\AI\Services\Tools;

use NeuronAI\Exceptions\InvalidToolInput;
use NeuronAI\ScalarCaster;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolPropertyInterface;

final readonly class SchemaToolProperty implements ToolPropertyInterface
{
    /**
     * @param  list<mixed>  $enum
     * @param  array<string, mixed>  $constraints
     */
    public function __construct(
        private string $name,
        private PropertyType $type,
        private string $description,
        private bool $required,
        private array $enum = [],
        private array $constraints = [],
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): PropertyType
    {
        return $this->type;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function isNullable(): bool
    {
        return false;
    }

    /**
     * What the model sends in the wrong type is converted when nothing is lost ("42" to 42, 42 to "42",
     * "true" to true), as Neuron converts its own scalar properties. Unlike them, a value that cannot be
     * converted is not refused here: it reaches the tool unchanged, as it did before Neuron 4. A value
     * outside the enum of the property is refused, and the model is told which values are allowed.
     */
    public function cast(mixed $input): mixed
    {
        if ($this->type === PropertyType::ARRAY || $this->type === PropertyType::OBJECT) {
            return $input;
        }

        $value = ScalarCaster::cast($input, $this->type->value) ?? $input;

        if ($this->enum !== [] && ! in_array($value, $this->enum, true)) {
            throw new InvalidToolInput('must be one of ' . $this->quote($this->enum) . '; ' . $this->quote([$value]) . ' given');
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function getJsonSchema(): array
    {
        return array_filter([
            'type' => $this->type->value,
            'description' => $this->description,
            'enum' => $this->enum,
            ...$this->constraints,
        ], static fn (mixed $value): bool => $value !== []);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            ...$this->getJsonSchema(),
            'required' => $this->required,
        ];
    }

    /**
     * @param  list<mixed>  $values
     */
    private function quote(array $values): string
    {
        return implode(', ', array_map(
            static fn (mixed $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '?',
            $values,
        ));
    }
}

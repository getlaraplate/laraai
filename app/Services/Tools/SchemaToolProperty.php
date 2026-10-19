<?php

declare(strict_types=1);

namespace Modules\AI\Services\Tools;

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
     * converted is not refused here: it reaches the tool unchanged, as it did before Neuron 4.
     */
    public function cast(mixed $input): mixed
    {
        return match ($this->type) {
            PropertyType::ARRAY, PropertyType::OBJECT => $input,
            default => ScalarCaster::cast($input, $this->type->value) ?? $input,
        };
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
}

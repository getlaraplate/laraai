<?php

declare(strict_types=1);

namespace Modules\AI\Services\Tools;

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

    public function cast(mixed $input): mixed
    {
        return $input;
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

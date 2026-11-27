<?php

declare(strict_types=1);

namespace Modules\AI\Services\Tools;

/**
 * DTO for a registered tool (name, description, parameters, risk level, handler).
 *
 * @phpstan-type ParameterShape array{name: string, type: string, description: string, required?: bool, enum?: list<mixed>, minimum?: int|float, maximum?: int|float, minLength?: int, maxLength?: int, minItems?: int, maxItems?: int, items?: array<string, mixed>}
 */
final readonly class ToolDefinition
{
    /**
     * @param  ParameterShape[]  $parameters
     * @param  callable  $handler  called by Neuron with the tool's parameters as named arguments
     * @param  int|null  $maxRuns  how many times the model may call the tool in one turn; null leaves Neuron's default
     * @param  string|null  $entity  `module.entity` when the tool acts on one entity, so the person can be told what the assistant may do
     * @param  string|null  $operation  the operation on that entity (`list`, `update`, ...)
     * @param  (callable(string): mixed)|null  $onApproval  when set, the tool waits for a person's decision
     *                                                      (Neuron's tool approval): `$handler` then runs when the
     *                                                      run pauses, to prepare what the person decides on, and
     *                                                      `$onApproval` runs with the call id once it is approved
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
        public string $riskLevel,
        public mixed $handler,
        public ?int $maxRuns = null,
        public ?string $entity = null,
        public ?string $operation = null,
        public mixed $onApproval = null,
    ) {}

    /**
     * Whether a call waits for a person's decision before it is carried out.
     */
    public function requiresApproval(): bool
    {
        return $this->onApproval !== null;
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Services\Tools;

use Closure;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Assistance\Policies\ToolNameMatcher;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;

/**
 * Converts request-local ToolDefinition DTOs, built by the contextual tool providers, into
 * NeuronAI Tool instances for agent consumption. It holds no tools of its own.
 */
final class ToolRegistry
{
    /**
     * Build request-local tools without adding them to the global action registry.
     *
     * @return list<Tool>
     */
    public function getContextualNeuronTools(
        ContextualToolProviderInterface $provider,
        AssistantAccessContext $context,
        ?array $allowedToolNames = null,
        array $deniedToolNames = [],
    ): array {
        $definitions = $provider->tools($context);

        if ($allowedToolNames !== null) {
            $definitions = array_values(array_filter(
                $definitions,
                static fn (ToolDefinition $definition): bool => ToolNameMatcher::allows($definition->name, $allowedToolNames, $deniedToolNames),
            ));
        }

        return array_map(
            fn (ToolDefinition $definition): Tool => $this->buildNeuronTool($definition),
            $definitions,
        );
    }

    /**
     * @param  list<ToolDefinition>  $definitions
     * @param  list<string>|null  $allowedToolNames  exact names or trailing-wildcard patterns
     * @param  list<string>  $deniedToolNames  they override the allowed ones
     * @return list<Tool>
     */
    public function getNeuronToolsForDefinitions(array $definitions, ?array $allowedToolNames = null, array $deniedToolNames = []): array
    {
        if ($allowedToolNames !== null) {
            $definitions = array_values(array_filter(
                $definitions,
                static fn (ToolDefinition $definition): bool => ToolNameMatcher::allows($definition->name, $allowedToolNames, $deniedToolNames),
            ));
        }

        return array_map(
            fn (ToolDefinition $definition): Tool => $this->buildNeuronTool($definition),
            $definitions,
        );
    }

    private function buildNeuronTool(ToolDefinition $definition): Tool
    {
        $tool = new CallbackTool(
            $definition->name,
            $definition->description,
            Closure::fromCallable($definition->handler),
        );

        if ($definition->maxRuns !== null) {
            $tool->setMaxRuns($definition->maxRuns);
        }

        foreach ($definition->parameters as $param) {
            $tool->addProperty(
                new SchemaToolProperty(
                    name: $param['name'],
                    type: $this->mapPropertyType($param['type']),
                    description: $param['description'],
                    required: $param['required'] ?? true,
                    enum: $param['enum'] ?? [],
                    constraints: array_intersect_key($param, array_flip([
                        'minimum',
                        'maximum',
                        'minLength',
                        'maxLength',
                        'minItems',
                        'maxItems',
                        'items',
                    ])),
                ),
            );
        }

        return $tool;
    }

    private function mapPropertyType(string $type): PropertyType
    {
        return match ($type) {
            'integer', 'int' => PropertyType::INTEGER,
            'number', 'float', 'double' => PropertyType::NUMBER,
            'boolean', 'bool' => PropertyType::BOOLEAN,
            'array' => PropertyType::ARRAY,
            'object' => PropertyType::OBJECT,
            default => PropertyType::STRING,
        };
    }
}

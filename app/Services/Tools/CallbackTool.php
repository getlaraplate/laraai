<?php

declare(strict_types=1);

namespace Modules\AI\Services\Tools;

use Closure;
use NeuronAI\Tools\Tool;
use Override;
use ReflectionFunction;
use ReflectionParameter;

/**
 * A Neuron tool whose name, description and properties come from a {@see ToolDefinition} at run time
 * and whose logic is the definition's handler, which receives the tool's arguments by name.
 */
final class CallbackTool extends Tool
{
    public function __construct(string $name, string $description, private readonly Closure $handler)
    {
        $this->name = $name;
        $this->description = $description;
    }

    public function __invoke(mixed ...$arguments): mixed
    {
        return ($this->handler)(...$arguments);
    }

    /**
     * The parameters of the handler, not of the variadic {@see self::__invoke()}: Neuron leaves out
     * an argument the model did not send, or sent as null where the parameter cannot take it, so that
     * the handler applies its own default.
     *
     * @return array<string, ReflectionParameter>
     */
    #[Override]
    protected function invokeParameters(): array
    {
        $parameters = [];

        foreach ((new ReflectionFunction($this->handler))->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = $parameter;
        }

        return $parameters;
    }
}

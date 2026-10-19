<?php

declare(strict_types=1);

namespace Modules\AI\Services\Tools;

use Closure;
use NeuronAI\Tools\Tool;

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
}

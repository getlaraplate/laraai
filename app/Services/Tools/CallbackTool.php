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
    /**
     * @param  (Closure(string): mixed)|null  $onApproval  what runs, with the call id, when the tool is gated by
     *                                                     approval and Neuron carries out the approved call
     */
    public function __construct(
        string $name,
        string $description,
        private readonly Closure $handler,
        private readonly ?Closure $onApproval = null,
    ) {
        $this->name = $name;
        $this->description = $description;

        if ($onApproval instanceof Closure) {
            $this->requireApproval();
        }
    }

    /**
     * A tool gated by approval is only carried out by Neuron's tool step once the call was approved, and that
     * step always gives the call its id: then the outcome of the decision answers. Invoked without a call id,
     * outside a run, the tool does what its handler does, which for a write is to prepare the proposal.
     */
    public function __invoke(mixed ...$arguments): mixed
    {
        $call_id = $this->getCallId();

        if ($this->onApproval instanceof Closure && $call_id !== null && $call_id !== '') {
            return ($this->onApproval)($call_id);
        }

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

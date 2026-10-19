<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Assistance;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tools\ToolCall;
use Override;
use RuntimeException;

/**
 * Neuron's fake provider that, on its first call, answers like a model that asks for tools: it builds
 * the tool call from the tools the agent really configured (the fake can only be queued messages, and
 * the tools that the protected pipeline builds exist only inside a run). Later calls answer with the
 * queued messages, as the plain fake does.
 */
final class ToolCallingFakeProvider extends FakeAIProvider
{
    /**
     * @var list<array{0: string, 1: array<string, mixed>}>
     */
    private array $calls;

    /**
     * @param  list<array{0: string, 1: array<string, mixed>}>  $calls  tool name and inputs, in order
     */
    public function __construct(array $calls, Message ...$responses)
    {
        parent::__construct(...$responses);

        $this->calls = $calls;
    }

    #[Override]
    public function chat(Message ...$messages): ProviderResponse
    {
        if ($this->calls === []) {
            return parent::chat(...$messages);
        }

        $tools = [];

        foreach ($this->calls as $index => [$name, $inputs]) {
            $tool = collect($this->tools)->first(static fn ($candidate): bool => $name === $candidate->getName())
                ?? throw new RuntimeException("The agent has no tool named {$name}.");

            $tools[] = ToolCall::make(name: $tool->getName(), callId: 'call_' . ($index + 1), inputs: $inputs);
        }

        $this->calls = [];
        $this->recorded[] = new RequestRecord(method: 'chat', messages: $messages, systemPrompt: $this->systemPrompt, tools: $this->tools);

        return new ProviderResponse(message: new ToolCallMessage(null, $tools));
    }
}

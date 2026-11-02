<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Providers;

use NeuronAI\Agent\AgentInterface;
use NeuronAI\Chat\Messages\Usage;

/**
 * The tokens a provider reported for one or more model calls: what was sent (`$input`, the whole
 * prompt) and what the model wrote (`$output`).
 */
final readonly class TokenUsage
{
    public function __construct(
        public int $input,
        public int $output,
    ) {}

    /**
     * The usage of every model call the agent made, summed from the messages of its conversation (a
     * tool-calling answer makes several); null when the provider reported none.
     */
    public static function fromAgent(AgentInterface $agent): ?self
    {
        $total = null;

        foreach ($agent->getChatHistory()->getMessages() as $message) {
            $usage = $message->getUsage();

            if ($usage instanceof Usage) {
                $total = new self($usage->inputTokens, $usage->outputTokens)->plus($total);
            }
        }

        return $total;
    }

    public function plus(?self $other): self
    {
        return $other instanceof self ? new self($this->input + $other->input, $this->output + $other->output) : $this;
    }
}

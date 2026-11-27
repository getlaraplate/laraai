<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Evaluation\Live;

use Modules\AI\Services\Assistance\Policies\AssistanceOutputPolicy;
use NeuronAI\Evaluation\Conversation\Trajectory;

/**
 * What one case of a live evaluation produced: the message the person would read, with its metadata, and
 * the trajectory of the turn's run (the tool calls the model made), read from the conversation Neuron kept
 * for the turn.
 */
final readonly class LiveAssistantRun
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $content,
        public array $metadata,
        public Trajectory $trajectory,
    ) {}

    public function refused(): bool
    {
        return ($this->metadata['refused'] ?? null) === true;
    }

    /**
     * @return list<string>
     */
    public function citationLabels(): array
    {
        $labels = [];
        $citations = $this->metadata['citations'] ?? [];

        foreach (is_array($citations) ? $citations : [] as $citation) {
            if (is_array($citation) && is_string($citation['label'] ?? null)) {
                $labels[] = $citation['label'];
            }
        }

        return $labels;
    }

    public function proposalCount(): int
    {
        return is_array($this->metadata['proposals'] ?? null) ? count($this->metadata['proposals']) : 0;
    }

    public function writeCount(): int
    {
        return is_array($this->metadata['writes'] ?? null) ? count($this->metadata['writes']) : 0;
    }

    public function claimsChange(): bool
    {
        return AssistanceOutputPolicy::claimsChange($this->content);
    }

    /**
     * The names of the write tools the model called in the turn.
     *
     * @return list<string>
     */
    public function writeCalls(): array
    {
        $names = [];

        foreach ($this->trajectory->toolCalls() as $call) {
            if (preg_match('/^crud_(?:create|update|delete|bulk_update|bulk_delete)_/', $call->getName()) === 1) {
                $names[] = $call->getName();
            }
        }

        return $names;
    }
}

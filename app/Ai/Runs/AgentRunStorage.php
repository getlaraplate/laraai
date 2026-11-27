<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Runs;

use Modules\AI\Models\AgentMessage;
use Modules\AI\Models\AgentWorkflowRecord;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\EloquentMessageStore;
use NeuronAI\Workflow\Persistence\EloquentPersistence;

/**
 * Where a durable Neuron run lives: its records through `EloquentPersistence` and its conversation through
 * `EloquentMessageStore`, both on the application's own connection and in the module's own tables. A run is
 * durable when it may pause in one request and resume in another, as an assistant turn does on a write the
 * person has to confirm.
 */
final readonly class AgentRunStorage
{
    public function persistence(): EloquentPersistence
    {
        return new EloquentPersistence(AgentWorkflowRecord::class);
    }

    public function messageStore(): EloquentMessageStore
    {
        return new EloquentMessageStore(AgentMessage::class);
    }

    /**
     * Makes `$agent`'s runs and conversation durable. The thread must already be bound.
     *
     * @template TAgent of Agent
     *
     * @param  TAgent  $agent
     * @return TAgent
     */
    public function attach(Agent $agent): Agent
    {
        $agent->setPersistence($this->persistence());
        $agent->setMessageStore($this->messageStore());

        return $agent;
    }

    /**
     * Deletes what a thread left behind: its run records and its conversation.
     */
    public function forget(string $thread_id): void
    {
        AgentWorkflowRecord::query()->where('partition', AgentWorkflowRecord::partitionOf($thread_id))->delete();
        AgentMessage::query()->where('thread_id', $thread_id)->delete();
    }
}

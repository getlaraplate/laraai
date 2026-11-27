<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Writes;

use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Models\WriteProposal;
use Modules\AI\Services\Tools\ToolDefinition;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use Throwable;

/**
 * Where a run of the assistant that paused on a write meets the proposal the person decides on.
 *
 * The write tools wait for approval (Neuron's tool approval, {@see ToolDefinition::$onApproval}): when the model
 * calls one, the run pauses before anything is written. For each call that waits, the gate runs the tool's
 * handler, which validates the call and stores the {@see WriteProposal} the person is shown, linked to the call.
 * A call that produced no proposal, because it was invalid or over the write budget, is approved at once: there
 * is nothing for the person to decide, and the call answers the model with why, so it can correct itself in
 * the same turn. The run then waits only for the person.
 */
final readonly class WriteApprovalGate
{
    /**
     * How many times in a row the gate resumes the run on its own: each resume is a model call that may
     * propose again.
     */
    private const int MAX_SETTLE_ROUNDS = 5;

    public function __construct(private WriteProposalService $proposals) {}

    /**
     * The state of the run once nothing is left that the gate can decide: finished, or waiting for the person.
     *
     * @param  list<ToolDefinition>  $definitions  the tools offered in the run
     */
    public function settle(ChatAgent $agent, AgentState $state, array $definitions): AgentState
    {
        $by_name = [];

        foreach ($definitions as $definition) {
            $by_name[$definition->name] = $definition;
        }

        for ($round = 0; $round < self::MAX_SETTLE_ROUNDS; $round++) {
            $request = $state->getInterruptRequest();

            if (! $state->isInterrupted() || ! $request instanceof ApprovalRequest) {
                return $state;
            }

            $decisions = [];

            foreach ($request->getActions() as $action) {
                if (! $action->isPending() || $this->alreadyProposed($action->id)) {
                    continue;
                }

                $definition = $by_name[$action->name] ?? null;

                if (! $definition instanceof ToolDefinition || ! $definition->requiresApproval()) {
                    $decisions[$action->id] = ['reject', 'This action cannot be decided on in this conversation.'];

                    continue;
                }

                $proposal = $this->proposals->prepareForCall(
                    $action->id,
                    static function () use ($definition, $action): mixed {
                        try {
                            return ($definition->handler)(...$action->inputs);
                        } catch (Throwable $exception) {
                            Log::warning('Assistant write could not be prepared', ['tool' => $action->name, 'exception' => $exception::class]);

                            return ['error' => 'The change could not be prepared. Check the arguments against the tool definition.'];
                        }
                    },
                );

                if (! $proposal instanceof WriteProposal) {
                    $decisions[$action->id] = 'approve';
                }
            }

            if ($decisions === []) {
                return $state;
            }

            $state = $agent->submitApprovalDecisions($decisions)->run();
        }

        return $state;
    }

    /**
     * Whether a person already has a proposal for the call: one prepared in an earlier request of the turn.
     */
    private function alreadyProposed(string $call_id): bool
    {
        return $this->proposals->proposalForCall($call_id) instanceof WriteProposal;
    }
}

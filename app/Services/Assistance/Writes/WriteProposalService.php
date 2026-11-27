<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Writes;

use Closure;
use Illuminate\Support\Facades\Log;
use Modules\AI\Enums\WriteProposalStatus;
use Modules\AI\Models\AssistantTurn;
use Modules\AI\Models\WriteProposal;
use Modules\Core\Models\User;
use Throwable;

/**
 * The life of a write the assistant proposed: stored when the model asks for it, applied only when the
 * person confirms it through an authenticated action, never by the model.
 *
 * The service does not know how a write is applied. The caller that confirms hands it the applier, so the
 * rules about what a write is stay with the tools that built the proposal.
 */
final class WriteProposalService
{
    /**
     * @var list<WriteProposal>
     */
    private array $proposedInTurn = [];

    /**
     * The turn whose run the proposals of this request belong to, and the call being prepared.
     */
    private ?string $turnId = null;

    private ?string $callId = null;

    /**
     * What the tool answers, by call id, for a call of this request that produced no proposal: the refusal of
     * the budget or the reason the change could not be prepared.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $unproposed = [];

    public function __construct(private readonly AssistantWriteBudget $budget) {}

    public function startTurn(?string $turn_id = null): void
    {
        $this->proposedInTurn = [];
        $this->unproposed = [];
        $this->turnId = $turn_id;
        $this->callId = null;
        $this->budget->startTurn();
    }

    /**
     * Resumes the bookkeeping of a turn in a later request, without a new write budget: the proposals made
     * there are reported as the turn's.
     */
    public function resumeTurn(string $turn_id): void
    {
        $this->proposedInTurn = [];
        $this->unproposed = [];
        $this->turnId = $turn_id;
        $this->callId = null;
    }

    /**
     * Runs `$prepare` as the preparation of the model's call `$call_id`: a proposal it stores answers that call.
     * When it stores none, what it returned is kept as the answer of the call.
     *
     * @param  Closure(): mixed  $prepare
     */
    public function prepareForCall(string $call_id, Closure $prepare): ?WriteProposal
    {
        $this->callId = $call_id;
        $before = count($this->proposedInTurn);

        try {
            $result = $prepare();
        } finally {
            $this->callId = null;
        }

        if (count($this->proposedInTurn) > $before) {
            return $this->proposedInTurn[array_key_last($this->proposedInTurn)];
        }

        $answer = is_array($result) ? $result : ['error' => 'This change could not be proposed.'];
        $this->unproposed[$call_id] = $answer;
        $this->rememberAnswer($call_id, $answer);

        return null;
    }

    /**
     * The proposal the model's call `$call_id` of the current turn produced; null when it produced none.
     */
    public function proposalForCall(string $call_id): ?WriteProposal
    {
        if ($this->turnId === null) {
            return null;
        }

        return WriteProposal::query()->where('turn_id', $this->turnId)->where('tool_call_id', $call_id)->first();
    }

    /**
     * What the model's call `$call_id` of the current turn answers once a person decided on it: the outcome of
     * the proposal it produced, or why it produced none.
     *
     * @return array<string, mixed>
     */
    public function outcomeOfCall(string $call_id): array
    {
        if (isset($this->unproposed[$call_id])) {
            return $this->unproposed[$call_id];
        }

        $remembered = $this->turnId === null ? null : AssistantTurn::query()->find($this->turnId)?->call_answers[$call_id] ?? null;

        if (is_array($remembered)) {
            return $remembered;
        }

        $proposal = $this->proposalForCall($call_id);

        if (! $proposal instanceof WriteProposal) {
            return ['error' => 'This change was not prepared. Propose it again.'];
        }

        return match ($proposal->status) {
            WriteProposalStatus::Applied => ['status' => 'applied', 'proposal_id' => $proposal->getKey(), 'outcome' => $proposal->outcome],
            WriteProposalStatus::PendingApproval => [
                'status' => 'sent_for_approval',
                'proposal_id' => $proposal->getKey(),
                'outcome' => $proposal->outcome,
                'message' => 'The person confirmed the change and it was sent for approval: it takes effect once approved.',
            ],
            WriteProposalStatus::Failed => ['status' => 'failed', 'proposal_id' => $proposal->getKey(), 'error' => $proposal->outcome['error'] ?? 'The change could not be applied.'],
            default => ['status' => $proposal->status->value, 'proposal_id' => $proposal->getKey(), 'error' => 'The change was not applied.'],
        };
    }

    /**
     * The proposals created since the turn started, for the message metadata.
     *
     * @return list<WriteProposal>
     */
    public function proposedInTurn(): array
    {
        return $this->proposedInTurn;
    }

    /**
     * Stores a proposal. Null when the turn has already created as many as it may.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $summary
     */
    public function propose(
        User $user,
        int|string $conversation_id,
        string $tool,
        string $module,
        string $entity,
        string $operation,
        array $payload,
        array $summary,
        bool $requires_approval,
    ): ?WriteProposal {
        if (! $this->budget->take()) {
            $this->audit('refused', null, [
                'actor_id' => $user->id,
                'conversation_id' => (int) $conversation_id,
                'tool' => $tool,
                'entity' => mb_strtolower($module . '.' . $entity),
                'operation' => $operation,
                'reason' => 'write_budget_exceeded',
            ]);

            return null;
        }

        /** @var WriteProposal $proposal */
        $proposal = WriteProposal::query()->create([
            'user_id' => $user->getKey(),
            'conversation_id' => $conversation_id,
            'tool' => $tool,
            'module' => $module,
            'entity' => $entity,
            'operation' => $operation,
            'payload' => $payload,
            'summary' => $summary,
            'requires_approval' => $requires_approval,
            'turn_id' => $this->turnId,
            'tool_call_id' => $this->callId,
            'status' => WriteProposalStatus::Proposed,
            'expires_at' => now()->addMinutes($this->ttlMinutes()),
        ]);

        $this->proposedInTurn[] = $proposal;
        $this->audit('proposed', $proposal);

        return $proposal;
    }

    /**
     * Applies a proposal as the person who confirms it. A proposal that is already resolved is returned as
     * it is: confirming twice writes once.
     *
     * @param  Closure(WriteProposal): WriteApplyResult  $apply
     */
    public function confirm(WriteProposal $proposal, User $actor, Closure $apply): WriteProposal
    {
        $claimed = new WriteProposal()->getConnection()->transaction(function () use ($proposal, $actor): ?WriteProposal {
            /** @var WriteProposal $locked */
            $locked = WriteProposal::query()->lockForUpdate()->findOrFail($proposal->getKey());

            if ($locked->user_id !== $actor->id || ! $locked->status->isOpen()) {
                return null;
            }

            if ($locked->hasExpired()) {
                $locked->update(['status' => WriteProposalStatus::Expired, 'resolved_at' => now()]);

                return null;
            }

            $locked->update(['status' => WriteProposalStatus::Applying]);

            return $locked;
        });

        if (! $claimed instanceof WriteProposal) {
            return $proposal->refresh();
        }

        try {
            $result = $apply($claimed);
        } catch (Throwable $exception) {
            Log::warning('Assistant write could not be applied', [
                'proposal_id' => $claimed->getKey(),
                'tool' => $claimed->tool,
                'exception' => $exception::class,
            ]);

            $result = new WriteApplyResult(WriteProposalStatus::Failed, ['error' => 'The change could not be applied.']);
        }

        $claimed->update([
            'status' => $result->status,
            'outcome' => $result->outcome,
            'resolved_at' => now(),
        ]);

        $this->audit('confirmed', $claimed, ['outcome' => $result->status->value]);

        return $claimed->refresh();
    }

    /**
     * The person declines. Only an open proposal of theirs can be rejected.
     */
    public function reject(WriteProposal $proposal, User $actor): WriteProposal
    {
        return new WriteProposal()->getConnection()->transaction(function () use ($proposal, $actor): WriteProposal {
            /** @var WriteProposal $locked */
            $locked = WriteProposal::query()->lockForUpdate()->findOrFail($proposal->getKey());

            if ($locked->user_id === $actor->id && $locked->status->isOpen()) {
                $locked->update(['status' => WriteProposalStatus::Rejected, 'resolved_at' => now()]);
                $this->audit('rejected', $locked);
            }

            return $locked->refresh();
        });
    }

    /**
     * Keeps the answer of a call that produced no proposal on its turn: the run may only carry it out once the
     * person decided on the other calls of the batch, in a later request.
     *
     * @param  array<string, mixed>  $answer
     */
    private function rememberAnswer(string $call_id, array $answer): void
    {
        $turn = $this->turnId === null ? null : AssistantTurn::query()->find($this->turnId);

        if (! $turn instanceof AssistantTurn) {
            return;
        }

        $turn->update(['call_answers' => [...($turn->call_answers ?? []), $call_id => $answer]]);
    }

    /**
     * One line per step of a write the assistant proposed: who, in which conversation, through which
     * tool, on what, what came of it. Never the values: the hash of the payload says that two lines are
     * about the same change without keeping what it was. The Core modification record has no field for
     * where a write came from, so this log is where the assistant's writes are told apart.
     *
     * @param  array<string, mixed>  $extra
     */
    private function audit(string $event, ?WriteProposal $proposal, array $extra = []): void
    {
        $context = $proposal instanceof WriteProposal ? [
            'proposal_id' => $proposal->id,
            'actor_id' => (int) $proposal->user_id,
            'conversation_id' => (int) $proposal->conversation_id,
            'tool' => $proposal->tool,
            'entity' => mb_strtolower($proposal->module . '.' . $proposal->entity),
            'operation' => $proposal->operation,
            'payload_hash' => hash('sha256', (string) json_encode($proposal->payload)),
        ] : [];

        Log::info('Assistant write', ['event' => $event, ...$context, ...$extra]);
    }

    private function ttlMinutes(): int
    {
        $minutes = config('ai.features.tools.crud.proposal_ttl_minutes', 30);

        return is_numeric($minutes) && (int) $minutes > 0 ? (int) $minutes : 30;
    }
}

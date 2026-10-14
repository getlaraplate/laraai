<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Writes;

use Closure;
use Illuminate\Support\Facades\Log;
use Modules\AI\Enums\WriteProposalStatus;
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

    public function __construct(private readonly AssistantWriteBudget $budget) {}

    public function startTurn(): void
    {
        $this->proposedInTurn = [];
        $this->budget->startTurn();
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
                'actor_id' => (int) $user->getKey(),
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

            if ((int) $locked->user_id !== (int) $actor->getKey() || ! $locked->status->isOpen()) {
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

            if ((int) $locked->user_id === (int) $actor->getKey() && $locked->status->isOpen()) {
                $locked->update(['status' => WriteProposalStatus::Rejected, 'resolved_at' => now()]);
                $this->audit('rejected', $locked);
            }

            return $locked->refresh();
        });
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
            'proposal_id' => (int) $proposal->getKey(),
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

<?php

declare(strict_types=1);

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\AI\Enums\WriteProposalStatus;
use Modules\AI\Http\Resources\WriteProposalResource;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\Message;
use Modules\AI\Models\WriteProposal;
use Modules\AI\Services\Assistance\AssistantAccessContextFactory;
use Modules\AI\Services\Assistance\Contracts\InAppAssistanceServiceInterface;
use Modules\AI\Services\Assistance\Writes\WriteProposalService;
use Modules\AI\Services\Tools\CrudToolProvider;
use Modules\Core\Helpers\ResponseBuilder;
use Modules\Core\Models\User;

/**
 * Where the person decides on a write the assistant proposed. This is the only way a proposal is applied:
 * an authenticated action of the person it was proposed to, which the model cannot perform.
 */
final class AssistantWriteController extends Controller
{
    public function __construct(
        private readonly WriteProposalService $proposals,
        private readonly AssistantAccessContextFactory $access,
        private readonly InAppAssistanceServiceInterface $assistance,
    ) {}

    public function show(Request $request, WriteProposal $proposal): JsonResponse
    {
        $this->authorizeProposal($proposal);

        return new ResponseBuilder($request)->setData(new WriteProposalResource($this->expireIfDue($proposal)))->json();
    }

    public function confirm(Request $request, WriteProposal $proposal, CrudToolProvider $provider): JsonResponse
    {
        $user = $this->authorizeProposal($proposal);
        $resolved = $this->proposals->confirm($proposal, $user, $provider->applyProposal(...));

        $done = in_array($resolved->status, [WriteProposalStatus::Applied, WriteProposalStatus::PendingApproval, WriteProposalStatus::Failed], true);

        return new ResponseBuilder($request)
            ->setData($this->withFollowUp($resolved, $user, $done))
            ->setStatus($done ? Response::HTTP_OK : Response::HTTP_CONFLICT)
            ->json();
    }

    public function reject(Request $request, WriteProposal $proposal): JsonResponse
    {
        $user = $this->authorizeProposal($proposal);
        $resolved = $this->proposals->reject($proposal, $user);
        $done = $resolved->status === WriteProposalStatus::Rejected;

        return new ResponseBuilder($request)
            ->setData($this->withFollowUp($resolved, $user, $done))
            ->setStatus($done ? Response::HTTP_OK : Response::HTTP_CONFLICT)
            ->json();
    }

    /**
     * Only the person the assistant acted for, in their own in-app conversation, signed in and not a guest.
     */
    private function authorizeProposal(WriteProposal $proposal): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        abort_if($proposal->user_id !== $user->id, Response::HTTP_FORBIDDEN, 'You do not have access to this proposal.');

        $conversation = $proposal->conversation;
        abort_unless($conversation instanceof Conversation, Response::HTTP_NOT_FOUND);

        $this->access->forInApp($conversation, $user);

        return $user;
    }

    /**
     * The proposal as the interface shows it, and, once the person's decision settled the last write its turn
     * waited for, the assistant's follow-up message under `follow_up` (null otherwise).
     *
     * @return array<string, mixed>
     */
    private function withFollowUp(WriteProposal $resolved, User $user, bool $decided): array
    {
        $follow_up = $decided ? $this->assistance->continueAfterDecision($resolved, $user) : null;

        return [
            ...new WriteProposalResource($resolved)->resolve(),
            'follow_up' => $follow_up instanceof Message ? [
                'id' => $follow_up->id,
                'role' => $follow_up->role,
                'content' => $follow_up->content,
                'metadata' => $follow_up->metadata,
                'created_at' => $follow_up->created_at?->toIso8601String(),
            ] : null,
        ];
    }

    private function expireIfDue(WriteProposal $proposal): WriteProposal
    {
        if ($proposal->status->isOpen() && $proposal->hasExpired()) {
            $proposal->update(['status' => WriteProposalStatus::Expired, 'resolved_at' => now()]);
        }

        return $proposal;
    }
}

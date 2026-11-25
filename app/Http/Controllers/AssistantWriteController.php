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
use Modules\AI\Models\WriteProposal;
use Modules\AI\Services\Assistance\AssistantAccessContextFactory;
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
            ->setData(new WriteProposalResource($resolved))
            ->setStatus($done ? Response::HTTP_OK : Response::HTTP_CONFLICT)
            ->json();
    }

    public function reject(Request $request, WriteProposal $proposal): JsonResponse
    {
        $user = $this->authorizeProposal($proposal);
        $resolved = $this->proposals->reject($proposal, $user);

        return new ResponseBuilder($request)
            ->setData(new WriteProposalResource($resolved))
            ->setStatus($resolved->status === WriteProposalStatus::Rejected ? Response::HTTP_OK : Response::HTTP_CONFLICT)
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

    private function expireIfDue(WriteProposal $proposal): WriteProposal
    {
        if ($proposal->status->isOpen() && $proposal->hasExpired()) {
            $proposal->update(['status' => WriteProposalStatus::Expired, 'resolved_at' => now()]);
        }

        return $proposal;
    }
}

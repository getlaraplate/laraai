<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Contracts;

use Closure;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\Message;
use Modules\AI\Models\WriteProposal;
use Modules\AI\Services\Assistance\Stream\RunProgress;
use Modules\Core\Models\User;

interface InAppAssistanceServiceInterface
{
    /**
     * @param  array<string, mixed>|null  $request_context
     * @param  (Closure(string, string): void)|null  $progress  told where the run is, with the words of {@see RunProgress}: `started` or `finished` and a step, or `refused` and a code
     */
    public function respond(
        Conversation $conversation,
        User $authenticated_user,
        string $user_input,
        ?array $request_context = null,
        ?Closure $progress = null,
    ): Message;

    /**
     * The assistant's follow-up once the person decided on every write a turn paused on: null when the turn
     * waits for more decisions or cannot go on. The decision stands either way.
     */
    public function continueAfterDecision(WriteProposal $proposal, User $authenticated_user): ?Message;
}

<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Evaluation\Live;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\AI\Ai\Runs\AgentRunStorage;
use Modules\AI\Models\AssistantTurn;
use Modules\AI\Models\Conversation;
use Modules\AI\Services\Assistance\Contracts\InAppAssistanceServiceInterface;
use Modules\AI\Services\Assistance\Evaluation\AssistantEvaluationCase;
use Modules\Core\Models\User;
use NeuronAI\Evaluation\Conversation\Trajectory;
use Throwable;

/**
 * Asks the in-app assistant one case of a dataset as `$user`, with the live model, the way a client would: the
 * page of the case as `context.page`, its module as the application context the server resolved. Everything
 * the turn writes (the conversation, the turn, its Neuron records, a proposal) is rolled back once the run is
 * read, so an evaluation leaves nothing behind.
 */
final readonly class LiveAssistantRunner
{
    public function __construct(
        private User $user,
        private Request $request,
    ) {}

    /**
     * Binds the request the assistant reads to `$user`: call once, before the assistant is resolved.
     */
    public static function forUser(User $user): self
    {
        $request = Request::create('/app/ai/agent', 'POST');
        $request->setUserResolver(static fn (): User => $user);
        app()->instance('request', $request);
        app()->instance(Request::class, $request);
        Auth::setUser($user);

        return new self($user, $request);
    }

    public function __invoke(AssistantEvaluationCase $case): LiveAssistantRun
    {
        $this->prepareRequest($case);
        $connection = new Conversation()->getConnection();
        $connection->beginTransaction();

        try {
            $conversation = Conversation::query()->create(['user_id' => $this->user->id, 'system_message' => null]);
            $message = app(InAppAssistanceServiceInterface::class)->respond($conversation, $this->user, $case->query);
            $turn = AssistantTurn::query()->where('reply_message_id', $message->id)->first();
            $messages = $turn instanceof AssistantTurn ? app(AgentRunStorage::class)->messageStore()->loadAll($turn->id) : [];

            return new LiveAssistantRun((string) $message->content, $message->metadata ?? [], Trajectory::fromMessages($messages));
        } finally {
            try {
                $connection->rollBack();
            } catch (Throwable) {
                // Nothing was left open to roll back.
            }
        }
    }

    private function prepareRequest(AssistantEvaluationCase $case): void
    {
        if ($case->page === null) {
            $this->request->request->remove('context');
        } else {
            $this->request->request->set('context', ['page' => $case->page]);
        }

        if ($case->moduleKey === null) {
            $this->request->attributes->remove('assistant_application_context');
        } else {
            $this->request->attributes->set('assistant_application_context', ['module' => $case->moduleKey]);
        }

        $this->user->forceFill(['lang' => $case->locale]);
    }
}

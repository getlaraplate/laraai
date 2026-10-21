<?php

declare(strict_types=1);

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Facades\Auth;
use Modules\AI\Http\Requests\AgentRunRequest;
use Modules\AI\Models\Conversation;
use Modules\AI\Services\AssistAgentService;
use Modules\AI\Services\Assistance\AssistantAccessContextFactory;
use Modules\Core\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `POST /app/ai/agent`: one turn of the in-app assistant as a stream of events. It cannot choose a
 * profile, a policy or a tool: it asks the same service as the base transport and relays the events
 * that service allows.
 */
final class AgentController extends Controller
{
    /**
     * The services come as arguments of the action: they are built for each request, never kept from
     * the first one the route served.
     */
    public function run(
        AgentRunRequest $request,
        AssistAgentService $agent,
        AssistantAccessContextFactory $access,
    ): StreamedResponse {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $conversation = Conversation::query()->findOrFail($request->integer('threadId'));

        // The conversation must be the user's own and the user must be one the assistant serves, as for the base transport.
        $access->forInApp($conversation, $user);

        $validated = $request->validated();
        $context = is_array($validated['context'] ?? null) ? $validated['context'] : null;

        return response()->eventStream(function () use ($agent, $conversation, $user, $validated, $context) {
            foreach ($agent->events($conversation, $user, (string) $validated['message'], $context) as $event) {
                yield new StreamedEvent(event: (string) $event['type'], data: $event);
            }
        }, endStreamWith: null);
    }
}

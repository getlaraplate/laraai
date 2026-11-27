<?php

declare(strict_types=1);

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\AI\Contracts\IChatService;
use Modules\AI\Http\Requests\InsertConversationRequest;
use Modules\AI\Http\Requests\ListConversationsRequest;
use Modules\AI\Http\Requests\ListMessagesRequest;
use Modules\AI\Http\Requests\SendMessageRequest;
use Modules\AI\Http\Requests\UpdateConversationMemoryRequest;
use Modules\AI\Models\Conversation;
use Modules\AI\Services\Assistance\AssistantAccessContextFactory;
use Modules\AI\Services\Assistance\Contracts\InAppAssistanceServiceInterface;
use Modules\AI\Services\MemoryService;
use Modules\Core\Helpers\ResponseBuilder;
use Modules\Core\Models\User;

final class ChatController extends Controller
{
    public function __construct(
        private readonly IChatService $chatService,
        private readonly AssistantAccessContextFactory $assistantAccessContextFactory,
        private readonly InAppAssistanceServiceInterface $inAppAssistance,
    ) {}

    /**
     * Create a new conversation.
     */
    public function insertConversation(InsertConversationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $conversation = $this->chatService->createConversation(
            user: $this->authenticatedUser(),
            title: $this->optionalString($validated, 'title'),
            metadata: $this->optionalArray($validated, 'metadata'),
        );

        if (is_bool($validated['memory_enabled'] ?? null)) {
            $conversation->update(['memory_enabled' => $validated['memory_enabled']]);
        }

        return new ResponseBuilder($request)
            ->setData($conversation->load('messages'))
            ->setStatus(Response::HTTP_CREATED)
            ->json();
    }

    /**
     * List user's conversations.
     */
    public function listConversations(ListConversationsRequest $request): JsonResponse
    {
        $conversations = Conversation::query()->where('user_id', Auth::id())
            ->withCount('messages')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return new ResponseBuilder($request)
            ->setData($conversations->items())
            ->setTotalRecords($conversations->total())
            ->setCurrentRecords($conversations->count())
            ->setCurrentPage($conversations->currentPage())
            ->setTotalPages($conversations->lastPage())
            ->setPagination($conversations->perPage())
            ->json();
    }

    /**
     * Get conversation details.
     */
    public function detailConversation(Conversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($conversation);

        $conversation->load('messages');

        return new ResponseBuilder(request())
            ->setData($conversation)
            ->json();
    }

    /**
     * Get conversation messages.
     */
    public function listMessages(ListMessagesRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($conversation);

        $messages = $conversation->messages()
            ->latest()
            ->paginate($request->integer('per_page', 50));

        return new ResponseBuilder($request)
            ->setData($messages->items())
            ->setTotalRecords($messages->total())
            ->setCurrentRecords($messages->count())
            ->setCurrentPage($messages->currentPage())
            ->setTotalPages($messages->lastPage())
            ->setPagination($messages->perPage())
            ->json();
    }

    /**
     * Whether the assistant reads the earlier turns of this conversation before answering, when the setting
     * `features.chat.history.enabled` lets it. Turning it off also forgets the conversation's summaries.
     */
    public function updateConversationMemory(UpdateConversationMemoryRequest $request, Conversation $conversation, MemoryService $memory): JsonResponse
    {
        $this->authorizeConversationAccess($conversation);

        $memory->setMemoryEnabled($conversation, $request->boolean('memory_enabled'));

        return new ResponseBuilder($request)
            ->setData($conversation->refresh())
            ->json();
    }

    /**
     * Delete a conversation.
     */
    public function deleteConversation(Conversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($conversation);

        $conversation->delete();

        return new ResponseBuilder(request())
            ->setData(['message' => 'Conversation deleted'])
            ->json();
    }

    /**
     * Protected in-app assistance is non-streaming until complete output validation.
     */
    public function streamMessage(SendMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeInAppAssistantAccess($conversation);

        return response()->json([
            'message' => 'Streaming is unavailable for protected in-app assistance.',
            'code' => 'in_app_streaming_unavailable',
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Send a message in a conversation (non-streaming).
     */
    public function insertMessage(SendMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeInAppAssistantAccess($conversation);

        $validated = $request->validated();

        $message = $this->inAppAssistance->respond(
            conversation: $conversation,
            authenticated_user: $this->authenticatedUser(),
            user_input: $this->requiredString($validated, 'message'),
            request_context: $this->optionalArray($validated, 'context'),
        );

        return new ResponseBuilder($request)
            ->setData([
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'metadata' => $message->metadata,
                'created_at' => $message->created_at->toIso8601String(),
            ])
            ->setStatus(Response::HTTP_CREATED)
            ->json();
    }

    /**
     * Verify that the authenticated user owns the conversation.
     */
    private function authorizeConversationAccess(Conversation $conversation): void
    {
        abort_if($conversation->user_id !== Auth::id(), Response::HTTP_FORBIDDEN, 'You do not have access to this conversation.');
    }

    private function authorizeInAppAssistantAccess(Conversation $conversation): void
    {
        $this->assistantAccessContextFactory->forInApp(
            conversation: $conversation,
            authenticated_user: $this->authenticatedUser(),
        );
    }

    private function authenticatedUser(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function optionalString(array $validated, string $key): ?string
    {
        $value = $validated[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>|null
     */
    private function optionalArray(array $validated, string $key): ?array
    {
        $value = $validated[$key] ?? null;

        if (! is_array($value)) {
            return null;
        }

        $normalized = [];

        foreach ($value as $item_key => $item_value) {
            $normalized[(string) $item_key] = $item_value;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function requiredString(array $validated, string $key): string
    {
        $value = $validated[$key] ?? null;

        if (! is_string($value)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\AI\Http\Requests\GenerateSuggestionRequest;
use Modules\AI\Models\ContextualSuggestion;
use Modules\AI\Services\ContextualSuggestionService;
use Modules\Core\Helpers\ResponseBuilder;
use Modules\Core\Models\User;

final class SuggestionController extends Controller
{
    public function __construct(
        private readonly ContextualSuggestionService $suggestionService,
    ) {}

    /**
     * List pending suggestions for the current user.
     */
    public function listSuggestions(Request $request): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);

        if (! $user instanceof User) {
            return $user;
        }

        $suggestions = $this->suggestionService->getPendingSuggestions($user);

        return new ResponseBuilder($request)
            ->setData($suggestions)
            ->setCurrentRecords($suggestions->count())
            ->json();
    }

    /**
     * Generate a new contextual suggestion.
     */
    public function generateSuggestion(GenerateSuggestionRequest $request): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);

        if (! $user instanceof User) {
            return $user;
        }

        $validated = $request->validated();
        $context = $validated['context'] ?? null;

        if (! is_array($context)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Invalid context payload.');
        }

        /** @var array<string, mixed> $context */
        $suggestion = $this->suggestionService->generateSuggestion($user, $context);

        if (! $suggestion instanceof ContextualSuggestion) {
            return new ResponseBuilder($request)
                ->setData(null)
                ->json();
        }

        return new ResponseBuilder($request)
            ->setData([
                'id' => $suggestion->id,
                'suggestion' => $suggestion->suggestion,
                'context' => $suggestion->context,
                'created_at' => $suggestion->created_at->toIso8601String(),
            ])
            ->setStatus(Response::HTTP_CREATED)
            ->json();
    }

    /**
     * Dismiss a suggestion.
     */
    public function dismissSuggestion(Request $request, ContextualSuggestion $suggestion): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);

        if (! $user instanceof User) {
            return $user;
        }

        if ($suggestion->user_id !== $user->id) {
            return new ResponseBuilder($request)
                ->setError('Forbidden')
                ->setStatus(Response::HTTP_FORBIDDEN)
                ->json();
        }

        $this->suggestionService->dismissSuggestion($suggestion);

        return new ResponseBuilder($request)
            ->setData(['success' => true])
            ->json();
    }

    private function resolveAuthenticatedUser(Request $request): User|JsonResponse
    {
        $user = Auth::user();

        if ($user instanceof User) {
            return $user;
        }

        return new ResponseBuilder($request)
            ->setError('Unauthorized')
            ->setStatus(Response::HTTP_UNAUTHORIZED)
            ->json();
    }
}

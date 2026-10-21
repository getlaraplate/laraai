<?php

declare(strict_types=1);

namespace Modules\AI\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\AI\Services\Assistance\AssistantCapabilities;
use Symfony\Component\HttpFoundation\Response;

/**
 * One gate for every route that serves or decides on the in-app assistant: with the assistant off
 * (`features.chat.enabled`) they answer 403 `FEATURE_DISABLED` before a controller, a provider or a
 * conversation is reached. A request that is not signed in passes through, so the controller still
 * answers it 401: the gate must not tell a stranger which features an instance has on.
 */
final readonly class EnsureAssistantEnabled
{
    public function __construct(private AssistantCapabilities $capabilities) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null && ! $this->capabilities->enabled()) {
            return response()->json(['code' => 'FEATURE_DISABLED', 'message' => 'The assistant is not available.'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}

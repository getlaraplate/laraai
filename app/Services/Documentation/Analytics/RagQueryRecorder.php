<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation\Analytics;

use function ai_config_bool;

use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\Providers\RoleTokenUsage;
use Modules\AI\Ai\Rag\Judge\JudgeRun;
use Modules\AI\Jobs\LogRagQueryJob;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\Core\Models\User;
use Throwable;

/**
 * Decides, in the request, whether an answered documentation question is logged, and queues it.
 *
 * Only an identified user speaking for themselves is logged: a guest or an impersonated session
 * never is. Impersonation is known only here, from the session, which a queued job does not have.
 * Nothing in here can fail the answer.
 */
final class RagQueryRecorder
{
    public function record(
        User $user,
        AssistantAccessContext $access,
        string $question,
        int $retrievedCount,
        int $citationCount,
        float $latencyMs,
        ?JudgeRun $judge = null,
        ?RoleTokenUsage $tokens = null,
    ): void {
        try {
            if (! ai_config_bool('ai.features.faq.query_logging.enabled', false)
                || $user->isGuest()
                || $user->isImpersonated()) {
                return;
            }

            LogRagQueryJob::dispatch(
                RagQueryLog::fromAnswer($access, $question, $retrievedCount, $citationCount, $latencyMs, $judge, $tokens)->toDocument(),
            );
        } catch (Throwable $throwable) {
            Log::warning('rag_query_analytics_dispatch_failed', ['exception' => $throwable::class]);
        }
    }
}

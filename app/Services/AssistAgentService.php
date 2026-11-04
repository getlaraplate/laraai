<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Fiber;
use Generator;
use Illuminate\Support\Str;
use LogicException;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\Message;
use Modules\AI\Services\Assistance\Contracts\InAppAssistanceServiceInterface;
use Modules\AI\Services\Assistance\Stream\AgentEvent;
use Modules\AI\Services\Assistance\Stream\RunProgress;
use Modules\Core\Models\User;
use Throwable;

/**
 * One turn of the in-app assistant, told as the events of a run while it happens.
 *
 * It is a wrapper and nothing more: the turn is `InAppAssistanceService::respond()`, with its
 * protected profile, its compiled policy, its guardrails, its proposals and its objects, and this class neither
 * chooses nor changes any of them. The service runs inside a fiber and reports where it is, so the
 * run says "retrieving" while it retrieves; the answer is sent once it is complete and validated, as
 * one message, because no token of the model is ever delivered.
 */
final readonly class AssistAgentService
{
    public function __construct(private InAppAssistanceServiceInterface $assistance) {}

    /**
     * The proposals and then the objects of the message follow it, one event each. Only a proposal waits
     * for the user, so only a proposal interrupts the run: an object is shown, never confirmed.
     *
     * @param  array<string, mixed>|null  $context
     * @return Generator<int, array<string, mixed>>
     */
    public function events(Conversation $conversation, User $user, string $message, ?array $context = null): Generator
    {
        $run_id = (string) Str::uuid();

        yield AgentEvent::runStarted($conversation->getKey(), $run_id);

        $refusal = null;
        $progress_callback = static function (string $kind, string $detail) use (&$refusal): void {
            if ($kind === RunProgress::REFUSED) {
                $refusal = $detail;

                return;
            }

            Fiber::suspend([$kind, $detail]);
        };
        $fiber = new Fiber(fn (): Message => $this->assistance->respond($conversation, $user, $message, $context, $progress_callback));

        try {
            $progress = $fiber->start();

            while (! $fiber->isTerminated()) {
                yield $progress[0] === RunProgress::STARTED
                    ? AgentEvent::stepStarted($progress[1])
                    : AgentEvent::stepFinished($progress[1]);

                $progress = $fiber->resume();
            }

            $reply = $fiber->getReturn();

            if (! $reply instanceof Message) {
                throw new LogicException('The assistance service returned no message.');
            }
        } catch (Throwable) {
            yield AgentEvent::runError(RunProgress::POLICY_DENIED);

            return;
        }

        yield from AgentEvent::message($reply->getKey(), (string) $reply->content);

        if ($refusal !== null) {
            yield AgentEvent::runError($refusal);

            return;
        }

        $citations = $reply->metadata['citations'] ?? [];
        $proposals = $reply->metadata['proposals'] ?? [];

        if ($citations !== []) {
            yield AgentEvent::stateDelta(['citations' => $citations]);
        }

        foreach ($proposals as $proposal) {
            yield AgentEvent::proposal($proposal);
        }

        foreach ($reply->metadata['objects'] ?? [] as $object) {
            yield AgentEvent::object($object);
        }

        yield AgentEvent::runFinished($conversation->getKey(), $run_id, $proposals);
    }
}

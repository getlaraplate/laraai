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
     * @return Generator<int, array{type: string, ...<string, mixed>}>
     */
    public function events(Conversation $conversation, User $user, string $message, ?array $context = null): Generator
    {
        $run_id = (string) Str::uuid();

        yield AgentEvent::runStarted($conversation->id, $run_id);

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
                [$kind, $detail] = self::progress($progress);

                yield $kind === RunProgress::STARTED
                    ? AgentEvent::stepStarted($detail)
                    : AgentEvent::stepFinished($detail);

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

        yield from AgentEvent::message($reply->id, (string) $reply->content);

        if ($refusal !== null) {
            yield AgentEvent::runError($refusal);

            return;
        }

        $citations = $reply->metadata['citations'] ?? [];
        $proposals = self::records($reply->metadata['proposals'] ?? null);

        if ($citations !== []) {
            yield AgentEvent::stateDelta(['citations' => $citations]);
        }

        foreach ($proposals as $proposal) {
            yield AgentEvent::proposal($proposal);
        }

        foreach (self::objects($reply->metadata['objects'] ?? null) as $object) {
            yield AgentEvent::object($object);
        }

        yield AgentEvent::runFinished($conversation->id, $run_id, $proposals);
    }

    /**
     * What the progress callback suspended the fiber with: a kind and its detail.
     *
     * @return array{string, string}
     */
    private static function progress(mixed $progress): array
    {
        if (! is_array($progress) || ! is_string($progress[0] ?? null) || ! is_string($progress[1] ?? null)) {
            throw new LogicException('The assistance run suspended without a progress step.');
        }

        return [$progress[0], $progress[1]];
    }

    /**
     * The records stored in one entry of the message metadata, keyed by name.
     *
     * @return list<array<string, mixed>>
     */
    private static function records(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $records = [];

        foreach ($value as $record) {
            if (is_array($record)) {
                $records[] = array_filter($record, is_string(...), ARRAY_FILTER_USE_KEY);
            }
        }

        return $records;
    }

    /**
     * The objects the message cites, skipping one stored without its id, type, title or reference.
     *
     * @return list<array{id: string, type: string, title: string, ref: array<string, mixed>}>
     */
    private static function objects(mixed $value): array
    {
        $objects = [];

        foreach (self::records($value) as $object) {
            $id = $object['id'] ?? null;
            $type = $object['type'] ?? null;
            $title = $object['title'] ?? null;
            $ref = $object['ref'] ?? null;

            if (is_string($id) && is_string($type) && is_string($title) && is_array($ref)) {
                $objects[] = ['id' => $id, 'type' => $type, 'title' => $title, 'ref' => array_filter($ref, is_string(...), ARRAY_FILTER_USE_KEY)];
            }
        }

        return $objects;
    }
}

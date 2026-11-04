<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Stream;

use Modules\AI\Services\Tools\ShowWidgetTool;

/**
 * The events of a run, in the vocabulary of AG-UI: every one is an array with its `type`. They carry
 * what the server decided to say: lifecycle, fixed step labels, a complete validated message,
 * validated proposals and objects, and an interrupt or an error code. No event carries a token of the model.
 */
final class AgentEvent
{
    private const array ERROR_TEXT = [
        RunProgress::POLICY_DENIED => 'The assistant cannot help with that request.',
        RunProgress::PROVIDER_ERROR => 'The assistant is unavailable. Try again later.',
    ];

    /**
     * @return array{type: string, threadId: int|string, runId: string}
     */
    public static function runStarted(int|string $threadId, string $runId): array
    {
        return ['type' => 'RunStarted', 'threadId' => $threadId, 'runId' => $runId];
    }

    /**
     * @return array{type: string, stepName: string}
     */
    public static function stepStarted(string $step): array
    {
        return ['type' => 'StepStarted', 'stepName' => $step];
    }

    /**
     * @return array{type: string, stepName: string}
     */
    public static function stepFinished(string $step): array
    {
        return ['type' => 'StepFinished', 'stepName' => $step];
    }

    /**
     * A message, complete: it is validated before any of it is sent, so the text is one event.
     *
     * @return list<array<string, mixed>>
     */
    public static function message(int|string $messageId, string $text): array
    {
        return [
            ['type' => 'TextMessageStart', 'messageId' => $messageId, 'role' => 'assistant'],
            ['type' => 'TextMessageContent', 'messageId' => $messageId, 'delta' => $text],
            ['type' => 'TextMessageEnd', 'messageId' => $messageId],
        ];
    }

    /**
     * @param  array<string, mixed>  $delta
     * @return array{type: string, delta: array<string, mixed>}
     */
    public static function stateDelta(array $delta): array
    {
        return ['type' => 'StateDelta', 'delta' => $delta];
    }

    /**
     * A proposal, as it was validated, for the user to accept or refuse.
     *
     * @param  array<string, mixed>  $proposal
     * @return array{type: string, toolCallId: string, toolCallName: string, args: array<string, mixed>}
     */
    public static function proposal(array $proposal): array
    {
        return [
            'type' => 'ToolCall',
            'toolCallId' => (string) $proposal['id'],
            'toolCallName' => $proposal['kind'] === 'view_state' ? 'propose_view_state' : 'propose_preference_change',
            'args' => $proposal,
        ];
    }

    /**
     * An object of the message, as it was validated, for the client to render. Nothing waits for the user.
     *
     * @param  array{id: string, type: string, title: string, ref: array<string, mixed>}  $object
     * @return array{type: string, toolCallId: string, toolCallName: string, args: array<string, mixed>}
     */
    public static function object(array $object): array
    {
        return [
            'type' => 'ToolCall',
            'toolCallId' => $object['id'],
            'toolCallName' => ShowWidgetTool::NAME,
            'args' => $object,
        ];
    }

    /**
     * The run ends with the user: one interrupt for each proposal that waits for an answer.
     *
     * @param  list<array<string, mixed>>  $proposals
     * @return array<string, mixed>
     */
    public static function runFinished(int|string $threadId, string $runId, array $proposals): array
    {
        $outcome = $proposals === []
            ? ['type' => 'success']
            : [
                'type' => 'interrupt',
                'interrupts' => array_map(static fn (array $proposal): array => [
                    'id' => (string) $proposal['id'],
                    'reason' => 'confirm_proposal',
                    'payload' => ['kind' => $proposal['kind'], 'target' => $proposal['target']],
                ], $proposals),
            ];

        return ['type' => 'RunFinished', 'threadId' => $threadId, 'runId' => $runId, 'outcome' => $outcome];
    }

    /**
     * @return array{type: string, code: string, message: string}
     */
    public static function runError(string $code): array
    {
        return ['type' => 'RunError', 'code' => $code, 'message' => self::ERROR_TEXT[$code] ?? self::ERROR_TEXT[RunProgress::PROVIDER_ERROR]];
    }
}

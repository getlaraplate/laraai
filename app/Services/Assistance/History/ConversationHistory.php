<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\History;

use function ai_config_bool;
use function ai_config_int;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\Message;
use Modules\AI\Services\Assistance\AssistanceGuardrailPipeline;

/**
 * Conversation memory: the earlier turns of a conversation the in-app assistant reads before answering, so that
 * a follow-up question ("and the one from yesterday?") is understood.
 *
 * Off by default (`ai.features.chat.history.enabled`), and off for a conversation whose `memory_enabled` is
 * false. When on, at most `ai.features.chat.history.turns` earlier turns (20 at most) travel in the authorized
 * context as `recent_turns`, under the policy capability `conversation_memory`, and only what the person saw:
 * the question as the input guardrails let it through and the answers as the output guardrails let them
 * through. Never a tool call, a tool result, a citation or any other metadata. A turn the assistant refused is
 * left out whole, and so is a turn in which any text reads like an instruction to the context classifier. Each
 * text is cut at `ai.features.chat.history.max_chars`.
 *
 * Memory of the user across conversations is not provided. The threat model, the mitigations and why are in
 * `docs/rag/ASSISTANT_HISTORY_SECURITY_REVIEW.md`.
 */
final readonly class ConversationHistory
{
    /**
     * The policy capability that carries the rule on earlier turns.
     */
    public const string CAPABILITY = 'conversation_memory';

    /**
     * The most turns a context may carry, whatever the setting says.
     */
    public const int MAX_TURNS = 20;

    public function __construct(private AssistanceGuardrailPipeline $guardrails) {}

    public static function enabled(): bool
    {
        return ai_config_bool('ai.features.chat.history.enabled', false);
    }

    /**
     * The column defaults to true in the database: a conversation created in this request may not have read it
     * back, and reads null until then.
     */
    public function enabledFor(Conversation $conversation): bool
    {
        return self::enabled() && $conversation->memory_enabled !== false;
    }

    /**
     * The earlier turns of `$conversation`, oldest first, as who spoke (`person` or `assistant`) and the text of
     * each message. The key is `speaker`, not `role`: a role is control-plane data the prompt context refuses.
     *
     * @return list<array{speaker: string, content: string}>
     */
    public function recentTurns(Conversation $conversation): array
    {
        if (! $this->enabledFor($conversation)) {
            return [];
        }

        $turns = min(self::MAX_TURNS, max(0, ai_config_int('ai.features.chat.history.turns', 6)));

        if ($turns === 0) {
            return [];
        }

        $messages = [];

        foreach (array_slice($this->turns($conversation, $turns * 4), -$turns) as $turn) {
            array_push($messages, ...$turn);
        }

        return $messages;
    }

    /**
     * The safe turns among the latest `$limit` messages, oldest first. A turn is a question and the answers
     * that follow it before the next question: the answer of the turn and the follow-up written after a decision.
     *
     * @return list<list<array{speaker: string, content: string}>>
     */
    private function turns(Conversation $conversation, int $limit): array
    {
        $rows = Message::query()
            ->where('conversation_id', $conversation->getKey())
            ->whereIn('role', ['user', 'assistant'])
            ->latest('id')
            ->limit($limit)
            ->get(['id', 'conversation_id', 'role', 'content', 'metadata'])
            ->reverse()
            ->values();

        $turns = [];
        $current = null;

        foreach ($rows as $row) {
            if ($row->role === 'user') {
                if ($current !== null) {
                    $turns[] = $current;
                }

                $current = ['question' => $row, 'answers' => []];

                continue;
            }

            if ($current !== null) {
                $current['answers'][] = $row;
            }
        }

        if ($current !== null) {
            $turns[] = $current;
        }

        $safe = [];

        foreach ($turns as $turn) {
            $messages = $this->safeTurn($turn['question'], $turn['answers']);

            if ($messages !== null) {
                $safe[] = $messages;
            }
        }

        return $safe;
    }

    /**
     * The turn as Neuron messages, or null when it must not be replayed: it has no answer yet, it was refused,
     * or one of its texts reads like an instruction.
     *
     * @param  list<Message>  $answers
     * @return list<array{speaker: string, content: string}>|null
     */
    private function safeTurn(Message $question, array $answers): ?array
    {
        if ($answers === []) {
            return null;
        }

        $max_chars = max(100, ai_config_int('ai.features.chat.history.max_chars', 2000));
        $messages = [['speaker' => 'person', 'content' => $this->bounded((string) $question->content, $max_chars)]];

        foreach ($answers as $answer) {
            if (($answer->metadata['refused'] ?? null) === true) {
                return null;
            }

            $messages[] = ['speaker' => 'assistant', 'content' => $this->bounded((string) $answer->content, $max_chars)];
        }

        foreach ($messages as $message) {
            if ($this->guardrails->toolResultContainsInstructions($message['content'])) {
                Log::notice('Assistant history turn withheld', ['conversation_id' => $question->conversation_id]);

                return null;
            }
        }

        return $messages;
    }

    private function bounded(string $text, int $max_chars): string
    {
        return Str::limit(mb_trim($text), $max_chars);
    }
}

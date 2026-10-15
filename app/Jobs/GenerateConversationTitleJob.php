<?php

declare(strict_types=1);

namespace Modules\AI\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\Message;
use Modules\AI\Services\Assistance\ConversationTitleService;
use Modules\Core\Helpers\LocaleContext;

/**
 * Gives a conversation its title after the first answer that is not a refusal. It carries the id of
 * that answer and nothing of the text: the messages are read when the job runs, so a conversation
 * deleted in the meantime is not titled.
 *
 * A title that is already there, set by the caller or by an earlier run, is never touched.
 */
final class GenerateConversationTitleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(private readonly int $assistantMessageId) {}

    public function handle(ConversationTitleService $titles): void
    {
        $answer = Message::query()->where('role', 'assistant')->find($this->assistantMessageId);
        $conversation = $answer instanceof Message ? Conversation::query()->find($answer->conversation_id) : null;

        if (! $answer instanceof Message || ! $conversation instanceof Conversation || $conversation->title !== null || ($answer->metadata['refused'] ?? null) === true) {
            return;
        }

        $question = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->where('id', '<', $answer->id)
            ->latest('id')
            ->first();

        if (! $question instanceof Message) {
            return;
        }

        $result = $titles->titleFor($question->content, $answer->content, $conversation->user?->lang ?? LocaleContext::getDefault());

        if ($result === null) {
            return;
        }

        // Only a title that is still null is written, so two answers racing for it cannot overwrite each other.
        $written = Conversation::query()->whereKey($conversation->id)->whereNull('title')->update(['title' => $result['title']]);

        // The title is the user's text: the log says that it was set, how, and how long it is.
        Log::info('Conversation title set', [
            'conversation_id' => $conversation->id,
            'source' => $result['generated'] ? 'generated' : 'fallback',
            'length' => mb_strlen($result['title']),
            'written' => $written === 1,
        ]);
    }
}

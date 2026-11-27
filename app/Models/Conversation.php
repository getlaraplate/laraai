<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\AI\Ai\Runs\AgentRunStorage;
use Modules\AI\Enums\AITables;
use Modules\Core\Models\User;
use Modules\Core\Overrides\Model;
use Override;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $title Conversation title (auto-generated or manual)
 * @property string|null $system_message Custom system prompt for this conversation
 * @property bool $memory_enabled Whether memory/summary is enabled for this conversation
 * @property string|null $summary Rolling summary of the conversation
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 * @property array<array-key, mixed>|null $metadata Additional context data
 */
final class Conversation extends Model
{
    /**
     * @var string
     */
    #[Override]
    protected $table = AITables::Conversations->value;

    #[Override]
    protected $fillable = [
        'user_id',
        'title',
        'system_message',
        'metadata',
        'memory_enabled',
        'summary',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->oldest();
    }

    /**
     * @return HasMany<ConversationSummary, $this>
     */
    public function summaries(): HasMany
    {
        return $this->hasMany(ConversationSummary::class)->latest();
    }

    /**
     * Remove for good what the conversation holds: its messages, its summaries, the runs of its assistant
     * turns with their Neuron records, and the title, the summary, the system message and the metadata on its
     * own row. The row stays, soft deleted or not, and holds nothing.
     */
    public function purgeContent(): void
    {
        Message::query()->withTrashed()->where('conversation_id', $this->getKey())->forceDelete();
        ConversationSummary::query()->withTrashed()->where('conversation_id', $this->getKey())->forceDelete();
        $runs = app(AgentRunStorage::class);

        foreach (AssistantTurn::query()->where('conversation_id', $this->getKey())->pluck('id') as $turn_id) {
            $runs->forget((string) $turn_id);
        }

        AssistantTurn::query()->where('conversation_id', $this->getKey())->delete();

        self::query()->withTrashed()->whereKey($this->getKey())->update([
            'title' => null,
            'summary' => null,
            'system_message' => null,
            'metadata' => null,
        ]);
    }

    /**
     * Add a message to the conversation.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function addMessage(string $role, string $content, ?array $metadata = null): Message
    {
        /** @var Message */
        return $this->messages()->create([
            'role' => $role,
            'content' => $content,
            'metadata' => $metadata,
        ]);
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'memory_enabled' => 'boolean',
        ];
    }
}

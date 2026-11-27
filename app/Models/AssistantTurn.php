<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\AI\Ai\Runs\AgentRunStorage;
use Modules\AI\Enums\AITables;
use Modules\AI\Enums\AssistantTurnStatus;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\Core\Models\User;
use Modules\Core\Overrides\Model;
use Override;

/**
 * One message of the in-app assistant as a durable run: its id is the Neuron thread the run, its history and
 * its pause live under. The turn keeps what rebuilding the agent needs when the person decides on a write
 * it proposed: the question and the application context the server resolved, never a value of the model.
 *
 * @property string $id
 * @property int $user_id
 * @property int $conversation_id
 * @property string $input
 * @property array<string, mixed>|null $application_context
 * @property AssistantTurnStatus $status
 * @property array<string, array<string, mixed>>|null $call_answers
 * @property int|null $reply_message_id
 * @property int|null $follow_up_message_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Conversation|null $conversation
 * @property-read User|null $user
 */
final class AssistantTurn extends Model implements IsPartOfParent
{
    use HasUuids;
    use Prunable;

    /**
     * Days a turn and its Neuron records are kept after its last change. A proposal waits half an hour by
     * default, so an older turn has nothing left to resume; the purge is scheduled in the AI service provider,
     * and the proposals, which are the audit record, stay.
     */
    public const int RETENTION_DAYS = 7;

    /**
     * The record of a run: never soft deleted, pruned with its Neuron records.
     */
    protected bool $softDeletesEnabled = false;

    /**
     * @var string
     */
    #[Override]
    protected $table = AITables::AssistantTurns->value;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'id',
        'user_id',
        'conversation_id',
        'input',
        'application_context',
        'status',
        'call_answers',
        'reply_message_id',
        'follow_up_message_id',
    ];

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->where('updated_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return HasMany<WriteProposal, $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(WriteProposal::class, 'turn_id');
    }

    /**
     * The relation to the record this one only exists inside, whose visibility it inherits.
     */
    #[Override]
    public function parentRelation(): string
    {
        return 'conversation';
    }

    /**
     * The run's records and the conversation Neuron kept for it go with the turn.
     */
    protected function pruning(): void
    {
        app(AgentRunStorage::class)->forget($this->id);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'application_context' => 'array',
            'call_answers' => 'array',
            'status' => AssistantTurnStatus::class,
        ];
    }
}

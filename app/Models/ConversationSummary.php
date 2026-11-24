<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AI\Enums\AITables;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\Core\Overrides\Model;
use Override;

/**
 * @property int $id
 * @property int $conversation_id
 * @property string $summary Summary text at this point in time
 * @property int $message_count Number of messages when summary was created
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 */
final class ConversationSummary extends Model implements IsPartOfParent
{
    /**
     * @var string
     */
    #[Override]
    protected $table = AITables::ConversationSummaries->value;

    #[Override]
    protected $fillable = [
        'conversation_id',
        'summary',
        'facts',
        'message_count',
    ];

    /**
     * The relation to the record this one only exists inside, whose visibility it inherits.
     */
    #[Override]
    public function parentRelation(): string
    {
        return 'conversation';
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    protected function casts(): array
    {
        return [
            'facts' => 'array',
            'message_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}

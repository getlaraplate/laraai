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
 * @property string $role Message role: system, user, assistant
 * @property string $content Message content
 * @property int|null $token_count Token count for cost tracking
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 * @property array<array-key, mixed>|null $metadata Additional data: context, tool calls, citations, etc.
 */
final class Message extends Model implements IsPartOfParent
{
    /**
     * @var string
     */
    #[Override]
    protected $table = AITables::Messages->value;

    #[Override]
    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'metadata',
        'token_count',
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
            'metadata' => 'array',
            'token_count' => 'integer',
        ];
    }
}

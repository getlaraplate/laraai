<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AI\Database\Factories\WriteProposalFactory;
use Modules\AI\Enums\AITables;
use Modules\AI\Enums\WriteProposalStatus;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\Core\Models\User;
use Modules\Core\Overrides\Model;
use Override;

/**
 * A write the in-app assistant proposed on behalf of a person. It changes nothing by itself: the person
 * confirms it through an authenticated action, and only then is `payload` applied, as that person.
 *
 * @property int $id
 * @property int $user_id
 * @property int $conversation_id
 * @property string $tool
 * @property string $module
 * @property string $entity
 * @property string $operation
 * @property array<string, mixed> $payload
 * @property array<string, mixed> $summary
 * @property bool $requires_approval
 * @property WriteProposalStatus $status
 * @property array<string, mixed>|null $outcome
 * @property \Carbon\CarbonImmutable $expires_at
 * @property \Carbon\CarbonImmutable|null $resolved_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 */
final class WriteProposal extends Model implements IsPartOfParent
{
    /**
     * An audit record of what the assistant asked for: never soft deleted.
     */
    protected bool $softDeletesEnabled = false;

    /**
     * @var string
     */
    #[Override]
    protected $table = AITables::WriteProposals->value;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'user_id',
        'conversation_id',
        'tool',
        'module',
        'entity',
        'operation',
        'payload',
        'summary',
        'requires_approval',
        'status',
        'outcome',
        'expires_at',
        'resolved_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    protected static function newFactory(): WriteProposalFactory
    {
        return WriteProposalFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'summary' => 'array',
            'outcome' => 'array',
            'requires_approval' => 'boolean',
            'status' => WriteProposalStatus::class,
            'expires_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}

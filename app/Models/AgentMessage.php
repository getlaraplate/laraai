<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\AI\Enums\AITables;
use Override;

/**
 * One message of the conversation Neuron keeps for an assistant turn (`EloquentMessageStore`): the question,
 * the tool calls and their results, the answer. It is Neuron's working copy, never shown as it is; what the
 * person reads are the {@see Message} rows of the conversation.
 *
 * A plain Eloquent model on purpose: Neuron writes and reads it through its own store, which expects the
 * columns as they are, no soft delete and no version.
 *
 * @property int $id
 * @property string $thread_id
 * @property string $message_id
 * @property string $role
 * @property array<array-key, mixed>|null $content
 * @property array<string, mixed>|null $meta
 * @property \Illuminate\Support\Carbon|null $archived_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class AgentMessage extends Model
{
    /**
     * @var string
     */
    #[Override]
    protected $table = AITables::AgentMessages->value;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = ['thread_id', 'message_id', 'role', 'content', 'meta'];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'meta' => 'array',
            'archived_at' => 'datetime',
        ];
    }
}

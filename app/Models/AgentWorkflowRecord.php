<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\AI\Enums\AITables;
use Override;

/**
 * One record of a durable Neuron run (`EloquentPersistence`): the partition is the thread of an assistant
 * turn, the key one of the run's records, the value Neuron's own encoding of it.
 *
 * A plain Eloquent model on purpose: the values must round-trip exactly as Neuron wrote them, so no cast, no
 * soft delete and no version.
 *
 * @property int $id
 * @property string $partition
 * @property string $key
 * @property string $value
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class AgentWorkflowRecord extends Model
{
    /**
     * @var string
     */
    #[Override]
    protected $table = AITables::AgentWorkflowRecords->value;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = ['partition', 'key', 'value'];

    /**
     * The partition Neuron stores a thread's records under: the thread, hex-encoded.
     */
    public static function partitionOf(string $thread_id): string
    {
        return bin2hex($thread_id);
    }
}

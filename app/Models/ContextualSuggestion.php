<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AI\Enums\AITables;
use Modules\Core\Models\User;
use Modules\Core\Overrides\Model;
use Override;

/**
 * @property int $id
 * @property int $user_id
 * @property string $suggestion AI-generated suggestion text
 * @property \Carbon\CarbonImmutable|null $dismissed_at When user dismissed the suggestion
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 * @property array<array-key, mixed> $context UI context (page, action, data)
 */
final class ContextualSuggestion extends Model
{
    use MassPrunable;

    /**
     * Days a suggestion is kept. One is shown for an hour at most, so what is older is stale data
     * about what a user was doing; the purge is scheduled in the AI service provider.
     */
    public const int RETENTION_DAYS = 7;

    /**
     * @var string
     */
    #[Override]
    protected $table = AITables::ContextualSuggestions->value;

    #[Override]
    protected $fillable = [
        'user_id',
        'context',
        'suggestion',
        'dismissed_at',
    ];

    /**
     * What the `context` column keeps of what a client sends: the page and the action the suggestion
     * is about, as text. The `data` a client sends helps generate the suggestion and is never stored.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<string, string>
     */
    public static function retainedContext(array $context): array
    {
        $retained = [];

        foreach (['page', 'action'] as $key) {
            if (is_string($context[$key] ?? null) && $context[$key] !== '') {
                $retained[$key] = mb_substr($context[$key], 0, 255);
            }
        }

        return $retained;
    }

    /**
     * The suggestions the scheduled `model:prune` removes: those older than the retention, soft
     * deleted ones included.
     *
     * @return Builder<ContextualSuggestion>
     */
    public function prunable(): Builder
    {
        return self::query()->withTrashed()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dismiss(): void
    {
        $this->update(['dismissed_at' => now()]);
    }

    /**
     * @param  Builder<ContextualSuggestion>  $query
     * @return Builder<ContextualSuggestion>
     */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function forUser(Builder $query, int $user_id): Builder
    {
        return $query->where('user_id', $user_id);
    }

    /**
     * @param  Builder<ContextualSuggestion>  $query
     * @return Builder<ContextualSuggestion>
     */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function notDismissed(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at');
    }

    /**
     * @param  Builder<ContextualSuggestion>  $query
     * @return Builder<ContextualSuggestion>
     */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function recent(Builder $query, int $minutes = 60): Builder
    {
        return $query->where('created_at', '>=', now()->subMinutes($minutes));
    }

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'dismissed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}

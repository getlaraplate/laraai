<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings\Switching;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection as BaseCollection;
use Modules\AI\Contracts\IEmbeddableModels;
use Modules\AI\Services\ModelEmbeddingSynchronizer;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Models\Concerns\HasTranslations;
use Modules\Core\Models\ModelEmbedding;

/**
 * The records an embedding model switch works on: for each embeddable model, the population
 * `scout:import` indexes (`makeAllSearchableQuery()`) less the records the index would skip
 * (`shouldBeSearchable()`). A record is embedded when it has a row stamped with the target
 * `model_key` and the hash of its current text for each locale it has embeddable text in; a record
 * with no embeddable text has no row to wait for.
 */
final readonly class EmbeddingSwitchCorpus
{
    private const int CHUNK = 200;

    public function __construct(private IEmbeddableModels $embeddableModels) {}

    /**
     * Readable references to records, as `Class #id`, at most ten of them.
     *
     * @param  list<Model>  $models
     */
    public static function describe(array $models): string
    {
        $references = array_map(static fn (Model $model): string => class_basename($model) . ' #' . $model->getKey(), array_slice($models, 0, 10));

        return implode(', ', $references) . (count($models) > 10 ? ', ...' : '');
    }

    /**
     * @return list<class-string<Model>>
     */
    public function models(): array
    {
        return $this->embeddableModels->all();
    }

    /**
     * Calls `$callback` with each chunk of the searchable records of `$modelClass`, only those whose
     * key is at least `$from` and below `$to` when they are given.
     *
     * @param  class-string<Model>  $modelClass
     * @param  Closure(Collection<int, Model>): void  $callback
     * @param  array<int|string, string|(Closure(Relation<*, *, *>): mixed)>  $with  Relations to eager-load on each chunk
     */
    public function eachSearchableChunk(string $modelClass, Closure $callback, array $with = [], int|string|null $from = null, int|string|null $to = null): void
    {
        /** @var Model $instance */
        $instance = new $modelClass();
        $key = $instance->getQualifiedKeyName();

        $this->importQuery($modelClass)
            ->with($with)
            ->when($from !== null, static fn (Builder $query) => $query->where($key, '>=', $from))
            ->when($to !== null, static fn (Builder $query) => $query->where($key, '<', $to))
            ->chunkById(self::CHUNK, static function (Collection $chunk) use ($callback): void {
                $searchable = $chunk
                    ->filter(static fn (Model $model): bool => ! method_exists($model, 'shouldBeSearchable') || $model->shouldBeSearchable())
                    ->values();

                if ($searchable->isNotEmpty()) {
                    $callback($searchable);
                }
            }, $instance->getQualifiedKeyName(), $instance->getKeyName());
    }

    /**
     * Splits the key space of `$modelClass` into ranges of about `$size` records of the population
     * `scout:import` indexes, in key order: `from` is the first key of a range (inclusive), `to` the
     * first key of the next one (exclusive). The first range has no lower bound and the last no upper
     * bound, so every record, also one created after the split, falls in exactly one range; a model
     * with no records has one unbounded range.
     *
     * @param  class-string<Model>  $modelClass
     * @return list<array{from: int|string|null, to: int|string|null}>
     */
    public function keyRanges(string $modelClass, int $size): array
    {
        /** @var Model $instance */
        $instance = new $modelClass();
        $starts = [];

        $this->importQuery($modelClass)
            ->toBase()
            ->select($instance->getQualifiedKeyName())
            ->chunkById(max(1, $size), static function (BaseCollection $rows) use (&$starts, $instance): void {
                $starts[] = $rows->first()->{$instance->getKeyName()};
            }, $instance->getQualifiedKeyName(), $instance->getKeyName());

        $ranges = [];
        $count = max(1, count($starts));

        for ($index = 0; $index < $count; $index++) {
            $ranges[] = [
                'from' => $index === 0 ? null : $starts[$index],
                'to' => $starts[$index + 1] ?? null,
            ];
        }

        return $ranges;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    public function searchableCount(string $modelClass): int
    {
        $count = 0;

        $this->eachSearchableChunk($modelClass, static function (Collection $chunk) use (&$count): void {
            $count += $chunk->count();
        });

        return $count;
    }

    /**
     * How far the corpus (or one model of it) is embedded with `$modelKey`: the (record, locale)
     * pairs expected and those with a fresh row of the key (its `content_hash` is the hash of the
     * record's current text for that locale), and the records still missing one. A row written
     * before the record was edited does not count.
     *
     * @param  class-string<Model>|null  $modelClass
     * @return array{total: int, done: int, pending: list<Model>}
     */
    public function progress(string $modelKey, ?string $modelClass = null): array
    {
        $total = 0;
        $done = 0;
        $pending = [];

        foreach ($modelClass === null ? $this->models() : [$modelClass] as $class) {
            $with = ['embeddings' => static function (Relation $query) use ($modelKey): void {
                $query->where('model_key', $modelKey);
            }];

            if (class_uses_trait($class, HasTranslations::class)) {
                $with[] = 'translations';
            }

            $this->eachSearchableChunk($class, function (Collection $chunk) use (&$total, &$done, &$pending, $modelKey): void {
                foreach ($chunk as $model) {
                    $expected = $this->expectedRows($model);

                    if ($expected === []) {
                        continue;
                    }

                    /** @var BaseCollection<int, ModelEmbedding> $rows */
                    $rows = $model->getRelation('embeddings');
                    $embedded = array_filter($expected, static fn (array $row): bool => $rows->contains(
                        static fn (ModelEmbedding $stored): bool => $stored->model_key === $modelKey
                            && $stored->locale === $row['locale']
                            && $stored->content_hash === $row['content_hash'],
                    ));

                    $total += count($expected);
                    $done += count($embedded);

                    if (count($embedded) < count($expected)) {
                        $pending[] = $model;
                    }
                }
            }, $with);
        }

        return ['total' => $total, 'done' => $done, 'pending' => $pending];
    }

    /**
     * The query `scout:import` indexes `$modelClass` with.
     *
     * @param  class-string<Model>  $modelClass
     * @return Builder<Model>
     */
    private function importQuery(string $modelClass): Builder
    {
        return $modelClass::makeAllSearchableQuery();
    }

    /**
     * The rows the record must have: one per locale with embeddable text, with the `locale` and
     * `content_hash` the embedding synchronizer stores for it.
     *
     * @return list<array{locale: string|null, content_hash: string}>
     */
    private function expectedRows(Model $model): array
    {
        if (! is_callable([$model, 'prepareDataToEmbedByLocale']) || ! is_callable([$model, 'embeddings'])) {
            return [];
        }

        $translated = class_uses_trait($model, HasTranslations::class);
        $default = LocaleContext::getDefault();
        $rows = [];

        foreach ($model->prepareDataToEmbedByLocale() as $locale => $text) {
            $rows[] = [
                'locale' => ModelEmbeddingSynchronizer::rowLocale((string) $locale, $default, $translated),
                'content_hash' => ModelEmbeddingSynchronizer::contentHash((string) $text),
            ];
        }

        return $rows;
    }
}

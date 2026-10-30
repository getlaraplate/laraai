<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Modules\Core\Search\Contracts\IReportsVectorDimensions;
use Modules\Core\Search\Contracts\ISearchEngine;
use Modules\Core\Search\Support\VectorProfileContext;
use RuntimeException;

/**
 * An in-memory search engine for the embedding switch tests: it keeps the documents it is given,
 * records every index creation with the dimensions and vector model of the profile in force at
 * that moment, and
 * reports the dimensions of the index it last created. Nothing reaches a real search service.
 */
final class RecordingSwitchSearchEngine extends Engine implements IReportsVectorDimensions, ISearchEngine
{
    /**
     * @var list<array{index: string, force: bool, dimensions: int, model_key: string|null}>
     */
    public array $createdIndexes = [];

    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    public array $documents = [];

    /**
     * @var array<string, int>
     */
    public array $dimensions = [];

    /**
     * @var list<array{index: string, vector: list<float>}>
     */
    public array $vectorQueries = [];

    /**
     * When set, a vector query throws with this message.
     */
    public ?string $failVectorQueries = null;

    /**
     * Scout keys whose document write throws, as a search engine refusing a bulk request would.
     *
     * @var list<string>
     */
    public array $refusedDocuments = [];

    /**
     * The number of documents written, over every call.
     */
    public int $writes = 0;

    public function __construct(public int $initialDimensions = 384) {}

    public function update($models): void
    {
        foreach ($models as $model) {
            if (in_array((string) $model->getScoutKey(), $this->refusedDocuments, true)) {
                throw new RuntimeException("refused to index document {$model->getScoutKey()}");
            }
        }

        foreach ($models as $model) {
            $this->writes++;
            $this->documents[$model->searchableAs()][(string) $model->getScoutKey()] = $model->toSearchableArray();
        }
    }

    public function delete($models): void
    {
        foreach ($models as $model) {
            unset($this->documents[$model->searchableAs()][(string) $model->getScoutKey()]);
        }
    }

    /**
     * @return array{total: int}
     */
    public function search(Builder $builder): array
    {
        $index = $builder->model->searchableAs();
        $vector = $builder->wheres['vector'] ?? null;

        if (is_array($vector)) {
            if ($this->failVectorQueries !== null) {
                throw new RuntimeException($this->failVectorQueries);
            }

            $this->vectorQueries[] = ['index' => $index, 'vector' => array_values($vector)];
        }

        return ['total' => count($this->documents[$index] ?? [])];
    }

    /**
     * @return array{total: int}
     */
    public function paginate(Builder $builder, $perPage, $page): array
    {
        return $this->search($builder);
    }

    public function mapIds($results): Collection
    {
        return collect();
    }

    public function map(Builder $builder, $results, $model): mixed
    {
        return $model->newCollection();
    }

    public function lazyMap(Builder $builder, $results, $model): LazyCollection
    {
        return LazyCollection::empty();
    }

    public function getTotalCount($results): int
    {
        return (int) ($results['total'] ?? 0);
    }

    public function flush($model): void
    {
        $this->documents[$model->searchableAs()] = [];
    }

    public function createIndex($name, array $options = [], bool $force = false): void
    {
        $index = $name instanceof Model ? $name->searchableAs() : (string) $name;
        $profile = VectorProfileContext::get();
        $dimensions = $profile?->dimensions ?? 0;

        $this->createdIndexes[] = [
            'index' => $index,
            'force' => $force,
            'dimensions' => $dimensions,
            'model_key' => $profile?->modelKey,
        ];

        if ($force || ! isset($this->dimensions[$index])) {
            $this->documents[$index] = [];
            $this->dimensions[$index] = $dimensions;
        }
    }

    public function deleteIndex($name): void
    {
        unset($this->documents[(string) $name], $this->dimensions[(string) $name]);
    }

    public function indexedVectorDimensions(Model $model): ?int
    {
        return $this->dimensions[$model->searchableAs()] ?? $this->initialDimensions;
    }

    public function supportsVectorSearch(): bool
    {
        return true;
    }

    public function supportsOrchestratedSearch(): bool
    {
        return false;
    }

    public function supportsOrchestratedVectorSearch(): bool
    {
        return false;
    }

    public function textMatchCapabilities(): array
    {
        return [];
    }

    public function health(): array
    {
        return [];
    }

    public function ping(): bool
    {
        return true;
    }

    public function stats(): array
    {
        return [];
    }

    public function getName(): string
    {
        return 'RecordingSwitch';
    }

    public function sync(string $modelClass, ?int $id = null, ?string $from = null): int
    {
        return 0;
    }

    public function buildSearchFilters(array $filters): array
    {
        return [];
    }

    public function getSearchMapping(Model $model): array
    {
        return [];
    }

    public function prepareDataToEmbed(Model $model): ?string
    {
        return null;
    }

    public function reindex(string $modelClass): void {}

    public function ensureSearchable(Model $model): void {}

    public function checkIndex(string|Model $model): bool
    {
        return true;
    }

    public function ensureIndex(string|Model $model): bool
    {
        return true;
    }

    public function getLastIndexedTimestamp(Model $model): ?string
    {
        return null;
    }

    /**
     * The documents of an index, by scout key.
     *
     * @return array<string, array<string, mixed>>
     */
    public function documentsOf(string $index): array
    {
        return $this->documents[$index] ?? [];
    }

    /**
     * @return list<array{index: string, force: bool, dimensions: int, model_key: string|null}>
     */
    public function forcedIndexes(): array
    {
        return array_values(array_filter($this->createdIndexes, static fn (array $call): bool => $call['force']));
    }
}

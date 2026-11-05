<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings\Switching;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Engines\CollectionEngine;
use Laravel\Scout\Engines\DatabaseEngine;
use Modules\Core\Search\Engines\ElasticsearchEngine;
use Modules\Core\Services\ElasticsearchService;

/**
 * Reads what a model's search index holds, for the verification of an embedding model switch: how
 * many documents it holds, and whether a vector query runs against it.
 */
final readonly class SearchIndexInspector
{
    /**
     * The number of documents in the model's index, or null when the engine keeps no index of its
     * own (the database and collection engines search the table itself). Elasticsearch is refreshed
     * first, so documents written a moment ago are counted.
     */
    public function documentCount(Model $model): ?int
    {
        $engine = $model->searchableUsing();

        if ($engine instanceof DatabaseEngine || $engine instanceof CollectionEngine) {
            return null;
        }

        if ($engine instanceof ElasticsearchEngine) {
            $client = ElasticsearchService::getInstance()->client;
            $index = $model->searchableAs();
            $client->indices()->refresh(['index' => $index]);

            return (int) ($client->count(['index' => $index])->asArray()['count'] ?? 0);
        }

        return (int) $engine->getTotalCount($engine->search($model::search('')));
    }

    /**
     * Runs one nearest-neighbour query with `$vector` against the model's index; throws when the
     * engine rejects it.
     *
     * @param  list<float>  $vector
     */
    public function vectorQuery(Model $model, array $vector): void
    {
        // Through the public API rather than writing into Builder::$wheres: Scout 11 changed that array's
        // shape from a field => value map to a list of field/operator/value triples.
        $builder = $model::search('')->take(1)->where('vector', $vector);

        $model->searchableUsing()->search($builder);
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Ai\SelfHosted;

/**
 * The two ways a rerank server is asked and answers, named by `ai.servers.rerank.dialect`.
 *
 * `tei` is Text Embeddings Inference: `POST /rerank {query, texts}`, answered by `[{index, score}]`.
 * `cohere` is the Cohere shape that Infinity and vLLM speak: `POST /rerank {model, query, documents}`, answered by
 * `{results: [{index, relevance_score}], model}`, sorted by score. Both give scores in [0, 1] unless asked for raw ones.
 */
enum RerankDialect: string
{
    case Tei = 'tei';
    case Cohere = 'cohere';

    /**
     * The request body for one query and its texts.
     *
     * @param  list<string>  $texts
     * @return array<string, mixed>
     */
    public function body(string $query, array $texts, ?string $model): array
    {
        return match ($this) {
            self::Tei => ['query' => $query, 'texts' => $texts, 'truncate' => true],
            self::Cohere => ['model' => $model, 'query' => $query, 'documents' => $texts],
        };
    }

    /**
     * The list of results in the answer, or null when the answer has none.
     *
     * @return list<mixed>|null
     */
    public function results(mixed $answer): ?array
    {
        $results = match ($this) {
            self::Tei => $answer,
            self::Cohere => is_array($answer) ? ($answer['results'] ?? null) : null,
        };

        return is_array($results) && array_is_list($results) ? $results : null;
    }

    /**
     * The key of a result that holds its score.
     */
    public function scoreKey(): string
    {
        return match ($this) {
            self::Tei => 'score',
            self::Cohere => 'relevance_score',
        };
    }
}

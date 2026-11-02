<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation\Analytics;

use function ai_config_string;

/**
 * The Elasticsearch index of the documentation query log: its name and its mapping.
 *
 * The mapping is strict, so a field the privacy review did not approve is refused by the index
 * instead of being stored, and it has no vector: the log is never searched by similarity. The
 * `judge_*` fields are written only while the relevance judge is on; an index created before they
 * existed refuses them, so recreate it (`ai:create-rag-query-index --force`) before turning it on.
 */
final class RagQueryLogIndex
{
    public static function name(): string
    {
        return ai_config_string('ai.features.faq.query_logging.index', 'laraplate_rag_queries');
    }

    /**
     * @return array{dynamic: string, properties: array<string, array<string, mixed>>}
     */
    public static function mappings(): array
    {
        return [
            'dynamic' => 'strict',
            'properties' => [
                'id' => ['type' => 'keyword'],
                'logged_at' => ['type' => 'date'],
                'user_ref' => ['type' => 'keyword'],
                'tenant' => ['type' => 'keyword'],
                'profile' => ['type' => 'keyword'],
                'locale' => ['type' => 'keyword'],
                'query' => [
                    'type' => 'text',
                    'fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 512]],
                ],
                'retrieved_count' => ['type' => 'integer'],
                'citation_count' => ['type' => 'integer'],
                'answered' => ['type' => 'boolean'],
                'latency_ms' => ['type' => 'float'],
                'index' => ['type' => 'keyword'],
                'judge_rounds' => ['type' => 'integer'],
                'judge_candidates' => ['type' => 'integer'],
                'judge_kept' => ['type' => 'integer'],
                'judge_fallback' => ['type' => 'boolean'],
                'judge_ms' => ['type' => 'float'],
                'judge_trace' => ['type' => 'keyword', 'ignore_above' => 2048],
                'judge_queries' => ['type' => 'text'],
            ],
        ];
    }
}

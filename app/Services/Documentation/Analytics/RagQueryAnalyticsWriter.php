<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation\Analytics;

use Elastic\Elasticsearch\Client;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes one query log document. It never fails its caller: the log is telemetry, and a missing
 * index or an unreachable cluster costs a log line, not an answer.
 *
 * An index created before the `tokens_*` fields existed refuses them under its strict mapping: the
 * document is then written again without them, so the question is still logged.
 */
final readonly class RagQueryAnalyticsWriter
{
    private const string STRICT_MAPPING_REFUSAL = 'strict_dynamic_mapping_exception';

    public function __construct(private Client $client) {}

    /**
     * @param  array<string, string|int|float|bool>  $document  a {@see RagQueryLog::toDocument()}
     */
    public function write(array $document): void
    {
        try {
            $this->index($document);
        } catch (Throwable $throwable) {
            $without_tokens = self::withoutTokens($document);

            if ($without_tokens !== $document && str_contains($throwable->getMessage(), self::STRICT_MAPPING_REFUSAL)) {
                try {
                    $this->index($without_tokens);
                    Log::notice('rag_query_analytics_tokens_not_mapped', ['index' => RagQueryLogIndex::name()]);

                    return;
                } catch (Throwable $retry) {
                    $throwable = $retry;
                }
            }

            // The exception class only: an Elasticsearch error may echo the document, question included.
            Log::warning('rag_query_analytics_write_failed', [
                'exception' => $throwable::class,
                'index' => RagQueryLogIndex::name(),
            ]);
        }
    }

    /**
     * @param  array<string, string|int|float|bool>  $document
     * @return array<string, string|int|float|bool>
     */
    private static function withoutTokens(array $document): array
    {
        return array_filter(
            $document,
            static fn (string $field): bool => ! str_starts_with($field, RagQueryLog::TOKEN_FIELD_PREFIX),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param  array<string, string|int|float|bool>  $document
     */
    private function index(array $document): void
    {
        $this->client->index([
            'index' => RagQueryLogIndex::name(),
            'id' => (string) $document['id'],
            'body' => $document,
        ]);
    }
}

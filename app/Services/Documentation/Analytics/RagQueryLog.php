<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation\Analytics;

use function ai_config_string;

use Illuminate\Support\Str;
use Modules\AI\Ai\Rag\Judge\JudgeRound;
use Modules\AI\Ai\Rag\Judge\JudgeRun;
use Modules\AI\Services\Assistance\AssistantAccessContext;

/**
 * One answered in-app documentation question, as the query log stores it: the fields that the
 * privacy review approved and nothing else (docs/rag/query-analytics-privacy-review.md).
 *
 * It is built in the request, so the job that writes it carries no user id and, when the text
 * mode is `off`, no question either.
 *
 * When the relevance judge ran, the document gains its `judge_*` fields, all scalars: the rounds,
 * the candidates judged, the documents kept, whether the unjudged candidates were used, the time of
 * the judge calls, one `round=N candidates=N kept=1,3 ms=N` entry per round, and the query of each
 * round, one per line, under the same text mode as the question. With the judge off the document
 * is the one it always was.
 */
final readonly class RagQueryLog
{
    public const string TEXT_MODE_RAW = 'raw';

    /**
     * @param  array<string, string|int|float|bool>  $judge  the `judge_*` fields; empty when the judge did not run
     */
    public function __construct(
        public string $id,
        public string $loggedAt,
        public string $userRef,
        public string $tenant,
        public string $profile,
        public string $locale,
        public ?string $query,
        public int $retrievedCount,
        public int $citationCount,
        public bool $answered,
        public float $latencyMs,
        public string $index,
        public array $judge = [],
    ) {}

    /**
     * @param  int  $retrievedCount  the documentation documents retrieval returned
     * @param  int  $citationCount  the documentation citations that grounded the answer; none is an abstention
     */
    public static function fromAnswer(
        AssistantAccessContext $access,
        string $question,
        int $retrievedCount,
        int $citationCount,
        float $latencyMs,
        ?JudgeRun $judgeRun = null,
    ): self {
        return new self(
            id: (string) Str::uuid7(),
            loggedAt: now()->toIso8601ZuluString('millisecond'),
            userRef: (new RagQueryUserReference)->for((string) $access->userId),
            tenant: $access->tenantId ?? $access->tenantScope->value ?? 'global',
            profile: $access->profile->value,
            locale: $access->locale,
            query: self::storesQuestion() ? $question : null,
            retrievedCount: $retrievedCount,
            citationCount: $citationCount,
            answered: $citationCount > 0,
            latencyMs: round($latencyMs, 2),
            index: ai_config_string('ai.features.faq.elasticsearch.user_index', ''),
            judge: $judgeRun?->applied === true ? self::judgeFields($judgeRun) : [],
        );
    }

    /**
     * @return array<string, string|int|float|bool>
     */
    public function toDocument(): array
    {
        $document = [
            'id' => $this->id,
            'logged_at' => $this->loggedAt,
            'user_ref' => $this->userRef,
            'tenant' => $this->tenant,
            'profile' => $this->profile,
            'locale' => $this->locale,
            'retrieved_count' => $this->retrievedCount,
            'citation_count' => $this->citationCount,
            'answered' => $this->answered,
            'latency_ms' => $this->latencyMs,
            'index' => $this->index,
        ];

        if ($this->query !== null) {
            $document['query'] = $this->query;
        }

        return [...$document, ...$this->judge];
    }

    /**
     * @return array<string, string|int|float|bool>
     */
    private static function judgeFields(JudgeRun $run): array
    {
        $fields = [
            'judge_rounds' => count($run->rounds),
            'judge_candidates' => $run->candidateCount(),
            'judge_kept' => $run->keptCount(),
            'judge_fallback' => $run->fallback,
            'judge_ms' => $run->milliseconds(),
            'judge_trace' => implode('; ', array_map(
                static fn (JudgeRound $round): string => sprintf(
                    'round=%d candidates=%d kept=%s ms=%s',
                    $round->round,
                    $round->candidates,
                    $round->kept === [] ? '-' : implode(',', $round->kept),
                    $round->ms,
                ),
                $run->rounds,
            )),
        ];

        if (self::storesQuestion()) {
            $fields['judge_queries'] = implode("\n", array_map(static fn (JudgeRound $round): string => $round->query, $run->rounds));
        }

        return $fields;
    }

    /**
     * Only `raw` stores the question; `off`, and any value the review did not approve, store none.
     */
    private static function storesQuestion(): bool
    {
        return ai_config_string('ai.features.faq.query_logging.query_text_mode', self::TEXT_MODE_RAW) === self::TEXT_MODE_RAW;
    }
}

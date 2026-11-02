<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag\Judge;

use function ai_config_bool;
use function ai_config_int;

use Closure;
use Illuminate\Support\Facades\Log;
use NeuronAI\RAG\Document;
use Throwable;

/**
 * The optional stage between documentation retrieval and the answer: the relevance judge keeps the
 * candidates that help answer the question and may propose a query for another round of retrieval.
 *
 * Off by default (`ai.features.rag.judge.enabled`): the candidates are then returned as given and no
 * model is called. When on, at most `max_rounds` rounds run, and they stop as soon as `min_sources`
 * documents are kept, when the judge proposes no next query or when a next query brings nothing new.
 * A document is judged once across rounds. A judge failure or timeout in any round, or no document
 * kept at all, returns the unjudged candidates of the first round; a retrieval failure on a next
 * query only ends the rounds.
 */
final readonly class RelevanceJudgeStage
{
    public function __construct(
        private RelevanceJudge $judge,
    ) {}

    public static function enabled(): bool
    {
        return ai_config_bool('ai.features.rag.judge.enabled', false);
    }

    /**
     * @param  list<Document>  $candidates  what retrieval returned for the question
     * @param  Closure(string): list<Document>  $retrieve  the same retrieval, for the query of a later round
     */
    public function run(string $question, array $candidates, Closure $retrieve): JudgeRun
    {
        if (! self::enabled()) {
            return JudgeRun::skipped($candidates);
        }

        $max_rounds = max(1, ai_config_int('ai.features.rag.judge.max_rounds', 3));
        $min_sources = max(1, ai_config_int('ai.features.rag.judge.min_sources', 3));
        $kept = [];
        $judged = [];
        $rounds = [];
        $query = $question;
        $pool = $candidates;

        for ($round = 1; $round <= $max_rounds; $round++) {
            if ($round > 1) {
                try {
                    $pool = $retrieve($query);
                } catch (Throwable $throwable) {
                    Log::notice('rag_judge_retrieval_failed', ['round' => $round, 'exception' => $throwable::class]);

                    break;
                }
            }

            $fresh = $this->notJudged($pool, $judged);

            if ($fresh === []) {
                break;
            }

            $started_at = hrtime(true);

            try {
                $verdict = $this->judge->judge($question, $fresh, $round);
            } catch (Throwable $throwable) {
                Log::warning('rag_judge_failed', ['round' => $round, 'exception' => ($throwable->getPrevious() ?? $throwable)::class]);

                return new JudgeRun($candidates, applied: true, rounds: $rounds, fallback: true);
            }

            foreach ($verdict->relevant as $number) {
                $kept[] = $fresh[$number - 1];
            }

            $rounds[] = new JudgeRound($round, $query, count($fresh), $verdict->relevant, round((hrtime(true) - $started_at) / 1_000_000, 2), $verdict->usage);

            if ($min_sources <= count($kept) || $verdict->nextQuery === null) {
                break;
            }

            $query = $verdict->nextQuery;
        }

        if ($kept === [] && $candidates !== []) {
            return new JudgeRun($candidates, applied: true, rounds: $rounds, fallback: true);
        }

        return new JudgeRun($kept, applied: true, rounds: $rounds);
    }

    /**
     * The documents of `$pool` that no round judged yet, each once; `$judged` gains their keys.
     *
     * @param  list<Document>  $pool
     * @param  array<string, true>  $judged
     * @return list<Document>
     */
    private function notJudged(array $pool, array &$judged): array
    {
        $fresh = [];

        foreach ($pool as $document) {
            $key = hash('xxh128', $document->getSourceType() . "\0" . $document->getSourceName() . "\0" . $document->getContent());

            if (isset($judged[$key])) {
                continue;
            }

            $judged[$key] = true;
            $fresh[] = $document;
        }

        return $fresh;
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag\Judge;

use NeuronAI\RAG\Document;

/**
 * The documents the answer receives after the relevance judge, and how the judge got there.
 */
final readonly class JudgeRun
{
    /**
     * @param  list<Document>  $documents  what the answer receives
     * @param  bool  $applied  whether the judge was switched on; when false, `$documents` are the candidates as given
     * @param  list<JudgeRound>  $rounds  the rounds that ended with a verdict
     * @param  bool  $fallback  whether the judge failed or kept nothing, so that `$documents` are the unjudged candidates
     */
    public function __construct(
        public array $documents,
        public bool $applied = false,
        public array $rounds = [],
        public bool $fallback = false,
    ) {}

    /**
     * @param  list<Document>  $candidates
     */
    public static function skipped(array $candidates): self
    {
        return new self($candidates);
    }

    public function candidateCount(): int
    {
        return array_sum(array_map(static fn (JudgeRound $round): int => $round->candidates, $this->rounds));
    }

    public function keptCount(): int
    {
        return array_sum(array_map(static fn (JudgeRound $round): int => count($round->kept), $this->rounds));
    }

    public function milliseconds(): float
    {
        return round(array_sum(array_map(static fn (JudgeRound $round): float => $round->ms, $this->rounds)), 2);
    }
}

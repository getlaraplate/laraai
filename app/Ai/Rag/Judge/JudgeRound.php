<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag\Judge;

/**
 * One round of the relevance judge, as the query log records it.
 */
final readonly class JudgeRound
{
    /**
     * @param  int  $round  1-based
     * @param  string  $query  the query whose candidates were judged (the question on round 1)
     * @param  int  $candidates  the candidates judged, not counting those an earlier round judged
     * @param  list<int>  $kept  the 1-based numbers of the candidates kept
     * @param  float  $ms  the time of the judge call
     */
    public function __construct(
        public int $round,
        public string $query,
        public int $candidates,
        public array $kept,
        public float $ms,
    ) {}
}

<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag\Judge;

/**
 * What the relevance judge decided about one round of candidates.
 */
final readonly class JudgeVerdict
{
    /**
     * @param  list<int>  $relevant  the 1-based numbers of the relevant candidates, ascending, each once
     * @param  ?string  $nextQuery  the query of the next round; null when the judge proposes none
     */
    public function __construct(
        public array $relevant,
        public ?string $nextQuery = null,
    ) {}
}

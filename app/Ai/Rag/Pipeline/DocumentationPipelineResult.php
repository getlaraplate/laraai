<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag\Pipeline;

use Modules\AI\Ai\Rag\Judge\JudgeRun;
use NeuronAI\RAG\Document;

/**
 * What a documentation pipeline handed on for one question.
 */
final readonly class DocumentationPipelineResult
{
    /**
     * @param  list<Document>  $documents  what the answer receives, after every post-processor
     * @param  int  $retrieved  how many documents the first retrieval found, before the post-processors
     * @param  float  $retrievalMs  the milliseconds of the first retrieval
     * @param  JudgeRun|null  $judgeRun  how the relevance judge decided, when the chain has one
     */
    public function __construct(
        public array $documents,
        public int $retrieved,
        public float $retrievalMs,
        public ?JudgeRun $judgeRun = null,
    ) {}

    public static function empty(): self
    {
        return new self([], 0, 0.0);
    }
}

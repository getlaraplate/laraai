<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag\Judge;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * The structured output the relevance judge asks the model for: a fixed schema of two keys. It is
 * the wire shape only; {@see RelevanceJudge} reads it into a {@see JudgeVerdict}, dropping numbers
 * outside the candidate list.
 */
final class JudgeAnswer
{
    /**
     * The numbers as the model wrote them: integers, or digits in a string when the schema of the
     * provider types array items as strings.
     *
     * @var list<int|string>
     */
    #[SchemaProperty(description: 'The numbers of the candidates that help answer the question, as listed (1 is the first). Empty when none does.')]
    public array $relevant = [];

    #[SchemaProperty(description: 'A search query that could find what the relevant candidates miss. Null when they answer the question.', required: false)]
    public ?string $next_query = null;
}

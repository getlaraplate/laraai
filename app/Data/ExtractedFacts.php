<?php

declare(strict_types=1);

namespace Modules\AI\Data;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * The structured output the model is asked for when it extracts the facts of a conversation. The
 * answer is an object because a structured output is one: the list is under `facts`.
 */
final class ExtractedFacts
{
    /**
     * @var list<string>
     */
    #[SchemaProperty(description: 'The key facts of the conversation, one short sentence each: user preferences, important information shared, decisions made. Empty when there are none.', required: true)]
    public array $facts = [];

    /**
     * @return list<string>
     */
    public function toList(): array
    {
        return array_values(array_filter(
            $this->facts,
            // @phpstan-ignore function.alreadyNarrowedType (the @var of the structured output is the schema asked of the model; its reply is not checked item by item)
            static fn (mixed $fact): bool => is_string($fact) && mb_trim($fact) !== '',
        ));
    }
}

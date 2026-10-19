<?php

declare(strict_types=1);

namespace Modules\AI\Data\Search;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * The structured output the model is asked for when it extracts the intent of a search query: the
 * important terms, the filters and an expanded query. Every key has a default, so an answer that
 * leaves one out still deserializes.
 */
final class SearchIntentData
{
    /**
     * @var list<string>
     */
    #[SchemaProperty(description: 'The most important search terms of the query.')]
    public array $keywords = [];

    #[SchemaProperty(description: 'The filters the query asks for.', required: false)]
    public SearchFilters $filters;

    #[SchemaProperty(description: 'The query rewritten for a better search.', required: false)]
    public SearchQueryExpansion $query_expansion;

    /**
     * Neuron sets what the answer holds and then runs this: it fills the sections that the answer left out, which the
     * sections declare as not required.
     */
    public function __construct()
    {
        $this->filters ??= new SearchFilters;
        $this->query_expansion ??= new SearchQueryExpansion;
    }
}

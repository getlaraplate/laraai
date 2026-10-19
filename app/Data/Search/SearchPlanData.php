<?php

declare(strict_types=1);

namespace Modules\AI\Data\Search;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * The structured output the model is asked for when it plans a search: the strategy and the settings of
 * each stage. Every key has a default, so an answer that leaves a section out still deserializes;
 * `SearchOrchestratorAgent` clamps whatever comes back to the ranges the search accepts.
 */
final class SearchPlanData
{
    #[SchemaProperty(description: 'The retrieval strategy: fulltext, vector or hybrid.')]
    public string $strategy = 'hybrid';

    #[SchemaProperty(description: 'Which retrievals run and how many candidates they return.', required: false)]
    public SearchRetrievalPlan $retrieval;

    #[SchemaProperty(description: 'How the results of the retrievals are merged.', required: false)]
    public SearchEnsemblePlan $ensemble;

    #[SchemaProperty(description: 'How the merged results are ranked.', required: false)]
    public SearchRankingPlan $ranking;

    #[SchemaProperty(description: 'The part of the vector search in the final score.', required: false)]
    public SearchVectorPlan $vector;

    #[SchemaProperty(description: 'The filters the query asks for.', required: false)]
    public SearchFilters $filters;

    #[SchemaProperty(description: 'When a search that scored badly is run again.', required: false)]
    public SearchRetryPolicy $retry_policy;

    /**
     * Neuron sets what the answer holds and then runs this: it fills the sections that the answer left out, which the
     * sections declare as not required.
     */
    public function __construct()
    {
        $this->retrieval ??= new SearchRetrievalPlan;
        $this->ensemble ??= new SearchEnsemblePlan;
        $this->ranking ??= new SearchRankingPlan;
        $this->vector ??= new SearchVectorPlan;
        $this->filters ??= new SearchFilters;
        $this->retry_policy ??= new SearchRetryPolicy;
    }
}

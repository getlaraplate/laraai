<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag\Judge;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use Override;

/**
 * {@see RelevanceJudgeStage} as a Neuron post-processor, so that every documentation path can put it in its
 * chain: the agent behind `ai:help` as its RAG post-processor, the in-app assistant through
 * {@see \Modules\AI\Ai\Rag\Pipeline\DocumentationPipeline}.
 *
 * A later round asks `$retrieval`, the strategy that found the candidates, with the query the judge proposed.
 * Off, it hands the documents on as they came and calls no model. The last run stays readable, for the
 * analytics of the question.
 */
final class RelevanceJudgePostProcessor implements PostProcessorInterface
{
    private ?JudgeRun $lastRun = null;

    public function __construct(
        private readonly RelevanceJudgeStage $stage,
        private readonly RetrievalInterface $retrieval,
    ) {}

    /**
     * @param  Document[]  $documents
     * @return list<Document>
     */
    #[Override]
    public function process(Message $question, array $documents): array
    {
        $this->lastRun = $this->stage->run(
            (string) $question->getContent(),
            array_values($documents),
            fn (string $query): array => array_values($this->retrieval->retrieve(new UserMessage($query))),
        );

        return $this->lastRun->documents;
    }

    /**
     * The run of the last question; null before any.
     */
    public function lastRun(): ?JudgeRun
    {
        return $this->lastRun;
    }
}

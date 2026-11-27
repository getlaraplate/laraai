<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag\Pipeline;

use Modules\AI\Ai\Rag\Judge\JudgeRun;
use Modules\AI\Ai\Rag\Judge\RelevanceJudgePostProcessor;
use Modules\AI\Ai\Rag\Judge\RelevanceJudgeStage;
use Modules\AI\Ai\Rag\Retrieval\MinimumSimilarity;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;
use NeuronAI\RAG\Retrieval\RetrievalInterface;

/**
 * The documentation half of a RAG turn, on Neuron's contracts: a retrieval strategy, then the post-processors
 * in order. The agent behind `ai:help` runs the same chain inside Neuron's RAG workflow
 * ({@see \Modules\AI\Ai\Agents\DocumentationAgent::postProcessors()}); the in-app assistant runs it here, before
 * the answer, because its guardrails validate the context before the model reads it and a refusal there is
 * an answer of the assistant, not a failed run.
 */
final readonly class DocumentationPipeline
{
    /**
     * @param  list<PostProcessorInterface>  $postProcessors
     */
    public function __construct(
        private RetrievalInterface $retrieval,
        private array $postProcessors,
    ) {}

    /**
     * The post-processors of a documentation path, in order: the similarity threshold, then the relevance
     * judge when `$judgedWith` is given, which asks that retrieval again on a later round. A retrieval that
     * applies the threshold itself, as {@see \Modules\AI\Ai\Rag\Retrieval\InAppDocumentationRetrieval} does for
     * every caller, passes `$withThreshold` false.
     *
     * @return list<PostProcessorInterface>
     */
    public static function standardPostProcessors(?RetrievalInterface $judgedWith = null, bool $withThreshold = true): array
    {
        $threshold = $withThreshold ? MinimumSimilarity::postProcessor() : null;

        return [
            ...($threshold === null ? [] : [$threshold]),
            ...($judgedWith === null ? [] : [new RelevanceJudgePostProcessor(app(RelevanceJudgeStage::class), $judgedWith)]),
        ];
    }

    public function run(string $question): DocumentationPipelineResult
    {
        $message = new UserMessage($question);
        $started_at = hrtime(true);
        $documents = array_values($this->retrieval->retrieve($message));
        $retrieval_ms = (hrtime(true) - $started_at) / 1_000_000;
        $retrieved = count($documents);

        foreach ($this->postProcessors as $processor) {
            $documents = array_values($processor->process($message, $documents));
        }

        return new DocumentationPipelineResult($documents, $retrieved, $retrieval_ms, $this->judgeRun());
    }

    private function judgeRun(): ?JudgeRun
    {
        foreach ($this->postProcessors as $processor) {
            if ($processor instanceof RelevanceJudgePostProcessor) {
                return $processor->lastRun();
            }
        }

        return null;
    }
}

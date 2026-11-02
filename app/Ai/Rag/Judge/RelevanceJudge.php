<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag\Judge;

use function ai_config_float;
use function ai_config_int;

use Closure;
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Enums\AiModelFeature;
use Modules\AI\Services\Documentation\HeadingBreadcrumb;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use RuntimeException;
use Throwable;

/**
 * Asks a model which retrieved documents help answer a question, and for a query that could find
 * what they miss. The model reads the title, the date and a bounded excerpt of each candidate,
 * answers at temperature 0 in the fixed schema of {@see JudgeAnswer}, on the provider and model
 * chosen in Settings for {@see AiModelFeature::RelevanceJudge}.
 *
 * It throws when the model fails or does not answer with a verdict: the caller
 * ({@see RelevanceJudgeStage}) decides what a failure costs.
 */
final readonly class RelevanceJudge
{
    /**
     * Times Neuron asks the model again when the answer does not fit the schema: none, because the
     * answer waits for it.
     */
    public const int MAX_RETRIES = 0;

    private const string SYSTEM_PROMPT = <<<'PROMPT'
You judge which documents help answer a question. You do not answer the question.
Read each numbered candidate and answer with JSON only:
- relevant: the numbers of the candidates that help answer the question, as listed (1 is the first); an empty list when none does.
- next_query: a search query that could find what the relevant candidates miss, or null when they answer the question.
Judge only from the title, date and excerpt of each candidate.
PROMPT;

    private const string FIRST_ROUND = 'If the candidates do not fully answer the question, write next_query as a more precise search query for what is missing.';

    private const string LATER_ROUND = 'If the candidates do not fully answer the question, write next_query as a broader search query, with synonyms of its key terms.';

    /**
     * @param  (Closure(string): ChatAgent)|null  $agentFactory  builds the agent from its system prompt
     */
    public function __construct(
        private ?Closure $agentFactory = null,
    ) {}

    /**
     * @param  list<Document>  $candidates
     * @param  int  $round  1-based: the first round asks for a more precise next query, later rounds for a broader one
     *
     * @throws RuntimeException when the model fails or does not answer with a verdict
     */
    public function judge(string $question, array $candidates, int $round): JudgeVerdict
    {
        if ($candidates === []) {
            return new JudgeVerdict([]);
        }

        try {
            $output = $this->createAgent(self::SYSTEM_PROMPT)->structured(
                new UserMessage($this->prompt($question, $candidates, $round)),
                JudgeAnswer::class,
                self::MAX_RETRIES,
            );
        } catch (Throwable $throwable) {
            throw new RuntimeException('The relevance judge is unavailable.', 0, $throwable);
        }

        throw_unless($output instanceof JudgeAnswer, RuntimeException::class, 'The relevance judge gave no verdict.');

        return new JudgeVerdict(
            $this->relevantNumbers($output->relevant, count($candidates)),
            $this->nextQuery($output->next_query),
        );
    }

    private function createAgent(string $system_prompt): ChatAgent
    {
        $agent = $this->agentFactory instanceof Closure
            ? ($this->agentFactory)($system_prompt)
            : ChatAgent::forFeature(AiModelFeature::RelevanceJudge, $system_prompt);

        return $agent
            ->withTimeout(max(0.0, ai_config_float('ai.features.rag.judge.timeout', 10.0)))
            ->withTemperature(0.0);
    }

    /**
     * @param  list<Document>  $candidates
     */
    private function prompt(string $question, array $candidates, int $round): string
    {
        $excerpt_chars = max(1, ai_config_int('ai.features.rag.judge.excerpt_chars', 600));
        $lines = ['Question: ' . $question, '', 'Candidates:'];

        foreach ($candidates as $index => $candidate) {
            $lines[] = sprintf('[%d] Title: %s', $index + 1, $this->title($candidate));
            $date = $this->date($candidate);

            if ($date !== null) {
                $lines[] = 'Date: ' . $date;
            }

            $lines[] = 'Excerpt: ' . $this->excerpt($candidate, $excerpt_chars);
            $lines[] = '';
        }

        $lines[] = $round <= 1 ? self::FIRST_ROUND : self::LATER_ROUND;

        return implode("\n", $lines);
    }

    private function title(Document $candidate): string
    {
        $title = mb_trim($candidate->getSourceName());
        $breadcrumb = HeadingBreadcrumb::fromMetadata($candidate->getMetadata()['heading_breadcrumb'] ?? null);

        if ($breadcrumb === null) {
            return $title;
        }

        return $title === '' ? $breadcrumb : $title . ' (' . $breadcrumb . ')';
    }

    private function date(Document $candidate): ?string
    {
        $metadata = $candidate->getMetadata();

        foreach (['date', 'updated_at', 'published_at', 'valid_from'] as $key) {
            $value = $metadata[$key] ?? null;

            if (is_string($value) && mb_trim($value) !== '') {
                return mb_trim($value);
            }
        }

        return null;
    }

    private function excerpt(Document $candidate, int $excerpt_chars): string
    {
        $text = mb_trim((string) preg_replace('/\s+/u', ' ', $candidate->getContent()));

        return $excerpt_chars < mb_strlen($text) ? mb_rtrim(mb_substr($text, 0, $excerpt_chars)) . '...' : $text;
    }

    /**
     * The numbers inside 1..`$count`, ascending, each once; anything else the model wrote is dropped.
     *
     * @param  array<mixed>  $relevant
     * @return list<int>
     */
    private function relevantNumbers(array $relevant, int $count): array
    {
        $numbers = [];

        foreach ($relevant as $value) {
            $number = match (true) {
                is_int($value) => $value,
                is_string($value) && ctype_digit(mb_trim($value)) => (int) mb_trim($value),
                default => null,
            };

            if ($number !== null && $number >= 1 && $number <= $count) {
                $numbers[$number] = $number;
            }
        }

        ksort($numbers);

        return array_values($numbers);
    }

    private function nextQuery(?string $next_query): ?string
    {
        $next_query = mb_trim((string) $next_query);

        return $next_query === '' ? null : $next_query;
    }
}

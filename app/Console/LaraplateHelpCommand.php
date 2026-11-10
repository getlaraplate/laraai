<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use function ai_config_bool;
use function Laravel\Prompts\text;

use Exception;
use Illuminate\Console\Command;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Services\DocumentationService;
use Override;

final class LaraplateHelpCommand extends Command
{
    #[Override]
    protected $signature = 'ai:help
                            {--question= : Ask a single question and exit}';

    #[Override]
    protected $description = 'Open a terminal RAG assistant for application documentation <fg=magenta>(✨ Modules\AI)</fg=magenta>';

    /**
     * Open a terminal RAG assistant for Laraplate documentation (interactive REPL or one-shot with --question).
     */
    public function handle(DocumentationService $documentationService): int
    {
        if (! ai_config_bool('ai.features.faq.enabled', true)) {
            $this->warn('FAQ/RAG is disabled in config (ai.features.faq.enabled).');

            return self::FAILURE;
        }

        if (! $documentationService->isAvailable()) {
            $this->warn('RAG index is not available. Run `php artisan ai:index-rag-docs` first.');

            return self::FAILURE;
        }

        $question_option = $this->option('question');
        $question = is_string($question_option) ? $question_option : '';

        if ($question !== '') {
            return $this->answerAndRender($documentationService, $question);
        }

        $this->line('Laraplate help chat is ready. Type your question, or `exit` to quit.');

        while (true) {
            $input = text('You');

            if (! is_string($input)) {
                continue;
            }

            $trimmed = mb_trim($input);

            if ($trimmed === '') {
                continue;
            }

            if (in_array(mb_strtolower($trimmed), ['exit', 'quit'], true)) {
                $this->line('Bye.');

                return self::SUCCESS;
            }

            $status = $this->answerAndRender($documentationService, $trimmed);

            if ($status !== self::SUCCESS) {
                return $status;
            }
        }
    }

    private function answerAndRender(DocumentationService $documentationService, string $question): int
    {
        try {
            $result = $documentationService->answerDeveloperQuestion(
                $question,
                AssistantProfile::DeveloperHelp,
            );
        } catch (Exception $exception) {
            $this->error('Help query failed: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('<info>Assistant:</info>');
        $this->line($result['answer'] !== '' ? $result['answer'] : '(empty answer)');

        if ($result['citations'] !== []) {
            $this->newLine();
            $this->line('<comment>Sources:</comment>');

            foreach ($this->groupBySource($result['citations']) as $group) {
                $this->line('- ' . $group['source'] . $this->describeGroup($group['passages'], $group['best']));
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * The citations of one answer by source, in the order the sources first appear: a page that supplies
     * several passages is one line, not one line per passage.
     *
     * @param  list<array{source: string, score: float|null}>  $citations
     * @return list<array{source: string, passages: int, best: float|null}>
     */
    private function groupBySource(array $citations): array
    {
        $groups = [];

        foreach ($citations as $citation) {
            $source = $citation['source'];
            $score = $citation['score'];
            $index = array_search($source, array_column($groups, 'source'), true);

            if ($index === false) {
                $groups[] = ['source' => $source, 'passages' => 1, 'best' => $score];

                continue;
            }

            $groups[$index]['passages']++;
            $groups[$index]['best'] = is_float($score) ? max($score, $groups[$index]['best'] ?? $score) : $groups[$index]['best'];
        }

        return $groups;
    }

    private function describeGroup(int $passages, ?float $best): string
    {
        if ($best === null) {
            return $passages > 1 ? sprintf(' (%d passages)', $passages) : '';
        }

        return $passages > 1
            ? sprintf(' (%d passages, best score: %.3f)', $passages, $best)
            : sprintf(' (score: %.3f)', $best);
    }
}

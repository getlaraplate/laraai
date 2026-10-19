<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation\Chunking;

use InvalidArgumentException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Splitter\AbstractSplitter;
use Override;

/**
 * Markdown-aware splitter that preserves the integrity of fenced code blocks
 * (including ```mermaid), pipe tables, and heading hierarchy when chunking
 * documents for RAG indexing.
 *
 * Atomic blocks (code fences, tables) are NEVER split, even if they exceed
 * {@see $maxWords}. Long prose paragraphs fall back to sentence-aware splitting.
 *
 * Each emitted chunk can be optionally prefixed with a heading breadcrumb
 * (e.g. "> Section: Title / Subsection A") to retain context for retrieval
 * even when the chunk is fetched in isolation.
 */
final class MarkdownAwareSplitter extends AbstractSplitter
{
    public function __construct(
        private readonly int $maxWords = 250,
        private readonly int $overlapWords = 0,
        private readonly bool $prependHeadingBreadcrumb = true,
    ) {
        throw_if($this->overlapWords >= $this->maxWords, InvalidArgumentException::class, 'Overlap must be less than maxWords.');
    }

    /**
     * @return Document[]
     */
    #[Override]
    public function splitDocument(Document $document): array
    {
        $content = $document->getContent();

        if (mb_trim($content) === '') {
            return [];
        }

        $blocks = $this->tokenizeBlocks($content);

        $chunks = $this->packBlocks($blocks);

        $output = [];

        foreach ($chunks as $chunk_text) {
            $new_document = (new Document($chunk_text))
                ->setSourceType($document->getSourceType())
                ->setSourceName($document->getSourceName())
                ->setMetadata($document->getMetadata());

            if (preg_match('/\A> Section: ([^\r\n]+)/u', $chunk_text, $matches) === 1) {
                $new_document->addMetadata('heading_breadcrumb', array_values(array_filter(
                    array_map(mb_trim(...), explode('/', $matches[1])),
                    static fn (string $heading): bool => $heading !== '',
                )));
            }

            $output[] = $new_document;
        }

        return $output;
    }

    /**
     * Walk the document line by line and group lines into typed blocks:
     * - heading: a single Markdown heading line (#, ##, ...)
     * - code: a fenced code block (``` ... ```), kept atomic
     * - table: a Markdown pipe table with header + separator + rows, kept atomic
     * - paragraph: a contiguous run of non-empty, non-block lines
     *
     * @return list<array{type: string, content: string, level: int}>
     */
    private function tokenizeBlocks(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $blocks = [];
        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            $line = $lines[$i];

            if (preg_match('/^\s*```/', $line) === 1) {
                $start = $i;
                $i++;

                while ($i < $n && preg_match('/^\s*```\s*$/', $lines[$i]) !== 1) {
                    $i++;
                }

                if ($i < $n) {
                    $i++;
                }

                $blocks[] = [
                    'type' => 'code',
                    'content' => implode("\n", array_slice($lines, $start, $i - $start)),
                    'level' => 0,
                ];

                continue;
            }

            if (preg_match('/^(#{1,6})\s+/', $line, $matches) === 1) {
                $blocks[] = [
                    'type' => 'heading',
                    'content' => $line,
                    'level' => mb_strlen($matches[1]),
                ];
                $i++;

                continue;
            }

            if ($this->isTableStart($lines, $i, $n)) {
                $start = $i;

                while ($i < $n && preg_match('/^\s*\|/', $lines[$i]) === 1) {
                    $i++;
                }

                $blocks[] = [
                    'type' => 'table',
                    'content' => implode("\n", array_slice($lines, $start, $i - $start)),
                    'level' => 0,
                ];

                continue;
            }

            if (mb_trim($line) === '') {
                $i++;

                continue;
            }

            $start = $i;

            while ($i < $n
                && mb_trim($lines[$i]) !== ''
                && preg_match('/^\s*```/', $lines[$i]) !== 1
                && preg_match('/^#{1,6}\s+/', $lines[$i]) !== 1
                && ! $this->isTableStart($lines, $i, $n)
            ) {
                $i++;
            }

            $blocks[] = [
                'type' => 'paragraph',
                'content' => implode("\n", array_slice($lines, $start, $i - $start)),
                'level' => 0,
            ];
        }

        return $blocks;
    }

    /**
     * @param  list<string>  $lines
     */
    private function isTableStart(array $lines, int $i, int $n): bool
    {
        if ($n <= $i + 1) {
            return false;
        }

        return preg_match('/^\s*\|.*\|\s*$/', $lines[$i]) === 1
            && preg_match('/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$/', $lines[$i + 1]) === 1;
    }

    /**
     * Pack tokenized blocks into chunks honouring {@see $maxWords}.
     *
     * Rules:
     * - Atomic blocks (code/table) are emitted intact, even when oversized.
     * - Headings flush the current chunk if it already contains content,
     *   then update the heading stack so the next chunk inherits the breadcrumb.
     * - Oversized prose paragraphs are split sentence-aware, each subchunk
     *   inheriting the current heading stack.
     *
     * @param  list<array{type: string, content: string, level: int}>  $blocks
     * @return list<string>
     */
    private function packBlocks(array $blocks): array
    {
        $chunks = [];
        $current = [];
        $current_words = 0;

        /** @var list<array{level: int, content: string}> $heading_stack */
        $heading_stack = [];

        foreach ($blocks as $block) {
            if ($block['type'] === 'heading') {
                if ($this->hasContentBlock($current)) {
                    $chunks[] = $this->finalizeChunk($current, $heading_stack);
                    $current = [];
                    $current_words = 0;
                }

                $heading_stack = $this->updateHeadingStack($heading_stack, $block);
                $current[] = $block;
                $current_words += $this->countWords($block['content']);

                continue;
            }

            $block_words = $this->countWords($block['content']);

            if ($block['type'] === 'code' || $block['type'] === 'table') {
                if ($block_words > $this->maxWords) {
                    if ($current !== []) {
                        $chunks[] = $this->finalizeChunk($current, $heading_stack);
                        $current = [];
                        $current_words = 0;
                    }

                    $chunks[] = $this->finalizeChunk([$block], $heading_stack);

                    continue;
                }

                if ($current !== [] && $this->maxWords < $current_words + $block_words) {
                    $chunks[] = $this->finalizeChunk($current, $heading_stack);
                    $current = [];
                    $current_words = 0;
                }

                $current[] = $block;
                $current_words += $block_words;

                continue;
            }

            if ($block_words > $this->maxWords) {
                if ($current !== []) {
                    $chunks[] = $this->finalizeChunk($current, $heading_stack);
                    $current = [];
                    $current_words = 0;
                }

                foreach ($this->splitProse($block['content']) as $sub) {
                    $chunks[] = $this->finalizeChunk(
                        [['type' => 'paragraph', 'content' => $sub, 'level' => 0]],
                        $heading_stack,
                    );
                }

                continue;
            }

            if ($current !== [] && $this->maxWords < $current_words + $block_words) {
                $chunks[] = $this->finalizeChunk($current, $heading_stack);
                $current = [];
                $current_words = 0;
            }

            $current[] = $block;
            $current_words += $block_words;
        }

        if ($current !== []) {
            $chunks[] = $this->finalizeChunk($current, $heading_stack);
        }

        return $chunks;
    }

    /**
     * @param  list<array{type: string, content: string, level: int}>  $blocks
     */
    private function hasContentBlock(array $blocks): bool
    {
        return array_any($blocks, static fn (array $block): bool => $block['type'] !== 'heading');
    }

    /**
     * Drop trailing entries deeper than the new heading and append it.
     *
     * @param  list<array{level: int, content: string}>  $stack
     * @param  array{type: string, content: string, level: int}  $heading
     * @return list<array{level: int, content: string}>
     */
    private function updateHeadingStack(array $stack, array $heading): array
    {
        $level = $heading['level'];
        $filtered = [];

        foreach ($stack as $entry) {
            if ($entry['level'] < $level) {
                $filtered[] = $entry;
            }
        }

        $filtered[] = ['level' => $level, 'content' => $heading['content']];

        return $filtered;
    }

    /**
     * @param  list<array{type: string, content: string, level: int}>  $blocks
     * @param  list<array{level: int, content: string}>  $heading_stack
     */
    private function finalizeChunk(array $blocks, array $heading_stack): string
    {
        $body = implode("\n\n", array_map(static fn (array $block): string => $block['content'], $blocks));

        if (! $this->prependHeadingBreadcrumb || $heading_stack === []) {
            return $body;
        }

        $crumbs = [];

        foreach ($heading_stack as $entry) {
            $stripped = preg_replace('/^#{1,6}\s+/', '', $entry['content']) ?? $entry['content'];
            $crumbs[] = mb_trim($stripped);
        }

        $crumbs = array_values(array_filter($crumbs, static fn (string $crumb): bool => $crumb !== ''));

        if ($crumbs === []) {
            return $body;
        }

        return '> Section: ' . implode(' / ', $crumbs) . "\n\n" . $body;
    }

    private function countWords(string $text): int
    {
        $tokens = preg_split('/\s+/u', mb_trim($text)) ?: [];
        $tokens = array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));

        return count($tokens);
    }

    /**
     * Sentence-aware fallback for oversized prose paragraphs.
     * Mirrors the spirit of {@see \NeuronAI\RAG\Splitter\SentenceTextSplitter}
     * but operates on a single paragraph string.
     *
     * @return list<string>
     */
    private function splitProse(string $text): array
    {
        $pattern = '/(?<=[.!?…])\s+(?=[A-ZÀ-Ÿ])/u';
        $sentences = preg_split($pattern, mb_trim($text)) ?: [];
        $sentences = array_values(array_filter(array_map(mb_trim(...), $sentences), static fn (string $sentence): bool => $sentence !== ''));

        $chunks = [];

        /** @var list<string> $current_words */
        $current_words = [];

        foreach ($sentences as $sentence) {
            $words = preg_split('/\s+/u', $sentence) ?: [];
            $words = array_values(array_filter($words, static fn (string $word): bool => $word !== ''));

            if ($this->maxWords < count($words)) {
                if ($current_words !== []) {
                    $chunks[] = implode(' ', $current_words);
                    $current_words = [];
                }

                foreach (array_chunk($words, max(1, $this->maxWords)) as $piece) {
                    $chunks[] = implode(' ', $piece);
                }

                continue;
            }

            if ($this->maxWords < count($current_words) + count($words)) {
                $chunks[] = implode(' ', $current_words);
                $current_words = $words;

                continue;
            }

            $current_words = array_merge($current_words, $words);
        }

        if ($current_words !== []) {
            $chunks[] = implode(' ', $current_words);
        }

        return $chunks;
    }
}

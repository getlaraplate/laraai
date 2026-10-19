<?php

declare(strict_types=1);

use Modules\AI\Services\Documentation\Chunking\MarkdownAwareSplitter;
use Modules\AI\Services\Documentation\DocumentationMetadata;
use NeuronAI\RAG\Document;

it('keeps a mermaid block atomic even when it exceeds maxWords', function (): void {
    $mermaid_lines = ['```mermaid', 'flowchart TB'];

    foreach (range(1, 200) as $i) {
        $mermaid_lines[] = "  Node{$i}[Label{$i}] --> Node" . ($i + 1) . '[Label' . ($i + 1) . ']';
    }

    $mermaid_lines[] = '```';
    $mermaid_block = implode("\n", $mermaid_lines);

    $document = new Document("# Title\n\nIntro paragraph.\n\n" . $mermaid_block . "\n\nClosing paragraph.");

    $splitter = new MarkdownAwareSplitter(maxWords: 50, overlapWords: 0, prependHeadingBreadcrumb: false);
    $chunks = $splitter->splitDocument($document);

    $mermaid_chunks = array_filter(
        array_map(fn (Document $chunk): string => $chunk->getContent(), $chunks),
        fn (string $content): bool => str_contains($content, '```mermaid'),
    );

    expect($mermaid_chunks)->toHaveCount(1);

    $mermaid_chunk_content = array_values($mermaid_chunks)[0];

    expect($mermaid_chunk_content)
        ->toContain('```mermaid')
        ->toContain('Node200')
        ->toContain('flowchart TB');

    expect(mb_substr_count($mermaid_chunk_content, '```'))->toBe(2);
});

it('keeps a fenced code block atomic for any language tag', function (): void {
    $code_lines = ['```php'];

    foreach (range(1, 80) as $i) {
        $code_lines[] = "\$variable{$i} = method{$i}();";
    }

    $code_lines[] = '```';
    $code_block = implode("\n", $code_lines);

    $document = new Document($code_block);

    $splitter = new MarkdownAwareSplitter(maxWords: 30, overlapWords: 0, prependHeadingBreadcrumb: false);
    $chunks = $splitter->splitDocument($document);

    $code_chunks = array_filter(
        array_map(fn (Document $chunk): string => $chunk->getContent(), $chunks),
        fn (string $content): bool => str_contains($content, '```php'),
    );

    expect($code_chunks)->toHaveCount(1);

    $code_chunk_content = array_values($code_chunks)[0];

    expect($code_chunk_content)->toContain('variable80');
    expect(mb_substr_count($code_chunk_content, '```'))->toBe(2);
});

it('keeps a markdown pipe table atomic', function (): void {
    $rows = ['| Name | Type | Notes |', '|------|------|-------|'];

    foreach (range(1, 30) as $i) {
        $rows[] = "| Field{$i} | string | Notes for field {$i} extra padding text |";
    }

    $table = implode("\n", $rows);
    $document = new Document("# Schema\n\n" . $table . "\n\nAfter the table.");

    $splitter = new MarkdownAwareSplitter(maxWords: 40, overlapWords: 0, prependHeadingBreadcrumb: false);
    $chunks = $splitter->splitDocument($document);

    $table_chunks = array_filter(
        array_map(fn (Document $chunk): string => $chunk->getContent(), $chunks),
        fn (string $content): bool => str_contains($content, '| Field1 |'),
    );

    expect($table_chunks)->toHaveCount(1);

    $table_chunk_content = array_values($table_chunks)[0];

    expect($table_chunk_content)
        ->toContain('| Field1 |')
        ->toContain('| Field30 |');
});

it('flushes current prose before adding a fitting atomic block that would exceed max words', function (): void {
    $document = new Document("one two three four\n\n```\nx\n```");

    $splitter = new MarkdownAwareSplitter(maxWords: 5, overlapWords: 0, prependHeadingBreadcrumb: false);
    $chunks = $splitter->splitDocument($document);
    $contents = array_map(fn (Document $chunk): string => $chunk->getContent(), $chunks);

    expect($contents)->toHaveCount(2)
        ->and($contents[0])->toBe('one two three four')
        ->and($contents[1])->toBe("```\nx\n```");
});

it('preserves atomic blocks while still splitting long prose around them', function (): void {
    $prose = str_repeat('This is a long sentence used to fill prose content. ', 60);
    $mermaid = "```mermaid\nflowchart LR\n  A --> B\n  B --> C\n```";
    $document = new Document("# Mixed\n\n" . $prose . "\n\n" . $mermaid . "\n\n" . $prose);

    $splitter = new MarkdownAwareSplitter(maxWords: 100, overlapWords: 0, prependHeadingBreadcrumb: false);
    $chunks = $splitter->splitDocument($document);

    $contents = array_map(fn (Document $chunk): string => $chunk->getContent(), $chunks);

    $mermaid_chunks = array_filter($contents, fn (string $c): bool => str_contains($c, '```mermaid'));
    expect($mermaid_chunks)->toHaveCount(1);

    $mermaid_content = array_values($mermaid_chunks)[0];
    expect(mb_substr_count($mermaid_content, '```'))->toBe(2);

    expect(count($chunks))->toBeGreaterThan(2);
});

it('prepends the heading breadcrumb to each chunk', function (): void {
    $document = new Document(
        "# Title One\n\nFirst section text.\n\n## Subsection A\n\nSubsection content here.\n\n## Subsection B\n\nMore content under B.",
    );

    $splitter = new MarkdownAwareSplitter(maxWords: 20, overlapWords: 0, prependHeadingBreadcrumb: true);
    $chunks = $splitter->splitDocument($document);

    $contents = array_map(fn (Document $chunk): string => $chunk->getContent(), $chunks);

    $under_a = array_filter($contents, fn (string $c): bool => str_contains($c, 'Subsection content here'));
    $under_b = array_filter($contents, fn (string $c): bool => str_contains($c, 'More content under B'));

    expect($under_a)->not->toBeEmpty();
    expect($under_b)->not->toBeEmpty();

    $under_a_text = array_values($under_a)[0];
    $under_b_text = array_values($under_b)[0];

    expect($under_a_text)->toContain('Title One')->toContain('Subsection A');
    expect($under_b_text)->toContain('Title One')->toContain('Subsection B');
});

it('falls back to sentence-aware splitting on plain prose without markdown', function (): void {
    $sentences = [];

    foreach (range(1, 80) as $i) {
        $sentences[] = "Sentence number {$i} contains exactly five words.";
    }

    $document = new Document(implode(' ', $sentences));

    $splitter = new MarkdownAwareSplitter(maxWords: 30, overlapWords: 0, prependHeadingBreadcrumb: false);
    $chunks = $splitter->splitDocument($document);

    expect(count($chunks))->toBeGreaterThan(1);

    foreach ($chunks as $chunk) {
        $word_count = str_word_count($chunk->getContent());
        expect($word_count)->toBeLessThanOrEqual(35);
    }
});

it('flushes current prose before adding another fitting prose block that would exceed max words', function (): void {
    $document = new Document("one two three\n\nfour five six");

    $splitter = new MarkdownAwareSplitter(maxWords: 5, overlapWords: 0, prependHeadingBreadcrumb: false);
    $chunks = $splitter->splitDocument($document);
    $contents = array_map(fn (Document $chunk): string => $chunk->getContent(), $chunks);

    expect($contents)->toBe(['one two three', 'four five six']);
});

it('omits breadcrumb prefix when headings contain no text', function (): void {
    $document = new Document("#   \n\nBody text");

    $splitter = new MarkdownAwareSplitter(maxWords: 20, overlapWords: 0, prependHeadingBreadcrumb: true);
    $chunks = $splitter->splitDocument($document);

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->getContent())->not->toStartWith('> Section:')
        ->and($chunks[0]->getContent())->toContain('Body text');
});

it('splits a single oversized sentence into max word pieces after flushing current words', function (): void {
    $document = new Document(
        'Short sentence. Another sentence has many words that must be split into multiple smaller pieces.',
    );

    $splitter = new MarkdownAwareSplitter(maxWords: 5, overlapWords: 0, prependHeadingBreadcrumb: false);
    $chunks = $splitter->splitDocument($document);
    $contents = array_map(fn (Document $chunk): string => $chunk->getContent(), $chunks);

    expect($contents[0])->toBe('Short sentence.')
        ->and($contents[1])->toBe('Another sentence has many words')
        ->and($contents[2])->toBe('that must be split into')
        ->and($contents[3])->toBe('multiple smaller pieces.');
});

it('preserves Document metadata across all emitted chunks', function (): void {
    $document = (new Document("# T\n\nParagraph content for the test."))
        ->setSourceType('files')
        ->setSourceName('erp/MODULE.md')
        ->setMetadata([
            'audience' => 'user',
            'heading_breadcrumb' => [],
        ]);

    $splitter = new MarkdownAwareSplitter(maxWords: 200);
    $chunks = $splitter->splitDocument($document);

    expect($chunks)->not->toBeEmpty();

    foreach ($chunks as $chunk) {
        expect($chunk->getSourceType())->toBe('files');
        expect($chunk->getSourceName())->toBe('erp/MODULE.md');
        expect($chunk->getMetadata())->toMatchArray(['audience' => 'user']);
        expect($chunk->getMetadata()['heading_breadcrumb'])->toBe(['T']);
    }
});

it('emits no chunks for an empty document', function (): void {
    $splitter = new MarkdownAwareSplitter;

    expect($splitter->splitDocument(new Document('')))->toBeArray()->toBeEmpty();
    expect($splitter->splitDocument(new Document("   \n\n  \t  ")))->toBeArray()->toBeEmpty();
});

it('exposes splitDocuments as a thin wrapper over splitDocument', function (): void {
    $splitter = new MarkdownAwareSplitter(maxWords: 200);
    $documents = [
        new Document("# A\n\nfirst doc"),
        new Document("# B\n\nsecond doc"),
    ];

    $chunks = $splitter->splitDocuments($documents);

    expect(count($chunks))->toBeGreaterThanOrEqual(2);
});

it('gives every chunk of a developer corpus document the normalized metadata', function (): void {
    $document = (new Document("# Guide\n\nFirst part of the guide.\n\n## Details\n\nSecond part of the guide."))
        ->setSourceType('files')
        ->setSourceName('faq-app-rag/GUIDE.md')
        ->setMetadata(['heading_breadcrumb' => []]);
    DocumentationMetadata::applyDeveloperDefaults($document);

    $chunks = (new MarkdownAwareSplitter(maxWords: 20))->splitDocument($document);

    expect($chunks)->toHaveCount(2)
        ->and($chunks[0]->getMetadata()['heading_breadcrumb'])->toBe(['Guide'])
        ->and($chunks[1]->getMetadata()['heading_breadcrumb'])->toBe(['Guide', 'Details']);

    foreach ($chunks as $chunk) {
        expect($chunk->getMetadata())->toMatchArray([
            'audience' => 'shared',
            'module' => 'app',
            'locale' => 'und',
            'canonical_source' => 'faq-app-rag/GUIDE.md',
            'source_type' => 'file',
        ]);
    }
});

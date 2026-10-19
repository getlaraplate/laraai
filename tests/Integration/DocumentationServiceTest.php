<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modules\AI\Ai\Agents\DocumentationAgent;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Ai\Rag\FaqVectorStoreConfig;
use Modules\AI\Exceptions\UnknownDocumentAudienceException;
use Modules\AI\Services\Documentation\Chunking\MarkdownAwareSplitter;
use Modules\AI\Services\DocumentationService;
use Modules\AI\Tests\Stubs\Documentation\CountingSplitter;
use Modules\AI\Tests\Stubs\Documentation\EmptySplitter;
use Modules\AI\Tests\Stubs\Documentation\ManyChunksSplitter;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\RAG\Document;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeVectorStore;

it('never splits one source across two reindexBySource batches', function (): void {
    $tmp_dir = sys_get_temp_dir() . '/ai-docs-batches-' . uniqid();
    mkdir($tmp_dir, 0755, true);
    file_put_contents($tmp_dir . '/large.md', "# Large\n\nBody.");
    file_put_contents($tmp_dir . '/small.md', "# Small\n\nBody.");

    config()->set('ai.features.faq.vector_store', 'memory');

    $chunk_splitter = new ManyChunksSplitter;

    /** @var list<array<string, int>> $calls */
    $calls = [];
    $agent_mock = Mockery::mock(DocumentationAgent::class);
    $agent_mock->shouldReceive('reindexBySource')
        ->atLeast()
        ->once()
        ->andReturnUsing(function (array $documents) use (&$calls): void {
            $per_source = [];

            foreach ($documents as $document) {
                $key = $document->sourceType . ':' . $document->sourceName;
                $per_source[$key] = ($per_source[$key] ?? 0) + 1;
            }

            $calls[] = $per_source;
        });

    $service = new DocumentationService(fn (): DocumentationAgent => $agent_mock, $chunk_splitter);

    try {
        expect($service->indexDocuments($tmp_dir))->toBe(153);

        $sources_seen = [];

        foreach ($calls as $per_source) {
            foreach ($per_source as $key => $count) {
                expect($sources_seen)->not->toHaveKey($key);
                $sources_seen[$key] = $count;
            }
        }

        expect($sources_seen)->toHaveCount(2)
            ->and(array_sum($sources_seen))->toBe(153)
            ->and(max($sources_seen))->toBe(150);
    } finally {
        unlink($tmp_dir . '/large.md');
        unlink($tmp_dir . '/small.md');
        rmdir($tmp_dir);
    }
});

it('indexDocuments returns 0 for invalid path', function (): void {
    $service = new DocumentationService;

    expect($service->indexDocuments('/nonexistent/path/xyz'))->toBe(0);
});

it('indexDocuments returns 0 for empty directory', function (): void {
    $emptyDir = sys_get_temp_dir() . '/ai-docs-empty-' . uniqid();
    mkdir($emptyDir, 0755, true);

    $service = new DocumentationService;

    try {
        expect($service->indexDocuments($emptyDir))->toBe(0);
    } finally {
        rmdir($emptyDir);
    }
});

it('indexes only explicitly classified safe documents in the user corpus', function (): void {
    $tmp_dir = sys_get_temp_dir() . '/ai-user-docs-' . uniqid();
    mkdir($tmp_dir, 0755, true);
    file_put_contents($tmp_dir . '/unclassified.md', "# Internal\n\nUnclassified details.");
    file_put_contents($tmp_dir . '/user.md', <<<'MARKDOWN'
---
audience: user
module: CMS
locale: it
canonical_source: cms/content/editing
safe_source_label: Modifica dei contenuti
required_permissions: []
tenant_scope: global
version: '1.0'
policy_classification: user_safe
policy_classification_version: in-app-docs-v1
---
# Modifica

Apri il contenuto e seleziona Modifica.
MARKDOWN);

    config()->set('ai.features.faq.vector_store', 'memory');
    config()->set('ai.features.faq.policy_classification_version', 'in-app-docs-v1');

    $agent_mock = Mockery::mock(DocumentationAgent::class);
    $agent_mock->shouldReceive('reindexBySource')
        ->once()
        ->with(Mockery::on(function (array $documents): bool {
            return count($documents) === 1
                && $documents[0]->metadata['audience'] === 'user'
                && $documents[0]->metadata['canonical_source'] === 'cms/content/editing'
                && $documents[0]->metadata['permissions_metadata_validated'] === true
                && $documents[0]->metadata['required_permissions_count'] === 0;
        }));

    $service = new DocumentationService(fn () => $agent_mock);

    try {
        expect($service->indexDocuments(
            $tmp_dir,
            false,
            DocumentationIndexProfile::User,
        ))->toBe(1);
    } finally {
        unlink($tmp_dir . '/unclassified.md');
        unlink($tmp_dir . '/user.md');
        rmdir($tmp_dir);
    }
});

it('clears a stale user store during a full rebuild with no eligible documents', function (): void {
    $tmp_dir = sys_get_temp_dir() . '/ai-user-docs-empty-' . uniqid();
    mkdir($tmp_dir, 0755, true);
    file_put_contents($tmp_dir . '/unclassified.md', "# Internal\n\nUnclassified details.");

    $developer_store = sys_get_temp_dir() . '/ai-profile-store-' . uniqid() . '.store';
    $user_store = mb_substr($developer_store, 0, -mb_strlen('.store')) . '-user.store';
    file_put_contents($user_store, 'stale user corpus');

    config()->set('ai.features.faq.vector_store', 'filesystem');
    config()->set('ai.features.faq.vector_store_path', $developer_store);

    $service = new DocumentationService;

    try {
        expect($service->indexDocuments(
            $tmp_dir,
            true,
            DocumentationIndexProfile::User,
        ))->toBe(0)
            ->and(file_exists($user_store))->toBeFalse();
    } finally {
        unlink($tmp_dir . '/unclassified.md');
        rmdir($tmp_dir);
        @unlink($developer_store);
        @unlink($user_store);
    }
});

it('isAvailable returns false when feature disabled', function (): void {
    config()->set('ai.features.faq.enabled', false);

    $service = new DocumentationService;

    expect($service->isAvailable())->toBeFalse();
});

it('isAvailable checks filesystem vector store path', function (): void {
    config()->set('ai.features.faq.enabled', true);
    config()->set('ai.features.faq.vector_store', 'filesystem');
    config()->set('ai.features.faq.vector_store_path', '/nonexistent/store.path');

    $service = new DocumentationService;

    expect($service->isAvailable())->toBeFalse();
});

it('isAvailable returns true for non-filesystem drivers', function (): void {
    config()->set('ai.features.faq.enabled', true);
    config()->set('ai.features.faq.vector_store', 'memory');

    $service = new DocumentationService;

    expect($service->isAvailable())->toBeTrue();
});

it('appendCitationsToAnswer formats citations correctly', function (): void {
    $service = new DocumentationService;
    $method = new ReflectionMethod($service, 'appendCitationsToAnswer');

    $citations = [
        ['source' => 'doc1.md', 'excerpt' => 'Excerpt 1', 'score' => 0.9],
        ['source' => 'doc2.md', 'excerpt' => 'Excerpt 2', 'score' => 0.8],
    ];

    $result = $method->invoke($service, 'The answer is here.', $citations);

    expect($result)->toContain('The answer is here.')
        ->toContain('---')
        ->toContain('**Sources:**')
        ->toContain('[1] doc1.md')
        ->toContain('[2] doc2.md');
});

it('appendCitationsToAnswer returns answer unchanged for empty citations', function (): void {
    $service = new DocumentationService;
    $method = new ReflectionMethod($service, 'appendCitationsToAnswer');

    $result = $method->invoke($service, 'Plain answer', []);

    expect($result)->toBe('Plain answer');
});

it('preserves a mermaid block intact when indexing markdown documentation', function (): void {
    $tmpDir = sys_get_temp_dir() . '/ai-docs-mermaid-' . uniqid();
    mkdir($tmpDir, 0755, true);

    $mermaid = "```mermaid\nflowchart LR\n  A --> B\n  B --> C\n  C --> D\n```";
    $body = "# Module diagram\n\nDescription paragraph one.\n\n" . $mermaid . "\n\nFurther paragraph after the diagram.";

    file_put_contents($tmpDir . '/diagram.md', $body);

    config()->set('ai.features.faq.vector_store', 'memory');
    config()->set('ai.features.faq.splitter.driver', 'markdown_aware');
    config()->set('ai.features.faq.splitter.max_words', 250);
    config()->set('ai.features.faq.splitter.prepend_heading_breadcrumb', false);

    /** @var array<int, Document> $captured */
    $captured = [];
    $agentMock = Mockery::mock(DocumentationAgent::class);
    $agentMock->shouldReceive('reindexBySource')
        ->with(Mockery::on(function (array $docs) use (&$captured): bool {
            $captured = $docs;

            return true;
        }))
        ->once();

    $service = new DocumentationService(fn (): DocumentationAgent => $agentMock);

    try {
        expect($service->indexDocuments($tmpDir))->toBeGreaterThan(0);

        $mermaid_chunks = array_filter(
            $captured,
            static fn (Document $document): bool => str_contains((string) $document->getContent(), '```mermaid'),
        );

        expect($mermaid_chunks)->toHaveCount(1);

        $first = array_values($mermaid_chunks)[0];
        $content = (string) $first->getContent();

        expect(mb_substr_count($content, '```'))->toBe(2);
        expect($content)->toContain('A --> B');
        expect($content)->toContain('C --> D');
    } finally {
        unlink($tmpDir . '/diagram.md');
        rmdir($tmpDir);
    }
});

it('honors a custom SplitterInterface passed to the constructor', function (): void {
    $tmpDir = sys_get_temp_dir() . '/ai-docs-splitter-' . uniqid();
    mkdir($tmpDir, 0755, true);
    file_put_contents($tmpDir . '/readme.md', "# Title\n\nContent.");

    config()->set('ai.features.faq.vector_store', 'memory');

    $customSplitter = new CountingSplitter;

    $agentMock = Mockery::mock(DocumentationAgent::class);
    $agentMock->shouldReceive('reindexBySource')->once();

    $service = new DocumentationService(fn (): DocumentationAgent => $agentMock, $customSplitter);

    try {
        $service->indexDocuments($tmpDir);

        expect($customSplitter->calls)->toBeGreaterThanOrEqual(1);
    } finally {
        unlink($tmpDir . '/readme.md');
        rmdir($tmpDir);
    }
});

it('indexDocuments indexes documents and returns count', function (): void {
    $tmpDir = sys_get_temp_dir() . '/ai-docs-' . uniqid();
    mkdir($tmpDir, 0755, true);
    file_put_contents($tmpDir . '/readme.md', "# Test Doc\n\nSome content for indexing.");

    $tmpStore = sys_get_temp_dir() . '/ai-vs-' . uniqid() . '.store';
    config()->set('ai.features.faq.vector_store', 'filesystem');
    config()->set('ai.features.faq.vector_store_path', $tmpStore);
    @unlink($tmpStore);

    $agentMock = Mockery::mock(DocumentationAgent::class);
    $agentMock->shouldReceive('addDocuments')
        ->with(Mockery::on(fn ($docs): bool => is_array($docs) && count($docs) >= 1))
        ->once();

    $service = new DocumentationService(fn () => $agentMock);

    try {
        $count = $service->indexDocuments($tmpDir);
        expect($count)->toBeGreaterThan(0);
    } finally {
        unlink($tmpDir . '/readme.md');
        rmdir($tmpDir);
        @unlink($tmpStore);
    }
});

it('indexDocuments uses reindexBySource when vector store driver is memory', function (): void {
    $tmpDir = sys_get_temp_dir() . '/ai-docs-mem-' . uniqid();
    mkdir($tmpDir, 0755, true);
    file_put_contents($tmpDir . '/readme.md', "# Doc\n\nParagraph.");

    config()->set('ai.features.faq.vector_store', 'memory');

    $agentMock = Mockery::mock(DocumentationAgent::class);
    $agentMock->shouldReceive('reindexBySource')
        ->with(Mockery::on(fn ($docs): bool => is_array($docs) && count($docs) >= 1))
        ->once();
    $agentMock->shouldNotReceive('addDocuments');

    $service = new DocumentationService(fn () => $agentMock);

    try {
        expect($service->indexDocuments($tmpDir))->toBeGreaterThan(0);
    } finally {
        unlink($tmpDir . '/readme.md');
        rmdir($tmpDir);
    }
});

it('indexDocuments with full rebuild uses addDocuments on memory driver', function (): void {
    $tmpDir = sys_get_temp_dir() . '/ai-docs-full-' . uniqid();
    mkdir($tmpDir, 0755, true);
    file_put_contents($tmpDir . '/readme.md', "# Doc\n\nBody.");

    config()->set('ai.features.faq.vector_store', 'memory');

    $agentMock = Mockery::mock(DocumentationAgent::class);
    $agentMock->shouldReceive('addDocuments')
        ->with(Mockery::on(fn ($docs): bool => is_array($docs) && count($docs) >= 1))
        ->once();
    $agentMock->shouldNotReceive('reindexBySource');

    $service = new DocumentationService(fn () => $agentMock);

    try {
        expect($service->indexDocuments($tmpDir, true))->toBeGreaterThan(0);
    } finally {
        unlink($tmpDir . '/readme.md');
        rmdir($tmpDir);
    }
});

it('indexDocuments with full rebuild removes filesystem vector store file before indexing', function (): void {
    $tmpDir = sys_get_temp_dir() . '/ai-docs-fsfull-' . uniqid();
    mkdir($tmpDir, 0755, true);
    file_put_contents($tmpDir . '/readme.md', "# Doc\n\nMore.");

    $tmpStore = sys_get_temp_dir() . '/ai-vs-full-' . uniqid() . '.store';
    file_put_contents($tmpStore, "stale\n");

    config()->set('ai.features.faq.vector_store', 'filesystem');
    config()->set('ai.features.faq.vector_store_path', $tmpStore);

    $agentMock = Mockery::mock(DocumentationAgent::class);
    $agentMock->shouldReceive('addDocuments')->once();

    $service = new DocumentationService(fn () => $agentMock);

    try {
        $service->indexDocuments($tmpDir, true);
        expect(file_exists($tmpStore))->toBeFalse();
    } finally {
        unlink($tmpDir . '/readme.md');
        rmdir($tmpDir);
        @unlink($tmpStore);
    }
});

/**
 * A real DocumentationAgent answering from a store that returns $hits, with the model, the store and
 * the embeddings replaced by Neuron's fakes.
 *
 * @param  list<Document>  $hits
 */
function documentationServiceOver(array $hits, string $answer = 'The answer.', ?FakeAIProvider &$provider = null): DocumentationService
{
    $provider = new FakeAIProvider(new AssistantMessage($answer));

    return new DocumentationService(
        static fn (): DocumentationAgent => DocumentationAgent::make()
            ->setAiProvider($provider)
            ->setVectorStore(new FakeVectorStore($hits))
            ->setEmbeddingsProvider(new FakeEmbeddingsProvider),
    );
}

function retrievedDocument(string $source, string $content, float $score): Document
{
    $document = new Document($content);
    $document->sourceName = $source;
    $document->setScore($score);

    return $document;
}

it('answerQuestion answers without sources when nothing was retrieved', function (): void {
    config()->set('ai.features.faq.format_citations', true);

    $result = documentationServiceOver([], 'The answer is X.')->answerQuestion('What is X?');

    expect($result)->toBe(['answer' => 'The answer is X.', 'citations' => []]);
});

it('answerQuestion cites the documents that reached the model', function (): void {
    config()->set('ai.features.faq.format_citations', true);
    config()->set('ai.features.faq.min_similarity', 0.0);

    $result = documentationServiceOver([
        retrievedDocument('doc.md', 'Excerpt from doc.', 0.95),
        retrievedDocument('other.md', str_repeat('long ', 100), 0.5),
    ], 'The answer.', $provider)->answerQuestion('Question?');

    expect($result['citations'])->toHaveCount(2)
        ->and($result['citations'][0])->toBe(['source' => 'doc.md', 'excerpt' => 'Excerpt from doc.', 'score' => 0.95])
        ->and(mb_strlen($result['citations'][1]['excerpt']))->toBeLessThanOrEqual(303)
        ->and($result['answer'])->toContain('The answer.')->toContain('**Sources:**')->toContain('[1] doc.md')->toContain('[2] other.md')
        ->and($provider->getRecorded()[0]->systemPrompt)->toContain('Source Name: doc.md');
});

it('answerQuestion does not cite, or show the model, a document below the minimum similarity', function (): void {
    config()->set('ai.features.faq.min_similarity', 0.9);

    $result = documentationServiceOver([
        retrievedDocument('relevant.md', 'Relevant.', 0.95),
        retrievedDocument('barely.md', 'Barely related.', 0.80),
    ], 'The answer.', $provider)->answerQuestion('Question?');

    expect(array_column($result['citations'], 'source'))->toBe(['relevant.md'])
        ->and($provider->getRecorded()[0]->systemPrompt)->not->toContain('barely.md');
});

it('answerQuestion keeps the sources of each question apart', function (): void {
    config()->set('ai.features.faq.min_similarity', 0.0);
    $provider = new FakeAIProvider(new AssistantMessage('First.'), new AssistantMessage('Second.'));
    $store = new FakeVectorStore([retrievedDocument('first.md', 'First.', 0.9)]);
    $service = new DocumentationService(
        static fn (): DocumentationAgent => DocumentationAgent::make()
            ->setAiProvider($provider)
            ->setVectorStore($store)
            ->setEmbeddingsProvider(new FakeEmbeddingsProvider),
    );

    $first = $service->answerQuestion('One?');
    $store->setSearchResults([]);
    $second = $service->answerQuestion('Two?');

    expect(array_column($first['citations'], 'source'))->toBe(['first.md'])
        ->and($second['citations'])->toBe([]);
});

it('includes configured absolute paths when resolving helper roots', function (): void {
    $rag_dir = sys_get_temp_dir() . '/ai-rag-roots-' . uniqid();
    mkdir($rag_dir, 0755, true);

    config()->set('ai.features.faq.documentation_path', $rag_dir);

    $service = new DocumentationService;
    $method = new ReflectionMethod($service, 'helperRoots');
    $roots = $method->invoke($service);

    try {
        expect(collect($roots)->firstWhere('path', $rag_dir))->toMatchArray([
            'path' => $rag_dir,
            'prefix' => 'faq-config',
        ]);
    } finally {
        rmdir($rag_dir);
    }
});

it('maps helper rag paths to stable source prefixes', function (): void {
    $service = new DocumentationService;
    $method = new ReflectionMethod($service, 'prefixFromHelperPath');

    expect($method->invoke($service, base_path('Modules/CMS/docs/rag')))->toBe('faq-module-CMS')
        ->and($method->invoke($service, base_path('docs/rag')))->toBe('faq-app-rag')
        ->and($method->invoke($service, '/tmp/custom-rag'))->toBe('faq-config');
});

it('returns zero when the splitter yields no chunks', function (): void {
    $tmp_dir = sys_get_temp_dir() . '/ai-docs-empty-split-' . uniqid();
    mkdir($tmp_dir, 0755, true);
    file_put_contents($tmp_dir . '/readme.md', '# Title');

    config()->set('ai.features.faq.vector_store', 'memory');

    $empty_splitter = new EmptySplitter;

    $agent_mock = Mockery::mock(DocumentationAgent::class);
    $agent_mock->shouldNotReceive('addDocuments');
    $agent_mock->shouldNotReceive('reindexBySource');

    $service = new DocumentationService(fn (): DocumentationAgent => $agent_mock, $empty_splitter);

    try {
        expect($service->indexDocuments($tmp_dir))->toBe(0);
    } finally {
        unlink($tmp_dir . '/readme.md');
        rmdir($tmp_dir);
    }
});

it('falls back to the default filesystem vector store path', function (): void {
    config()->set('ai.features.faq.vector_store_path', null);

    $service = new DocumentationService;
    $method = new ReflectionMethod($service, 'getFilesystemVectorStoreFilePath');

    expect($method->invoke($service))->toBe(storage_path('app/ai/faq-vectorstore.store'));
});

it('uses helper roots when indexing without an explicit path', function (): void {
    $rag_dir = sys_get_temp_dir() . '/ai-rag-null-path-' . uniqid();
    mkdir($rag_dir, 0755, true);
    file_put_contents($rag_dir . '/guide.md', "# Guide\n\nBody.");

    config()->set('ai.features.faq.documentation_path', $rag_dir);
    config()->set('ai.features.faq.vector_store', 'memory');

    $agent_mock = Mockery::mock(DocumentationAgent::class);
    $agent_mock->shouldReceive('reindexBySource')
        ->with(Mockery::on(static fn (array $docs): bool => count($docs) > 0 && count($docs) <= 100))
        ->atLeast()
        ->once();

    $service = new DocumentationService(fn (): DocumentationAgent => $agent_mock);

    try {
        expect($service->indexDocuments())->toBeGreaterThan(0);
    } finally {
        unlink($rag_dir . '/guide.md');
        rmdir($rag_dir);
    }
});

it('clears elasticsearch index during full rebuild', function (): void {
    $service = new DocumentationService;
    $method = new ReflectionMethod($service, 'resetVectorStoreForFullRebuild');

    expect(fn () => $method->invoke($service, 'elasticsearch'))->not->toThrow(Throwable::class);
});

it('checks elasticsearch store population for incremental reindex', function (): void {
    $service = new DocumentationService;
    $method = new ReflectionMethod($service, 'shouldUseIncrementalReindex');

    expect($method->invoke($service, 'elasticsearch'))->toBeBool();
});

it('returns no helper roots when rag_paths is unavailable', function (): void {
    $service = new DocumentationService(ragPathsResolver: static fn (): bool => false);

    $method = new ReflectionMethod(DocumentationService::class, 'helperRoots');

    expect($method->invoke($service))->toBe([]);
});

it('lists the source names a profile indexes, sorted and once each', function (): void {
    $tmp_dir = sys_get_temp_dir() . '/ai-docs-sources-' . uniqid();
    mkdir($tmp_dir, 0755, true);

    foreach (['c.md', 'a.md', 'b.md'] as $file) {
        file_put_contents($tmp_dir . '/' . $file, "# {$file}\n\nBody.");
    }

    $service = new DocumentationService;

    try {
        $names = $service->sourceNames(DocumentationIndexProfile::Developer, $tmp_dir);

        expect($names)->toHaveCount(3)
            ->and($names)->toBe(collect($names)->sort(SORT_STRING)->values()->all())
            ->and($names[0])->toEndWith('a.md')
            ->and($names[2])->toEndWith('c.md')
            ->and($service->sourceNames(DocumentationIndexProfile::User, $tmp_dir))->toBe([]);
    } finally {
        foreach (['a.md', 'b.md', 'c.md'] as $file) {
            unlink($tmp_dir . '/' . $file);
        }

        rmdir($tmp_dir);
    }
});

it('indexes only the sources of a range, replacing each of them, so a range written twice writes the same documents', function (): void {
    $tmp_dir = sys_get_temp_dir() . '/ai-docs-range-' . uniqid();
    mkdir($tmp_dir, 0755, true);

    foreach (['a.md', 'b.md', 'c.md'] as $file) {
        file_put_contents($tmp_dir . '/' . $file, "# {$file}\n\nBody.");
    }

    config()->set('ai.features.faq.vector_store', 'elasticsearch');

    /** @var list<list<string>> $batches */
    $batches = [];
    $agent_mock = Mockery::mock(DocumentationAgent::class);
    $agent_mock->shouldNotReceive('addDocuments');
    $agent_mock->shouldReceive('reindexBySource')
        ->twice()
        ->andReturnUsing(function (array $documents) use (&$batches): void {
            $batches[] = array_values(array_unique(array_map(static fn (Document $document): string => basename($document->getSourceName()), $documents)));
        });

    $service = new DocumentationService(fn (): DocumentationAgent => $agent_mock);

    try {
        $names = $service->sourceNames(DocumentationIndexProfile::Developer, $tmp_dir);

        $first = $service->indexSources(DocumentationIndexProfile::Developer, $names[1], null, $tmp_dir);
        $again = $service->indexSources(DocumentationIndexProfile::Developer, $names[1], null, $tmp_dir);

        expect($first)->toBeGreaterThan(0)
            ->and($again)->toBe($first)
            ->and($batches)->toBe([['b.md', 'c.md'], ['b.md', 'c.md']])
            ->and($service->indexSources(DocumentationIndexProfile::Developer, null, $names[0], $tmp_dir))->toBe(0);
    } finally {
        foreach (['a.md', 'b.md', 'c.md'] as $file) {
            unlink($tmp_dir . '/' . $file);
        }

        rmdir($tmp_dir);
    }
});

it('stores every developer corpus chunk with the normalized metadata, declared values first', function (): void {
    $tmp_dir = sys_get_temp_dir() . '/ai-dev-metadata-' . uniqid();
    mkdir($tmp_dir, 0755, true);
    file_put_contents($tmp_dir . '/legacy.md', "# Legacy\n\nFirst part.\n\n## More\n\nSecond part.");
    file_put_contents($tmp_dir . '/declared.md', "---\naudience: developer\nmodule: core\n---\n# Declared\n\nBody.");

    config()->set('ai.features.faq.vector_store', 'memory');

    /** @var list<Document> $stored */
    $stored = [];
    $agent_mock = Mockery::mock(DocumentationAgent::class);
    $agent_mock->shouldReceive('reindexBySource')
        ->andReturnUsing(function (array $documents) use (&$stored): void {
            array_push($stored, ...$documents);
        });

    $service = new DocumentationService(fn (): DocumentationAgent => $agent_mock, new MarkdownAwareSplitter(maxWords: 4));

    try {
        $count = $service->indexDocuments($tmp_dir);

        expect($count)->toBe(count($stored))
            ->and($count)->toBeGreaterThan(2);

        foreach ($stored as $chunk) {
            expect($chunk->metadata)->toHaveKeys(['audience', 'module', 'locale', 'canonical_source', 'heading_breadcrumb', 'source_type']);
            $prefix_and_name = explode('/', $chunk->getSourceName(), 2);

            if ($prefix_and_name[1] === 'legacy.md') {
                expect($chunk->metadata)->toMatchArray([
                    'audience' => 'shared',
                    'module' => 'app',
                    'locale' => 'und',
                    'canonical_source' => $chunk->getSourceName(),
                    'source_type' => 'file',
                ]);

                continue;
            }

            expect($chunk->metadata)->toMatchArray([
                'audience' => 'developer',
                'module' => 'core',
                'locale' => 'und',
                'canonical_source' => $chunk->getSourceName(),
                'heading_breadcrumb' => ['Declared'],
                'source_type' => 'file',
            ]);
        }
    } finally {
        File::deleteDirectory($tmp_dir);
    }
});

it('gives the user corpus none of the developer defaults', function (): void {
    $tmp_dir = sys_get_temp_dir() . '/ai-user-no-defaults-' . uniqid();
    mkdir($tmp_dir, 0755, true);
    file_put_contents($tmp_dir . '/legacy.md', "# Legacy\n\nUnclassified.");
    file_put_contents($tmp_dir . '/user.md', <<<'MARKDOWN'
---
audience: user
module: CMS
locale: it
canonical_source: cms/content/editing
safe_source_label: Modifica dei contenuti
required_permissions: []
tenant_scope: global
version: '1.0'
policy_classification: user_safe
policy_classification_version: in-app-docs-v1
---
# Modifica

Apri il contenuto.
MARKDOWN);

    config()->set('ai.features.faq.vector_store', 'memory');
    config()->set('ai.features.faq.policy_classification_version', 'in-app-docs-v1');

    /** @var list<Document> $stored */
    $stored = [];
    $agent_mock = Mockery::mock(DocumentationAgent::class);
    $agent_mock->shouldReceive('reindexBySource')
        ->andReturnUsing(function (array $documents) use (&$stored): void {
            array_push($stored, ...$documents);
        });

    $service = new DocumentationService(fn (): DocumentationAgent => $agent_mock);

    try {
        expect($service->indexDocuments($tmp_dir, false, DocumentationIndexProfile::User))->toBe(1)
            ->and($stored[0]->getSourceName())->toEndWith('/user.md')
            ->and($stored[0]->metadata)->not->toHaveKey('source_type');
    } finally {
        File::deleteDirectory($tmp_dir);
    }
});

it('stops indexing on an unknown audience before touching the store', function (DocumentationIndexProfile $profile): void {
    $tmp_dir = sys_get_temp_dir() . '/ai-bad-audience-' . uniqid();
    mkdir($tmp_dir, 0755, true);
    file_put_contents($tmp_dir . '/a-valid.md', "# Valid\n\nBody.");
    file_put_contents($tmp_dir . '/b-wrong.md', "---\naudience: internal\n---\n# Wrong\n\nBody.");

    config()->set('ai.features.faq.vector_store', 'filesystem');
    config()->set('ai.features.faq.vector_store_path', sys_get_temp_dir() . '/ai-vs-bad-audience-' . uniqid() . '.store');
    $tmp_store = FaqVectorStoreConfig::file($profile)->path;
    file_put_contents($tmp_store, "existing\n");

    $agent_mock = Mockery::mock(DocumentationAgent::class);
    $agent_mock->shouldNotReceive('addDocuments');
    $agent_mock->shouldNotReceive('reindexBySource');

    $service = new DocumentationService(fn (): DocumentationAgent => $agent_mock);

    try {
        expect(fn (): int => $service->indexDocuments($tmp_dir, true, $profile))
            ->toThrow(UnknownDocumentAudienceException::class, $tmp_dir . DIRECTORY_SEPARATOR . 'b-wrong.md')
            ->and(file_get_contents($tmp_store))->toBe("existing\n")
            ->and(fn (): array => $service->sourceNames($profile, $tmp_dir))->toThrow(UnknownDocumentAudienceException::class)
            ->and(fn () => $service->validateSources($tmp_dir))->toThrow(UnknownDocumentAudienceException::class, '"internal"');
    } finally {
        File::deleteDirectory($tmp_dir);
        @unlink($tmp_store);
    }
})->with([
    'developer' => [DocumentationIndexProfile::Developer],
    'user' => [DocumentationIndexProfile::User],
]);

it('validates sources that all declare a known audience', function (): void {
    $tmp_dir = sys_get_temp_dir() . '/ai-good-audience-' . uniqid();
    mkdir($tmp_dir, 0755, true);
    file_put_contents($tmp_dir . '/doc.md', "---\naudience: shared\n---\n# Doc\n");

    try {
        expect(fn () => (new DocumentationService)->validateSources($tmp_dir))->not->toThrow(UnknownDocumentAudienceException::class);
    } finally {
        File::deleteDirectory($tmp_dir);
    }
});

it('gives every documentation agent its own workflow id', function (): void {
    $first = DocumentationAgent::make('ollama', 'memory');
    $second = DocumentationAgent::make('ollama', 'memory');

    expect($first->getWorkflowId())->toBeString()->not->toBeEmpty()
        ->and($second->getWorkflowId())->toBeString()->not->toBe($first->getWorkflowId());
});

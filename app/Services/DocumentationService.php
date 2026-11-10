<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use function ai_config_bool;
use function ai_config_int;
use function ai_config_string;

use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\AI\Ai\Agents\DocumentationAgent;
use Modules\AI\Ai\Embeddings\EmbeddingDimensionProbe;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\EmbeddingsProviderFactory;
use Modules\AI\Ai\Providers\AiModelChoice;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Ai\Rag\ElasticsearchRagVectorStore;
use Modules\AI\Ai\Rag\FaqVectorStoreConfig;
use Modules\AI\Ai\Rag\Retrieval\DeveloperDocumentationRetrieval;
use Modules\AI\Ai\Rag\Retrieval\InAppDocumentationRetrieval;
use Modules\AI\Enums\AiModelFeature;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Exceptions\UnknownDocumentAudienceException;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Assistance\Scope\AssistantScope;
use Modules\AI\Services\Documentation\Chunking\SplitterFactory;
use Modules\AI\Services\Documentation\DocumentationMetadata;
use Modules\AI\Services\Documentation\DocumentAudiencePolicy;
use Modules\AI\Services\Documentation\FileDocumentReader;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Splitter\SplitterInterface;
use RuntimeException;
use Throwable;

final readonly class DocumentationService
{
    private const int INDEX_BATCH_SIZE = 100;

    /**
     * How many of the passages a help without a model shows whole. The answer is the passage, so a cut at the
     * length of a citation excerpt would hide the very lines a person asked for; the rest stay short, as a
     * pointer to where else to look.
     */
    private const int WHOLE_PASSAGES = 2;

    /**
     * @param  Closure(): DocumentationAgent|null  $agentFactory  Optional factory for testing
     * @param  Closure(): bool|null  $ragPathsResolver  Whether the `rag_paths()` helper is
     *                                                  available. Null asks PHP, which is what production does. Injecting it lets a test
     *                                                  describe an application without the helper without reaching into this class.
     * @param  Closure(): mixed|null  $embeddingsProbe  Embeds one text with the active embedding profile, so a run
     *                                                  learns that the embeddings answer before it writes or empties anything. Null asks the
     *                                                  profile, which is what production does.
     * @param  DeveloperDocumentationRetrieval|null  $developer_retrieval  The retrieval of a help without a model; null builds it from the container.
     * @param  Closure(DocumentationIndexProfile): ElasticsearchRagVectorStore|null  $storeFactory  The Elasticsearch store of a
     *                                                                                              profile; null builds it from the configuration, which is what production does.
     */
    public function __construct(
        private ?Closure $agentFactory = null,
        private ?SplitterInterface $splitter = null,
        private ?InAppDocumentationRetrieval $in_app_retrieval = null,
        private ?Closure $ragPathsResolver = null,
        private ?Closure $embeddingsProbe = null,
        private ?DeveloperDocumentationRetrieval $developer_retrieval = null,
        private ?Closure $storeFactory = null,
    ) {}

    /**
     * Index documentation: read markdown/html, split, embed, persist to the vector store.
     *
     * When {@code $path} is null, roots come from {@see rag_paths()}.
     * When {@code $path} is provided (CLI --path), only that root is indexed.
     *
     * When the vector store already has data (filesystem file non-empty, or memory driver), uses
     * {@see DocumentationAgent::reindexBySource()} so repeated runs update each logical file instead of duplicating chunks.
     * Pass {@code $fullRebuild} true to wipe the store and rebuild from scratch (filesystem: deletes store file; memory: resets shared store).
     *
     * `$onProgress` is told where the run is: before each batch it receives the chunks written so far, the
     * chunks in all and the sources of the batch about to be written, and once more at the end with every
     * chunk done and no sources. It is never called when there is nothing to index.
     *
     * @param  (Closure(int, int, list<string>): void)|null  $onProgress
     */
    public function indexDocuments(
        ?string $path = null,
        bool $fullRebuild = false,
        DocumentationIndexProfile $profile = DocumentationIndexProfile::Developer,
        ?Closure $onProgress = null,
    ): int {
        return $this->indexFromRoots($this->roots($path), $fullRebuild, $profile, $onProgress, pruneRemoved: $path === null);
    }

    /**
     * Brings the Elasticsearch index of `$profile` in line with the documentation, writing only what differs:
     * a source that is new, or whose fingerprint (its chunks and the active embedding model) changed since it
     * was written, is embedded and replaced; a source already indexed and unchanged is left alone, and costs no
     * embedding. A source indexed but no longer in the documentation is removed, unless `$path` limits the run
     * to one file or directory, which cannot tell what is gone from the rest.
     *
     * The index is never emptied: a source is replaced only after its new chunks were embedded.
     *
     * With `$dryRun` nothing is embedded, written or removed, and the embedding service is not asked: the report
     * says what a real run would write and remove, by source name.
     *
     * @param  (Closure(int, int, list<string>): void)|null  $onProgress  see {@see self::indexDocuments()}
     *
     * @throws RuntimeException when the vector store is not Elasticsearch, or the embeddings do not answer
     *
     * @return array{sources: int, changed: int, unchanged: int, removed: int, chunks: int, to_write: list<string>, to_remove: list<string>, dry_run: bool} The sources
     *                                                                                                                                                      read, those written (or to write), those left alone, the sources removed from the index (or to remove), the chunks
     *                                                                                                                                                      written, and the names of the sources to write and to remove.
     */
    public function syncDocuments(
        ?string $path = null,
        DocumentationIndexProfile $profile = DocumentationIndexProfile::Developer,
        ?Closure $onProgress = null,
        bool $dryRun = false,
    ): array {
        $driver = FaqVectorStoreConfig::driver();

        if ($driver !== 'elasticsearch') {
            throw new RuntimeException(sprintf('A diff needs the elasticsearch vector store, and the driver is "%s".', $driver));
        }

        $chunks = $this->splitDocuments($this->profileDocuments($this->roots($path), $profile));

        if ($chunks === []) {
            return ['sources' => 0, 'changed' => 0, 'unchanged' => 0, 'removed' => 0, 'chunks' => 0, 'to_write' => [], 'to_remove' => [], 'dry_run' => $dryRun];
        }

        $by_source = [];

        foreach ($chunks as $chunk) {
            $by_source[$chunk->getSourceName()][] = $chunk;
        }

        $store = $this->store($profile);
        $indexed = $store->sourceFingerprints();
        $changed = [];
        $to_write = [];

        foreach ($by_source as $name => $source_chunks) {
            $fingerprint = $indexed[$name] ?? null;
            $hash = $source_chunks[0]->getMetadata()[ElasticsearchRagVectorStore::SOURCE_HASH] ?? null;

            if ($fingerprint !== null && $fingerprint['hash'] === $hash && $fingerprint['chunks'] === count($source_chunks)) {
                continue;
            }

            $to_write[] = (string) $name;
            array_push($changed, ...$source_chunks);
        }

        // With a path the run sees one file or directory, and cannot tell what is gone from the rest.
        $to_remove = $path === null
            ? array_values(array_diff(array_map(strval(...), array_keys($indexed)), array_map(strval(...), array_keys($by_source))))
            : [];

        if (! $dryRun) {
            if ($changed !== []) {
                $this->assertEmbeddingsAnswer();
                $this->storeDocuments($changed, $profile, replaceSources: true, onProgress: $onProgress);
            }

            if ($to_remove !== []) {
                $store->deleteSourcesNotIn(array_map(strval(...), array_keys($by_source)));
            }
        }

        return [
            'sources' => count($by_source),
            'changed' => count($to_write),
            'unchanged' => count($by_source) - count($to_write),
            'removed' => count($to_remove),
            'chunks' => count($changed),
            'to_write' => $to_write,
            'to_remove' => $to_remove,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * The source names of the documents `$profile` indexes (from {@see rag_paths()}, or from
     * `$path` alone), sorted as strings, each once: what a caller splits into ranges for
     * {@see self::indexSources()}.
     *
     * @return list<string>
     */
    public function sourceNames(DocumentationIndexProfile $profile, ?string $path = null): array
    {
        $names = array_values(array_unique(array_map(
            static fn (Document $document): string => $document->getSourceName(),
            $this->profileDocuments($this->roots($path), $profile),
        )));

        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Indexes the documents of `$profile` whose source name is at least `$from` and below `$to`
     * (string order; a missing bound is open), embedding them with the active embedding profile.
     * Each of those sources is replaced in the store ({@see DocumentationAgent::reindexBySource()}),
     * so indexing a range again writes the same documents again. The store is never reset. Returns
     * the number of chunks written.
     */
    public function indexSources(
        DocumentationIndexProfile $profile,
        ?string $from = null,
        ?string $to = null,
        ?string $path = null,
    ): int {
        $documents = array_values(array_filter(
            $this->profileDocuments($this->roots($path), $profile),
            static function (Document $document) use ($from, $to): bool {
                $name = $document->getSourceName();

                return ($from === null || strcmp($name, $from) >= 0) && ($to === null || strcmp($name, $to) < 0);
            },
        ));

        $split_documents = $this->splitDocuments($documents);

        if ($split_documents === []) {
            return 0;
        }

        $this->storeDocuments($split_documents, $profile, replaceSources: true);

        return count($split_documents);
    }

    /**
     * Reads every documentation source (from {@see rag_paths()}, or `$path` alone) without writing
     * anything, so that a caller about to empty an index first learns whether the sources can be
     * indexed at all.
     *
     * @throws UnknownDocumentAudienceException when a source declares an unknown audience
     */
    public function validateSources(?string $path = null): void
    {
        $this->gatherDocumentsFromRoots($this->roots($path));
    }

    /**
     * Answer a question using RAG (vector search + LLM).
     *
     * @return array{answer: string, citations: list<array{source: string, excerpt: string, score: float|null}>}
     */
    public function answerQuestion(string $question): array
    {
        if (! AiModelChoice::forFeatureOrNone(AiModelFeature::Faq) instanceof AiModelChoice) {
            return $this->passagesFor($question);
        }

        $factory = $this->agentFactory ?? fn (): DocumentationAgent => DocumentationAgent::make(
            topK: ai_config_int('ai.features.faq.max_documents', 5),
        );

        /** @var DocumentationAgent $agent */
        $agent = $factory();

        $response = $agent->chat(new UserMessage($question));
        $answer = $response->getMessage()?->getContent() ?? '';
        $citations = $this->buildCitations($agent->retrievedDocuments());

        $formatted_answer = $answer;

        if (ai_config_bool('ai.features.faq.format_citations', true) && $citations !== []) {
            $formatted_answer = $this->appendCitationsToAnswer($answer, $citations);
        }

        return [
            'answer' => $formatted_answer,
            'citations' => $citations,
        ];
    }

    /**
     * Answer from the developer documentation corpus selected by a server-owned CLI profile.
     *
     * @return array{answer: string, citations: list<array{source: string, excerpt: string, score: float|null}>}
     */
    public function answerDeveloperQuestion(string $question, AssistantProfile $profile): array
    {
        if ($profile !== AssistantProfile::DeveloperHelp) {
            throw new InvalidArgumentException('Developer documentation requires the developer help profile.');
        }

        return $this->answerQuestion($question);
    }

    /**
     * Retrieve permission- and tenant-scoped documentation for in-app orchestration.
     *
     * @return list<Document>
     */
    public function retrieveForInApp(string $question, AssistantAccessContext $access, ?AssistantScope $scope = null): array
    {
        $retrieval = $this->in_app_retrieval ?? app(InAppDocumentationRetrieval::class);

        return $retrieval->retrieve($question, $access, $scope);
    }

    /**
     * The chunks of one document of the user corpus, in section order, that the person behind `$access`
     * may retrieve ({@see InAppDocumentationRetrieval::readDocument()}).
     *
     * @return array{documents: list<Document>, total: int|null}
     */
    public function readForInApp(
        string $document_key,
        AssistantAccessContext $access,
        ?AssistantScope $scope = null,
        ?int $from = null,
        ?int $to = null,
    ): array {
        $retrieval = $this->in_app_retrieval ?? app(InAppDocumentationRetrieval::class);

        return $retrieval->readDocument($document_key, $access, $scope, $from, $to);
    }

    /**
     * Check if FAQ/RAG is enabled and documentation is indexed.
     */
    public function isAvailable(): bool
    {
        if (! ai_config_bool('ai.features.faq.enabled', true)) {
            return false;
        }

        $store_driver = FaqVectorStoreConfig::driver();

        if ($store_driver === 'filesystem') {
            $path = $this->getFilesystemVectorStoreFilePath();

            return file_exists($path);
        }

        if ($store_driver === 'elasticsearch') {
            return $this->store(DocumentationIndexProfile::Developer)->hasDocuments();
        }

        return true;
    }

    /**
     * The answer of a help that has no model ({@see AiModelFeature::allowsNone()}): the passages the
     * vector search finds in the developer corpus, with their sources, and no text written by a model. The
     * first {@see self::WHOLE_PASSAGES} are shown whole, the others as the excerpt of their citation.
     *
     * @return array{answer: string, citations: list<array{source: string, excerpt: string, score: float|null}>}
     */
    private function passagesFor(string $question): array
    {
        $documents = array_values(($this->developer_retrieval ?? app(DeveloperDocumentationRetrieval::class))->retrieve($question));
        $citations = $this->buildCitations($documents);

        $lines = ['No answer model is set (features.faq.model), so these are the passages found:'];

        foreach ($citations as $index => $citation) {
            $lines[] = '';
            $lines[] = $citation['source'];
            $lines[] = $index < self::WHOLE_PASSAGES ? $documents[$index]->getContent() : $citation['excerpt'];
        }

        if ($citations === []) {
            $lines[] = '';
            $lines[] = 'No passage of the documentation matches the question.';
        }

        return [
            'answer' => implode("\n", $lines),
            'citations' => $citations,
        ];
    }

    private function ragPathsFunctionExists(): bool
    {
        if ($this->ragPathsResolver instanceof Closure) {
            return ($this->ragPathsResolver)();
        }

        return function_exists('rag_paths');
    }

    /**
     * @param  list<array{source: string, excerpt: string, score: float|null}>  $citations
     */
    private function appendCitationsToAnswer(string $answer, array $citations): string
    {
        if ($citations === []) {
            return $answer;
        }

        $citation_lines = [];

        foreach ($citations as $index => $citation) {
            $number = $index + 1;
            $source = $citation['source'];
            $citation_lines[] = "[{$number}] {$source}";
        }

        return $answer . "\n\n---\n**Sources:**\n" . implode("\n", $citation_lines);
    }

    /**
     * @param  list<Document>  $documents  the documents that reached the model
     * @return list<array{source: string, excerpt: string, score: float|null}>
     */
    private function buildCitations(array $documents): array
    {
        $citations = [];

        foreach ($documents as $document) {
            $source = $document->getSourceName();

            $citations[] = [
                'source' => $source !== '' ? $source : 'Unknown',
                'excerpt' => Str::limit($document->getContent(), 300),
                'score' => $document->getScore(),
            ];
        }

        return $citations;
    }

    private function singlePathPrefix(string $path): string
    {
        $resolved = realpath($path);

        return 'faq-cli-' . mb_substr(hash('sha256', $resolved !== false ? $resolved : $path), 0, 16);
    }

    /**
     * @return list<array{path: string, prefix: string}>
     */
    private function helperRoots(): array
    {
        if (! $this->ragPathsFunctionExists()) {
            return [];
        }

        $roots = [];

        foreach (rag_paths(onlyActive: true, prioritySort: true) as $path) {
            $path = (string) $path;

            $roots[] = [
                'path' => $path,
                'prefix' => $this->prefixFromHelperPath($path),
            ];
        }

        return $roots;
    }

    private function prefixFromHelperPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $base = str_replace('\\', '/', base_path());

        if (preg_match('#/Modules/([^/]+)/docs/rag/?$#', $normalized, $matches)) {
            return 'faq-module-' . $matches[1];
        }

        if ($normalized === $base . '/docs/rag' || $normalized === $base . '/docs/rag/') {
            return 'faq-app-rag';
        }

        return 'faq-config';
    }

    /**
     * The roots to read: `$path` alone, or every directory returned by {@see rag_paths()}.
     *
     * @return list<array{path: string, prefix: string}>
     */
    private function roots(?string $path): array
    {
        return $path !== null
            ? [['path' => $path, 'prefix' => $this->singlePathPrefix($path)]]
            : $this->helperRoots();
    }

    /**
     * @param  list<array{path: string, prefix: string}>  $roots
     */
    private function indexFromRoots(
        array $roots,
        bool $fullRebuild,
        DocumentationIndexProfile $profile,
        ?Closure $onProgress = null,
        bool $pruneRemoved = false,
    ): int {
        $documents = $this->profileDocuments($roots, $profile);
        $driver = FaqVectorStoreConfig::driver();

        $split_documents = $this->splitDocuments($documents);

        if ($split_documents !== [] && $driver !== 'memory') {
            $this->assertEmbeddingsAnswer();
        }

        // An Elasticsearch index is never emptied up front: a run that stops after the delete leaves a library with
        // nothing in it. Each source is replaced once its new chunks are embedded, and what the documentation no
        // longer has is removed at the end.
        $replaces_in_place = $driver === 'elasticsearch';

        if ($fullRebuild && ! $replaces_in_place) {
            $this->resetVectorStoreForFullRebuild($driver, $profile);
        }

        if ($split_documents === []) {
            return 0;
        }

        $this->storeDocuments(
            $split_documents,
            $profile,
            replaceSources: $fullRebuild ? $replaces_in_place : $this->shouldUseIncrementalReindex($driver, $profile),
            onProgress: $onProgress,
        );

        if ($fullRebuild && $replaces_in_place && $pruneRemoved) {
            $this->store($profile)->deleteSourcesNotIn(array_values(array_unique(array_map(
                static fn (Document $chunk): string => $chunk->getSourceName(),
                $split_documents,
            ))));
        }

        return count($split_documents);
    }

    /**
     * One text is embedded before anything is written. Without it, a run whose embedding service is down
     * spends minutes retrying batch after batch before it fails, and a full rebuild, which empties the
     * store first, would also leave it empty: when this fails the store is left as it is.
     *
     * @throws RuntimeException when the embedding service cannot embed one text
     */
    private function assertEmbeddingsAnswer(): void
    {
        $probe = $this->embeddingsProbe
            ?? static fn (): array => EmbeddingsProviderFactory::make()->embedText(EmbeddingDimensionProbe::TEXT);

        try {
            $probe();
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'The embedding service does not answer, so the index was left as it is: ' . $throwable->getMessage(),
                previous: $throwable,
            );
        }
    }

    /**
     * The documents under `$roots` that the audience policy allows in `$profile`; those of the user
     * profile are marked as validated against their required permissions, those of the developer
     * profile receive the neutral defaults of the metadata they do not declare
     * ({@see DocumentationMetadata::applyDeveloperDefaults()}), after the policy, which never sees them.
     * Every source is read before anything is written: an unknown audience throws
     * {@see UnknownDocumentAudienceException} with the store untouched, even on a full rebuild.
     *
     * @param  list<array{path: string, prefix: string}>  $roots
     * @return list<Document>
     */
    private function profileDocuments(array $roots, DocumentationIndexProfile $profile): array
    {
        $documents = $this->gatherDocumentsFromRoots($roots);
        $audience_policy = new DocumentAudiencePolicy(
            ai_config_string('ai.features.faq.policy_classification_version', 'in-app-docs-v1'),
        );
        $documents = array_values(array_filter(
            $documents,
            static fn (Document $document): bool => $audience_policy->allows($document, $profile),
        ));

        if ($profile === DocumentationIndexProfile::Developer) {
            foreach ($documents as $document) {
                DocumentationMetadata::applyDeveloperDefaults($document);
            }
        }

        if ($profile === DocumentationIndexProfile::User) {
            foreach ($documents as $document) {
                $document->addMetadata('permissions_metadata_validated', true);
                $document->addMetadata('required_permissions_count', count(
                    $document->getMetadata()['required_permissions'],
                ));
            }
        }

        return $documents;
    }

    /**
     * @param  list<Document>  $documents
     * @return list<Document>
     */
    private function splitDocuments(array $documents): array
    {
        if ($documents === []) {
            return [];
        }

        $splitter = $this->splitter ?? SplitterFactory::make();
        $split_documents = [];

        foreach ($documents as $document) {
            $chunks = array_values($splitter->splitDocument($document));

            DocumentationMetadata::markPositions($chunks);

            foreach ($chunks as $chunk) {
                $split_documents[] = $chunk;
            }
        }

        return FaqVectorStoreConfig::driver() === 'elasticsearch' ? $this->stampSourceHashes($split_documents) : $split_documents;
    }

    /**
     * Marks every chunk with the fingerprint of its source: a hash of the chunks it was split into (their text
     * and metadata) and of the embedding model that will embed them, so a diff run sees a source as changed when
     * either did.
     *
     * @param  list<Document>  $chunks
     * @return list<Document>
     */
    private function stampSourceHashes(array $chunks): array
    {
        $model_key = app(EmbeddingModelRegistry::class)->active()->key;
        $by_source = [];

        foreach ($chunks as $chunk) {
            $by_source[$chunk->getSourceType() . ':' . $chunk->getSourceName()][] = $chunk;
        }

        foreach ($by_source as $source_chunks) {
            $hash = hash('sha256', json_encode([
                $model_key,
                array_map(static fn (Document $chunk): array => [$chunk->getContent(), $chunk->getMetadata()], $source_chunks),
            ], JSON_THROW_ON_ERROR));

            foreach ($source_chunks as $chunk) {
                $chunk->addMetadata(ElasticsearchRagVectorStore::SOURCE_HASH, $hash);
            }
        }

        return $chunks;
    }

    private function store(DocumentationIndexProfile $profile): ElasticsearchRagVectorStore
    {
        return $this->storeFactory !== null
            ? ($this->storeFactory)($profile)
            : ElasticsearchRagVectorStore::fromConfig($profile, 1);
    }

    /**
     * Embeds and stores the chunks in the vector store of `$profile`, in batches that never split a
     * source; with `$replaceSources` each source already stored is deleted first.
     *
     * @param  list<Document>  $split_documents
     * @param  (Closure(int, int, list<string>): void)|null  $onProgress  see {@see self::indexDocuments()}
     */
    private function storeDocuments(
        array $split_documents,
        DocumentationIndexProfile $profile,
        bool $replaceSources,
        ?Closure $onProgress = null,
    ): void {
        /** @var DocumentationAgent $agent */
        $agent = $this->agentFactory !== null
            ? ($this->agentFactory)($profile)
            : DocumentationAgent::make(indexProfile: $profile);

        $total = count($split_documents);
        $done = 0;

        foreach ($this->batchBySource($split_documents) as $batch) {
            if ($onProgress instanceof Closure) {
                $onProgress($done, $total, array_values(array_unique(array_map(
                    static fn (Document $document): string => $document->getSourceName(),
                    $batch,
                ))));
            }

            if ($replaceSources) {
                $agent->reindexBySource($batch);
            } else {
                $agent->addDocuments($batch);
            }

            $done += count($batch);
        }

        if ($onProgress instanceof Closure) {
            $onProgress($total, $total, []);
        }
    }

    /**
     * Packs chunks into batches of about {@see self::INDEX_BATCH_SIZE} documents without ever
     * splitting one source across two batches: {@see DocumentationAgent::reindexBySource()}
     * deletes each source it receives before adding it, so a later batch holding the tail of a
     * source would delete the chunks an earlier batch just added. A source larger than the
     * batch size becomes a batch of its own.
     *
     * @param  list<Document>  $documents
     * @return list<list<Document>>
     */
    private function batchBySource(array $documents): array
    {
        /** @var array<string, list<Document>> $by_source */
        $by_source = [];

        foreach ($documents as $document) {
            $by_source[$document->getSourceType() . ':' . $document->getSourceName()][] = $document;
        }

        $batches = [];
        $current = [];

        foreach ($by_source as $source_documents) {
            if ($current !== [] && count($current) + count($source_documents) > self::INDEX_BATCH_SIZE) {
                $batches[] = $current;
                $current = [];
            }

            array_push($current, ...$source_documents);
        }

        if ($current !== []) {
            $batches[] = $current;
        }

        return $batches;
    }

    private function shouldUseIncrementalReindex(
        string $driver,
        DocumentationIndexProfile $profile = DocumentationIndexProfile::Developer,
    ): bool {
        if ($driver === 'memory') {
            return true;
        }

        if ($driver === 'elasticsearch') {
            return $this->store($profile)->hasDocuments();
        }

        return $this->filesystemVectorStoreHasData($profile);
    }

    private function filesystemVectorStoreHasData(
        DocumentationIndexProfile $profile = DocumentationIndexProfile::Developer,
    ): bool {
        $path = $this->getFilesystemVectorStoreFilePath($profile);

        return is_file($path) && filesize($path) > 0;
    }

    private function getFilesystemVectorStoreFilePath(
        DocumentationIndexProfile $profile = DocumentationIndexProfile::Developer,
    ): string {
        return FaqVectorStoreConfig::file($profile)->path;
    }

    private function resetVectorStoreForFullRebuild(
        string $driver,
        DocumentationIndexProfile $profile = DocumentationIndexProfile::Developer,
    ): void {
        if ($driver === 'memory') {
            DocumentationAgent::resetSharedMemoryVectorStore($profile);

            return;
        }

        $store_path = $this->getFilesystemVectorStoreFilePath($profile);

        if (is_file($store_path)) {
            unlink($store_path);
        }

        $this->removeStoreLock($store_path . '.lock');
    }

    /**
     * The 4.x file store keeps a lock file beside its store. It is removed only when nobody holds it,
     * so that a process writing to the store keeps its lock.
     */
    private function removeStoreLock(string $lock_path): void
    {
        $lock = is_file($lock_path) ? @fopen($lock_path, 'r') : false;

        if ($lock === false) {
            return;
        }

        if (flock($lock, LOCK_EX | LOCK_NB)) {
            unlink($lock_path);
            flock($lock, LOCK_UN);
        }

        fclose($lock);
    }

    /**
     * @param  list<array{path: string, prefix: string}>  $roots
     * @return list<Document>
     */
    private function gatherDocumentsFromRoots(array $roots): array
    {
        $documents = [];

        foreach ($roots as $root) {
            $physical = $root['path'];
            $prefix = $root['prefix'];

            if (! is_dir($physical) && ! is_file($physical)) {
                continue;
            }

            $reader = new FileDocumentReader($physical, FileDocumentReader::DOCUMENT_EXTENSIONS, $prefix);
            $documents = [...$documents, ...$reader->getDocuments()];
        }

        return array_values($documents);
    }
}

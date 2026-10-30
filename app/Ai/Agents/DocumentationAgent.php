<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Agents;

use Illuminate\Support\Str;
use Modules\AI\Ai\Embeddings\EmbeddingsProviderFactory;
use Modules\AI\Ai\Providers\AiModelChoice;
use Modules\AI\Ai\Providers\ProviderFactory;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Ai\Rag\ElasticsearchRagVectorStore;
use Modules\AI\Ai\Rag\FaqVectorStoreConfig;
use Modules\AI\Ai\Rag\RecordingPostProcessor;
use Modules\AI\Ai\Rag\Retrieval\MinimumSimilarity;
use Modules\AI\Enums\AiModelFeature;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use Override;

/**
 * RAG agent for answering questions using indexed documentation.
 *
 * @method static static make(?string $providerName = null, ?string $vectorStoreDriver = null, ?string $vectorStorePath = null, int $topK = 5, DocumentationIndexProfile $indexProfile = DocumentationIndexProfile::Developer)
 */
class DocumentationAgent extends RAG
{
    /**
     * @var array<string, MemoryVectorStore>
     */
    private static array $shared_memory_stores = [];

    private ?RecordingPostProcessor $recorder = null;

    public function __construct(
        protected ?string $providerName = null,
        protected ?string $vectorStoreDriver = null,
        protected ?string $vectorStorePath = null,
        protected int $topK = 5,
        protected DocumentationIndexProfile $indexProfile = DocumentationIndexProfile::Developer,
    ) {
        // Neuron runs nothing without a thread id; the application keeps its own conversations, so each agent gets a throwaway one.
        parent::__construct(workflowId: (string) Str::uuid7());
    }

    /**
     * Clears the in-memory vector store singleton (used when FAQ vector_store driver is "memory" and a full rebuild is requested).
     */
    public static function resetSharedMemoryVectorStore(?DocumentationIndexProfile $profile = null): void
    {
        if ($profile === null) {
            self::$shared_memory_stores = [];

            return;
        }

        unset(self::$shared_memory_stores[$profile->value]);
    }

    /**
     * The documents that reached the model on the last question, after the similarity threshold:
     * what the answer is entitled to cite.
     *
     * @return list<Document>
     */
    public function retrievedDocuments(): array
    {
        return $this->recorder()->documents();
    }

    /**
     * The same threshold as the retrieval that `test:ai:evaluate-documentation` measures, then the recorder.
     *
     * @return list<PostProcessorInterface>
     */
    #[Override]
    protected function postProcessors(): array
    {
        $threshold = MinimumSimilarity::postProcessor();

        return [
            ...($threshold === null ? [] : [$threshold]),
            $this->recorder(),
        ];
    }

    protected function provider(): AIProviderInterface
    {
        if ($this->providerName !== null) {
            return ProviderFactory::make($this->providerName);
        }

        $choice = AiModelChoice::forFeature(AiModelFeature::Faq);

        return ProviderFactory::make($choice->provider, $choice->model);
    }

    #[Override]
    protected function instructions(): SystemMessage
    {
        return new SystemMessage(<<<'PROMPT'
You are a documentation assistant. Answer questions based on the provided context documents.
If the context doesn't contain enough information, say so honestly.
Always reference specific documents when possible.
Respond in the same language as the question.
PROMPT);
    }

    /**
     * @codeCoverageIgnore
     */
    protected function embeddings(): EmbeddingsProviderInterface
    {
        return EmbeddingsProviderFactory::make();
    }

    /**
     * @codeCoverageIgnore
     */
    protected function vectorStore(): VectorStoreInterface
    {
        $driver = $this->vectorStoreDriver ?? FaqVectorStoreConfig::driver();

        return match ($driver) {
            'memory' => self::$shared_memory_stores[$this->indexProfile->value] ??= new MemoryVectorStore($this->topK),
            'elasticsearch' => ElasticsearchRagVectorStore::fromConfig($this->indexProfile, $this->topK),
            default => $this->fileVectorStore(),
        };
    }

    private function recorder(): RecordingPostProcessor
    {
        return $this->recorder ??= new RecordingPostProcessor;
    }

    /**
     * The store writes the file that {@see FaqVectorStoreConfig::file()} names, with the extension of
     * that name: Neuron joins its own `.store` otherwise, and the file would not be the one the service
     * looks for.
     *
     * @codeCoverageIgnore
     */
    private function fileVectorStore(): FileVectorStore
    {
        $file = FaqVectorStoreConfig::file($this->indexProfile, $this->vectorStorePath);

        return new FileVectorStore(
            directory: $file->directory(),
            topK: $this->topK,
            name: $file->name(),
            ext: $file->extension(),
        );
    }
}

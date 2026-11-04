<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag\Retrieval;

use function ai_config_string;

use Closure;
use InvalidArgumentException;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Ai\Rag\ElasticsearchRagVectorStore;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Assistance\Scope\AssistantScope;
use Modules\AI\Services\Documentation\DocumentAudiencePolicy;
use NeuronAI\RAG\Document;
use RuntimeException;
use Throwable;

final readonly class InAppDocumentationRetrieval
{
    use EmbedsDocumentationQuery;

    /**
     * @param  (Closure(array<float>, DocumentationRetrievalContext): array<Document>)|null  $search
     * @param  (Closure(string, DocumentationRetrievalContext, int|null, int|null): array{documents: array<Document>, total: int|null})|null  $read
     */
    public function __construct(
        private IEmbeddingService $embedding_service,
        private ?Closure $search = null,
        private ?Closure $read = null,
    ) {}

    /**
     * @return list<Document>
     */
    public function retrieve(string $question, AssistantAccessContext $access, ?AssistantScope $scope = null): array
    {
        $question = mb_trim($question);

        if ($question === '') {
            throw new InvalidArgumentException('In-app documentation question cannot be blank.');
        }

        $context = $scope === null
            ? DocumentationRetrievalContext::fromAccessContext($access)
            : DocumentationRetrievalContext::fromAccessContextAndScope($access, $scope);

        try {
            $embedding = $this->embedQuestion($question);

            $documents = $this->search !== null
                ? ($this->search)($embedding, $context)
                : $this->searchUserIndex($embedding, $context);

            return MinimumSimilarity::filter($this->safeDocuments($documents));
        } catch (Throwable) {
            throw new RuntimeException('In-app documentation retrieval is unavailable.');
        }
    }

    /**
     * The chunks of one document, in section order, that the person may retrieve: the access context and
     * scope of {@see self::retrieve()}, the same safe projection, and a term on the document key. An empty
     * list means no such document for this person, whatever the reason. `$from` and `$to` bound the
     * sections (inclusive); `total` is the section count of the document when the index knows it.
     *
     *
     * @throws RuntimeException when retrieval is unavailable
     *
     * @return array{documents: list<Document>, total: int|null}
     */
    public function readDocument(
        string $document_key,
        AssistantAccessContext $access,
        ?AssistantScope $scope = null,
        ?int $from = null,
        ?int $to = null,
    ): array {
        $context = $scope === null
            ? DocumentationRetrievalContext::fromAccessContext($access)
            : DocumentationRetrievalContext::fromAccessContextAndScope($access, $scope);

        try {
            $result = $this->read !== null
                ? ($this->read)($document_key, $context, $from, $to)
                : $this->readUserIndex($document_key, $context, $from, $to);

            return ['documents' => $this->safeDocuments($result['documents']), 'total' => $result['total']];
        } catch (Throwable) {
            throw new RuntimeException('In-app documentation retrieval is unavailable.');
        }
    }

    /**
     * @return array{documents: list<Document>, total: int|null}
     */
    private function readUserIndex(string $document_key, DocumentationRetrievalContext $context, ?int $from, ?int $to): array
    {
        $store = ElasticsearchRagVectorStore::fromConfig(DocumentationIndexProfile::User, $context->topK);
        $result = $store->chunksOfDocument($document_key, $context, $from, $to);

        if ($result['documents'] === [] && ! $store->hasDocuments()) {
            throw new RuntimeException;
        }

        return ['documents' => array_values($result['documents']), 'total' => $result['total']];
    }

    /**
     * @param  list<float>  $embedding
     * @return list<Document>
     */
    private function searchUserIndex(array $embedding, DocumentationRetrievalContext $context): array
    {
        $store = ElasticsearchRagVectorStore::fromConfig(
            DocumentationIndexProfile::User,
            $context->topK,
        );

        $documents = $store->similaritySearchForContext($embedding, $context);

        if ($documents === [] && ! $store->hasDocuments()) {
            throw new RuntimeException;
        }

        return $documents;
    }

    /**
     * @param  array<Document>  $documents
     * @return list<Document>
     */
    private function safeDocuments(array $documents): array
    {
        $classification_version = ai_config_string(
            'ai.features.faq.policy_classification_version',
            'in-app-docs-v1',
        );
        $policy = new DocumentAudiencePolicy($classification_version);
        $safe_documents = [];

        foreach ($documents as $document) {
            if (! $document instanceof Document
                || ! $policy->allows($document, DocumentationIndexProfile::User)) {
                throw new RuntimeException;
            }

            $metadata = $document->getMetadata();

            if (($metadata['permissions_metadata_validated'] ?? null) !== true
                || ($metadata['required_permissions_count'] ?? null) !== count($metadata['required_permissions'])) {
                throw new RuntimeException;
            }

            $safe_documents[] = (new Document($document->getContent()))
                ->setSourceType('documentation')
                ->setSourceName((string) $metadata['safe_source_label'])
                ->setMetadata(array_intersect_key($metadata, array_flip([
                    'audience',
                    'document_key',
                    'heading_breadcrumb',
                    'locale',
                    'module',
                    'safe_source_label',
                    'section',
                    'version',
                ])))
                ->setScore($document->getScore());
        }

        return $safe_documents;
    }
}

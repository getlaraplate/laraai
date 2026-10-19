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
     */
    public function __construct(
        private IEmbeddingService $embedding_service,
        private ?Closure $search = null,
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
                ->setSourceName($metadata['safe_source_label'])
                ->setMetadata(array_intersect_key($metadata, array_flip([
                    'audience',
                    'heading_breadcrumb',
                    'locale',
                    'module',
                    'safe_source_label',
                    'version',
                ])))
                ->setScore($document->getScore());
        }

        return $safe_documents;
    }
}

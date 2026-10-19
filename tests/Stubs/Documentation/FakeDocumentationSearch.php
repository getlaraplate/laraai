<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Documentation;

use Closure;
use Modules\AI\Ai\Rag\Retrieval\DeveloperDocumentationRetrieval;
use Modules\AI\Ai\Rag\Retrieval\DocumentationRetrievalContext;
use Modules\AI\Ai\Rag\Retrieval\InAppDocumentationRetrieval;
use NeuronAI\RAG\Document;

final class FakeDocumentationSearch
{
    /**
     * @param  array<int, list<Document>>  $rankedByHash
     */
    private array $rankedByHash;

    /**
     * @param  array<string, list<Document>>  $rankedByQuery
     */
    public function __construct(array $rankedByQuery)
    {
        $indexed = [];

        foreach ($rankedByQuery as $query => $documents) {
            $indexed[crc32((string) $query)] = $documents;
        }

        $this->rankedByHash = $indexed;
    }

    /**
     * @param  list<float>  $embedding
     * @return list<Document>
     */
    public function __invoke(array $embedding, DocumentationRetrievalContext $context): array
    {
        $key = (int) ($embedding[0] ?? 0);
        $candidates = $this->rankedByHash[$key] ?? [];
        $allowed = [];

        foreach ($candidates as $document) {
            if ($this->isVisible($document, $context)) {
                $allowed[] = $document;
            }

            if ($context->topK <= count($allowed)) {
                break;
            }
        }

        return $allowed;
    }

    /**
     * @param  array<string, list<Document>>  $rankedByQuery
     */
    public static function forInAppRetrieval(array $rankedByQuery): InAppDocumentationRetrieval
    {
        $search = new self($rankedByQuery);

        return new InAppDocumentationRetrieval(
            new StubDocumentationEmbeddingService,
            Closure::fromCallable($search),
        );
    }

    /**
     * @param  array<string, list<Document>>  $rankedByQuery
     */
    public static function forDeveloperRetrieval(array $rankedByQuery): DeveloperDocumentationRetrieval
    {
        $search = new self($rankedByQuery);

        return new DeveloperDocumentationRetrieval(
            new StubDocumentationEmbeddingService,
            static fn (array $embedding): array => $search->developerRanked($embedding),
        );
    }

    /**
     * @param  list<string>  $breadcrumb
     * @param  list<string>  $requiredPermissions
     */
    public static function document(
        string $label,
        string $locale,
        string $content,
        array $breadcrumb,
        array $requiredPermissions = [],
        string $tenantScope = 'global',
        ?string $tenantId = null,
        string $classificationVersion = 'in-app-docs-v1',
        string $module = 'core',
        bool $crossCuttingUser = false,
    ): Document {
        $metadata = [
            'audience' => 'user',
            'module' => $module,
            'locale' => $locale,
            'canonical_source' => 'core/' . mb_strtolower(str_replace([' · ', ' '], ['/', '-'], $label)),
            'safe_source_label' => $label,
            'version' => '1',
            'policy_classification' => 'user_safe',
            'policy_classification_version' => $classificationVersion,
            'required_permissions' => $requiredPermissions,
            'required_permissions_count' => count($requiredPermissions),
            'permissions_metadata_validated' => true,
            'heading_breadcrumb' => $breadcrumb,
            'tenant_scope' => $tenantScope,
            'cross_cutting_user' => $crossCuttingUser,
        ];

        if ($tenantId !== null) {
            $metadata['tenant_id'] = $tenantId;
        }

        return (new Document($content))
            ->setSourceType('documentation')
            ->setSourceName($label)
            ->setMetadata($metadata)
            ->setScore(1.0);
    }

    /**
     * @param  list<float>  $embedding
     * @return list<Document>
     */
    public function developerRanked(array $embedding): array
    {
        return $this->rankedByHash[(int) ($embedding[0] ?? 0)] ?? [];
    }

    private function isVisible(Document $document, DocumentationRetrievalContext $context): bool
    {
        $metadata = $document->getMetadata();

        if (($metadata['locale'] ?? null) !== $context->locale) {
            return false;
        }

        $scope = $metadata['tenant_scope'] ?? null;

        if ($scope === 'tenant'
            && ($context->tenantScope->value !== 'tenant' || ($metadata['tenant_id'] ?? null) !== $context->tenantId)) {
            return false;
        }

        if ($context->docScope === \Modules\AI\Services\Assistance\Scope\DocScope::Module && $context->moduleKey !== null) {
            $module = $metadata['module'] ?? null;
            $crossCutting = ($metadata['cross_cutting_user'] ?? false) === true;

            if ($module !== $context->moduleKey && ! $crossCutting) {
                return false;
            }
        }

        $required = $metadata['required_permissions'] ?? [];

        return array_values(array_diff($required, $context->effectivePermissions)) === [];
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation\Search;

use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Services\Assistance\AssistantAccessContextFactory;
use Modules\AI\Services\Assistance\Scope\AssistantScopeResolver;
use Modules\AI\Services\Documentation\DocumentationMetadata;
use Modules\AI\Services\DocumentationService;
use Modules\Core\Models\User;
use RuntimeException;

/**
 * Reads a documentation document of one module as the given user: the same access context, scope and
 * safe projection as {@see DocumentationSearch}, plus a term on the document key. The index is the only
 * source, so audience, permissions, tenant and module scope stay decided in one place.
 */
final readonly class DocumentationReader
{
    public function __construct(
        private DocumentationService $documentation,
        private AssistantAccessContextFactory $access_contexts,
        private AssistantScopeResolver $scope_resolver,
    ) {}

    /**
     * With `$section` the section itself and one neighbour on each side, without it every section the
     * read allows. Null when the person cannot read such a document, whether it does not exist, belongs
     * to another module, locale or tenant, or needs a permission they lack.
     *
     * @throws RuntimeException when retrieval is unavailable
     */
    public function read(User $user, string $module, string $reference, ?int $section = null): ?DocumentationRead
    {
        if (! DocumentationMetadata::isDocumentKey($reference)) {
            return null;
        }

        $access = $this->access_contexts->forDocumentationSearch($user);
        $scope = $this->scope_resolver->resolve(AssistantProfile::InAppAssistance, $module);
        $result = $this->documentation->readForInApp(
            $reference,
            $access,
            $scope,
            $section === null ? null : max($section - 1, 0),
            $section === null ? null : $section + 1,
        );

        $sections = [];

        foreach ($result['documents'] as $document) {
            $read = DocumentationSection::fromDocument($document);

            if ($read instanceof DocumentationSection) {
                $sections[] = $read;
            }
        }

        if ($sections === []) {
            return null;
        }

        $first = $result['documents'][0];

        return new DocumentationRead(
            reference: $reference,
            label: mb_trim($first->getSourceName()),
            module: is_string($first->getMetadata()['module'] ?? null) ? $first->getMetadata()['module'] : null,
            total: max($result['total'] ?? 0, $sections[array_key_last($sections)]->section + 1),
            from: $sections[0]->section,
            sections: $sections,
        );
    }
}

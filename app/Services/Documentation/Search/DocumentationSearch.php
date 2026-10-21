<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation\Search;

use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Services\Assistance\AssistantAccessContextFactory;
use Modules\AI\Services\Assistance\Scope\AssistantScopeResolver;
use Modules\AI\Services\DocumentationService;
use Modules\Core\Models\User;
use RuntimeException;

/**
 * Searches the in-app documentation of one module as the given user, with the same access
 * context and retrieval the assistant uses, and returns citation-shaped passages.
 */
final readonly class DocumentationSearch
{
    public function __construct(
        private DocumentationService $documentation,
        private AssistantAccessContextFactory $access_contexts,
        private AssistantScopeResolver $scope_resolver,
    ) {}

    /**
     * Assumes a validated query and module key.
     *
     *
     * @throws RuntimeException when retrieval is unavailable
     *
     * @return list<DocumentationPassage>
     */
    public function search(User $user, string $module, string $query): array
    {
        $access = $this->access_contexts->forDocumentationSearch($user);
        $scope = $this->scope_resolver->resolve(AssistantProfile::InAppAssistance, $module);
        $passages = [];

        foreach ($this->documentation->retrieveForInApp($query, $access, $scope) as $document) {
            $passage = DocumentationPassage::fromDocument($document);

            if ($passage instanceof DocumentationPassage) {
                $passages[] = $passage;
            }
        }

        return $passages;
    }
}

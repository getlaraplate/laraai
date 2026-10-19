<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation;

use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use NeuronAI\RAG\Document;

final readonly class DocumentAudiencePolicy
{
    public function __construct(
        private string $classification_version,
    ) {}

    public function allows(Document $document, DocumentationIndexProfile $profile): bool
    {
        if ($profile === DocumentationIndexProfile::Developer) {
            return true;
        }

        $metadata = $document->getMetadata();

        if (! in_array($metadata['audience'] ?? null, ['user', 'shared'], true)) {
            return false;
        }

        foreach (['module', 'locale', 'canonical_source', 'safe_source_label', 'version'] as $key) {
            if (! $this->isNonEmptyString($metadata[$key] ?? null)) {
                return false;
            }
        }

        if (($metadata['policy_classification'] ?? null) !== 'user_safe'
            || ($metadata['policy_classification_version'] ?? null) !== $this->classification_version) {
            return false;
        }

        if (! $this->isStringList($metadata['required_permissions'] ?? null)
            || ! $this->isStringList($metadata['heading_breadcrumb'] ?? null)) {
            return false;
        }

        return $this->hasValidTenantScope($metadata);
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && mb_trim($value) !== '';
    }

    private function isStringList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! $this->isNonEmptyString($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function hasValidTenantScope(array $metadata): bool
    {
        return match ($metadata['tenant_scope'] ?? null) {
            'global' => ! isset($metadata['tenant_id']),
            'tenant' => $this->isNonEmptyString($metadata['tenant_id'] ?? null),
            default => false,
        };
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag\Retrieval;

use NeuronAI\RAG\Document;

/**
 * When a guide is retrieved in the caller's locale and in the fallback locale, the caller's one wins.
 *
 * Two chunks belong to the same guide when they share `module` and `canonical_source`; the document key hashes the
 * locale, so the two translations of a file have two keys and cannot tell. A chunk without both is never dropped.
 */
final readonly class DocumentationLocalePreference
{
    /**
     * @param  list<Document>  $documents  in search order
     * @return list<Document> the same order, without the fallback-locale chunks of a guide also retrieved in `$locale`
     */
    public static function apply(array $documents, string $locale): array
    {
        if ($locale === DocumentationRetrievalContext::FALLBACK_LOCALE) {
            return $documents;
        }

        $translated = [];

        foreach ($documents as $document) {
            $identity = self::identity($document);

            if ($identity !== null && ($document->getMetadata()['locale'] ?? null) === $locale) {
                $translated[$identity] = true;
            }
        }

        return array_values(array_filter($documents, static function (Document $document) use ($translated): bool {
            $identity = self::identity($document);

            return $identity === null
                || ($document->getMetadata()['locale'] ?? null) !== DocumentationRetrievalContext::FALLBACK_LOCALE
                || ! isset($translated[$identity]);
        }));
    }

    private static function identity(Document $document): ?string
    {
        $metadata = $document->getMetadata();
        $module = $metadata['module'] ?? null;
        $source = $metadata['canonical_source'] ?? null;

        if (! is_string($module) || $module === '' || ! is_string($source) || $source === '') {
            return null;
        }

        return $module . "\0" . $source;
    }
}

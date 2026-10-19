<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation;

use NeuronAI\RAG\Document;

/**
 * The metadata vocabulary of the documentation corpora.
 *
 * Every chunk of the developer corpus carries `audience`, `module`, `locale`, `canonical_source`,
 * `heading_breadcrumb` and `source_type`: what a document declares in its front matter, and a
 * neutral default for what it does not ({@see self::applyDeveloperDefaults()}). The defaults are
 * for the developer corpus only: the user corpus admits a document on its declared metadata alone
 * ({@see DocumentAudiencePolicy}), so a document without front matter never reaches it.
 */
final class DocumentationMetadata
{
    /**
     * The `audience` values a documentation source may declare.
     *
     * @var list<string>
     */
    public const array AUDIENCES = ['user', 'developer', 'shared'];

    public static function isKnownAudience(mixed $audience): bool
    {
        return in_array($audience, self::AUDIENCES, true);
    }

    /**
     * Fills the developer corpus metadata `$document` does not declare (absent, null or blank):
     * audience `shared`, module `app`, locale `und`, its source name as canonical source, an empty
     * heading breadcrumb and source type `file`. Declared values are kept as they are.
     */
    public static function applyDeveloperDefaults(Document $document): void
    {
        $defaults = [
            'audience' => 'shared',
            'module' => 'app',
            'locale' => 'und',
            'canonical_source' => $document->getSourceName(),
            'heading_breadcrumb' => [],
            'source_type' => 'file',
        ];

        foreach ($defaults as $key => $default) {
            $value = $document->getMetadata()[$key] ?? null;

            if ($value === null || (is_string($value) && mb_trim($value) === '')) {
                $document->addMetadata($key, $default);
            }
        }
    }
}

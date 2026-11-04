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

    /**
     * The opaque key of the document a chunk belongs to: `d_` and 32 lowercase hex characters, a hash
     * of the module, the locale and the canonical source, so the path never leaves the server and the
     * same file in two locales is two documents. The format is a contract: the index may map the key as
     * analysed text, and a lowercase `d_<hex>` string stays one token for a `term` filter.
     * Null when the document does not declare all three.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function documentKey(array $metadata): ?string
    {
        $identity = [];

        foreach (['module', 'locale', 'canonical_source'] as $key) {
            $value = $metadata[$key] ?? null;

            if (! is_string($value) || mb_trim($value) === '') {
                return null;
            }

            $identity[] = $value;
        }

        return 'd_' . hash('xxh128', implode("\0", $identity));
    }

    /**
     * Whether `$key` has the shape of a key made by {@see self::documentKey()}.
     */
    public static function isDocumentKey(string $key): bool
    {
        return preg_match('/\Ad_[0-9a-f]{32}\z/', $key) === 1;
    }

    /**
     * Numbers the chunks of one document in order (`section`, from 0) and gives each the key of the
     * document ({@see self::documentKey()}), when it has one. Called once per document with the
     * chunks the configured splitter produced, whatever the splitter.
     *
     * @param  list<Document>  $chunks
     */
    public static function markPositions(array $chunks): void
    {
        foreach ($chunks as $position => $chunk) {
            $chunk->addMetadata('section', $position);

            $key = self::documentKey($chunk->getMetadata());

            if ($key !== null) {
                $chunk->addMetadata('document_key', $key);
            }
        }
    }

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

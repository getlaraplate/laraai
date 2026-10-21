<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation\Search;

use Illuminate\Support\Str;
use NeuronAI\RAG\Document;

/**
 * One documentation hit shaped like the assistant's documentation citation: the source name as label
 * and reference, an excerpt cut at 300 characters, and the optional heading breadcrumb and module.
 */
final readonly class DocumentationPassage
{
    private const int EXCERPT_LENGTH = 300;

    public function __construct(
        public string $label,
        public string $reference,
        public string $excerpt,
        public ?string $headingBreadcrumb = null,
        public ?string $module = null,
    ) {}

    /**
     * Null when the document has no usable source name, which is not a valid passage.
     */
    public static function fromDocument(Document $document): ?self
    {
        $label = mb_trim($document->getSourceName());

        if ($label === '') {
            return null;
        }

        return new self(
            label: $label,
            reference: $label,
            excerpt: Str::limit($document->getContent(), self::EXCERPT_LENGTH),
            headingBreadcrumb: self::breadcrumb($document),
            module: self::metadataString($document, 'module'),
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'label' => $this->label,
            'reference' => $this->reference,
            'excerpt' => $this->excerpt,
            'heading_breadcrumb' => $this->headingBreadcrumb,
            'module' => $this->module,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * The index stores the breadcrumb as a list of headings; a plain string is taken as is.
     */
    private static function breadcrumb(Document $document): ?string
    {
        $value = $document->getMetadata()['heading_breadcrumb'] ?? null;

        if (is_array($value) && array_is_list($value) && $value !== []
            && array_all($value, static fn (mixed $item): bool => is_string($item) && mb_trim($item) !== '')) {
            return implode(' > ', $value);
        }

        return is_string($value) ? $value : null;
    }

    private static function metadataString(Document $document, string $key): ?string
    {
        $value = $document->getMetadata()[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}

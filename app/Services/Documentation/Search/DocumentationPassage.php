<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation\Search;

use Illuminate\Support\Str;
use Modules\AI\Services\Documentation\HeadingBreadcrumb;
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
            excerpt: Str::limit(self::plainText($document->getContent()), self::EXCERPT_LENGTH),
            headingBreadcrumb: HeadingBreadcrumb::fromMetadata($document->getMetadata()['heading_breadcrumb'] ?? null),
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
     * The chunk keeps the Markdown of its source: rendered with raw HTML stripped, then reduced to
     * its text, so the excerpt carries no syntax and no tag.
     */
    private static function plainText(string $markdown): string
    {
        $html = Str::markdown($markdown, ['html_input' => 'strip']);
        $text = html_entity_decode(strip_tags(str_replace(['</p>', '</li>', '</tr>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>', '</pre>'], ' ', $html)), ENT_QUOTES | ENT_HTML5);

        return mb_trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function metadataString(Document $document, string $key): ?string
    {
        $value = $document->getMetadata()[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}

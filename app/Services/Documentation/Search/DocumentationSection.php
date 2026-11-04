<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation\Search;

use Modules\AI\Services\Documentation\HeadingBreadcrumb;
use NeuronAI\RAG\Document;

/**
 * One section of a documentation document as the read route returns it: its position, the heading
 * breadcrumb and the text as stored, without the `> Section:` line the splitter prepends to a chunk.
 */
final readonly class DocumentationSection
{
    public function __construct(
        public int $section,
        public string $content,
        public ?string $headingBreadcrumb = null,
    ) {}

    /**
     * Null when the chunk carries no usable position, which a read cannot place in the document.
     */
    public static function fromDocument(Document $document): ?self
    {
        $section = $document->getMetadata()['section'] ?? null;

        if (! is_int($section) || $section < 0) {
            return null;
        }

        return new self(
            section: $section,
            content: mb_trim((string) preg_replace('/\A> Section: [^\r\n]*(?:\R|\z)/u', '', $document->getContent())),
            headingBreadcrumb: HeadingBreadcrumb::fromMetadata($document->getMetadata()['heading_breadcrumb'] ?? null),
        );
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return array_filter([
            'section' => $this->section,
            'heading_breadcrumb' => $this->headingBreadcrumb,
            'content' => $this->content,
        ], static fn (int|string|null $value): bool => $value !== null && $value !== '');
    }
}

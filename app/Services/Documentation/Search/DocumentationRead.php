<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation\Search;

/**
 * What a read of one documentation document returns: its key, display label and module, the number of
 * sections the document has (`total`), the position of the first section returned (`from`) and the
 * sections in order.
 */
final readonly class DocumentationRead
{
    /**
     * @param  list<DocumentationSection>  $sections
     */
    public function __construct(
        public string $reference,
        public string $label,
        public ?string $module,
        public int $total,
        public int $from,
        public array $sections,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'reference' => $this->reference,
            'label' => $this->label,
            'module' => $this->module,
            'total' => $this->total,
            'from' => $this->from,
            'sections' => array_map(static fn (DocumentationSection $section): array => $section->toArray(), $this->sections),
        ], static fn (mixed $value): bool => $value !== null);
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Widgets;

use Modules\AI\Data\WidgetObject;

/**
 * The types of object a page declares it can render, read from `context.page.objects`.
 *
 * The list is untrusted client input: only its first three entries are read, and an entry that is not one of
 * the known types is dropped, never repaired. It narrows what the assistant may show and grants nothing.
 */
final readonly class RenderableObjectTypes
{
    public const array ALLOWED = [WidgetObject::TYPE_KPI, WidgetObject::TYPE_CHART, WidgetObject::TYPE_GRAPH];

    public const int MAX = 3;

    /**
     * @param  list<string>  $types
     */
    public function __construct(private array $types = []) {}

    public static function fromPageContext(mixed $objects): self
    {
        if (! is_array($objects) || ! array_is_list($objects)) {
            return new self;
        }

        $types = [];

        foreach (array_slice($objects, 0, self::MAX) as $entry) {
            if (is_string($entry) && in_array($entry, self::ALLOWED, true) && ! in_array($entry, $types, true)) {
                $types[] = $entry;
            }
        }

        return new self($types);
    }

    public function has(string $type): bool
    {
        return in_array($type, $this->types, true);
    }

    public function isEmpty(): bool
    {
        return $this->types === [];
    }
}

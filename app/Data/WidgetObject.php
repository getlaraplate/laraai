<?php

declare(strict_types=1);

namespace Modules\AI\Data;

/**
 * An object the assistant puts in its message for the application to render: a figure, a chart or the relation
 * map of a record. It holds the validated request and the title the model chose, never computed figures or
 * records: the client runs the request with the user's session, so what it shows is what the user may read now.
 * The title is model text, so a number in it is not a computed figure.
 */
final readonly class WidgetObject
{
    public const string TYPE_KPI = 'kpi';

    public const string TYPE_CHART = 'chart';

    public const string TYPE_GRAPH = 'graph';

    /**
     * @param  array<string, mixed>  $ref  `request`, and `variant` (a chart) and `format` (a kpi or a chart) when they were given
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $title,
        public array $ref,
    ) {}

    /**
     * @return array{id: string, type: string, title: string, ref: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'ref' => $this->ref,
        ];
    }
}

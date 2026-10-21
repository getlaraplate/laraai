<?php

declare(strict_types=1);

namespace Modules\AI\Data;

/**
 * An object the assistant puts in its message for the application to render: a figure or a chart. It holds the
 * request that computes the data, never the data: the client runs the request with the user's session, so what
 * it shows is what the user may read now, and no number in it was written by the model.
 */
final readonly class WidgetObject
{
    public const string TYPE_KPI = 'kpi';

    public const string TYPE_CHART = 'chart';

    public const string TYPE_GRAPH = 'graph';

    /**
     * @param  array<string, mixed>  $ref  `request`, and `variant` and `format` when they were given
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

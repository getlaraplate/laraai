<?php

declare(strict_types=1);

namespace Modules\AI\Services\Tools;

use Modules\AI\Services\Assistance\Widgets\WidgetObjectCollector;
use Modules\Core\Services\Crud\Aggregate\AggregateLimits;

/**
 * Lets the model show the user a figure, a chart or the relation map of a record in its message. The model writes
 * the request, never the numbers: the application validates the request, runs it once so the model can say what it
 * shows, and puts the request in the message for the client to run and render with the session of the user. The
 * graph is offered only in a run whose policy allows it, so the model is never offered a type it cannot use.
 */
final readonly class ShowWidgetTool
{
    public const string NAME = 'show_widget';

    /**
     * The calls a turn may make beyond the objects a message holds, so that the model can correct a refused request.
     */
    private const int RETRIES = 2;

    public function __construct(private WidgetObjectCollector $collector) {}

    public function definition(): ToolDefinition
    {
        $graphs = $this->collector->showsGraphs();

        return new ToolDefinition(
            name: self::NAME,
            description: 'Show the user a figure (kpi) or a chart that the application computes from the records of an entity you may summarize'
                . ($graphs ? ', or a graph: the relation map of one record. ' : '. ')
                . 'You write the request, never the numbers: the application runs it with the permissions of the user and renders the object in your answer. '
                . 'A kpi shows one figure, with no group_by or with a group_by on a time bucket for its trend; a chart needs a group_by and a variant. '
                . 'Each object of an answer shows a different request. The result gives you the figures so that you can say what the object shows: never state one that is not in it.',
            parameters: [
                ['name' => 'type', 'type' => 'string', 'enum' => $this->collector->types(), 'description' => 'kpi for one figure, chart for figures by group or over time' . ($graphs ? ', graph for the relation map of one record' : '') . '. Only a type the page can show.'],
                ['name' => 'title', 'type' => 'string', 'description' => 'A short title in plain text in the language of the user, without markup or links.', 'maxLength' => WidgetObjectCollector::MAX_TITLE_LENGTH],
                ['name' => 'request', 'type' => 'string', 'description' => $this->requestDescription($graphs), 'maxLength' => WidgetObjectCollector::MAX_REQUEST_LENGTH],
                ['name' => 'variant', 'type' => 'string', 'required' => false, 'enum' => WidgetObjectCollector::VARIANTS, 'description' => 'For a chart only: bar, ranking, line (a group_by on a time bucket) or donut (a limit of at most 8, with other for the rest). Leave it out for a kpi' . ($graphs ? ' or a graph.' : '.')],
                ['name' => 'format', 'type' => 'string', 'required' => false, 'description' => 'Optional format of the figures as JSON text: {"style": "number"|"currency"|"percent", "currency"?: three capital letters, "max_fraction_digits"?: 0 to 4, "compact"?: true|false}.' . ($graphs ? ' Leave it out for a graph.' : ''), 'maxLength' => WidgetObjectCollector::MAX_FORMAT_LENGTH],
            ],
            riskLevel: 'low',
            handler: fn (mixed $type = null, mixed $title = null, mixed $request = null, mixed $variant = null, mixed $format = null): string => $this->collector->show($type, $title, $request, $variant, $format),
            maxRuns: AggregateLimits::fromConfig()->maxObjectsPerMessage + self::RETRIES,
        );
    }

    /**
     * The shape of the request: the aggregate one, and the graph one only in a run that may show a graph.
     */
    private function requestDescription(bool $graphs): string
    {
        $aggregate = 'The aggregate request as JSON text: {"resource": "module/entity", "measures": [{"fn": "count"} or {"fn": "sum|avg|min|max", "field": column}], "group_by"?: {"field", "bucket"?: day|week|month|quarter|year, "label"?}, "period"?: {"field", and one of "last": {"n", "unit"}, "preset", or "from" and "to" as YYYY-MM-DD}, "filters"?: a list of {property, operator, value} or of groups {operator: and|or, filters: [...]}, "sort"?: {"by": measure|key, "dir": asc|desc}, "limit"?, "other"?: true, "compare"?: "previous_period"}. A bucket needs a period on the same field; other needs a limit.';

        if (! $graphs) {
            return $aggregate;
        }

        return $aggregate . ' For a graph, the graph request as JSON text: {"resource": "module/entity", "id": the key of the record, "relations": [1 to ' . WidgetObjectCollector::MAX_GRAPH_RELATIONS . ' distinct relation paths such as "roles" or "roles.permissions"], "depth"?: 1 or ' . WidgetObjectCollector::MAX_GRAPH_DEPTH . ' (1 by default; a path has at most as many steps as the depth), "node_detail"?: "minimal"|"summary"} and nothing else.';
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Services\Tools;

use function ai_config_int;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Middleware\ToolSearchMiddleware;
use NeuronAI\Tools\Tool;

/**
 * How the tools of a run reach the model. Every tool definition is sent with every request, so an installation
 * whose modules offer many entities pays for all of them on every call and gives the model a long list to
 * choose from. Above `ai.features.tools.search.threshold` tools, the entity tools go into a pool that the model
 * searches with Neuron's `tool_search` (a local keyword search, no model call): a tool it finds is offered for
 * the rest of the turn, and the tools that are not about one entity stay offered as they are.
 *
 * A threshold of 0 switches the search off.
 */
final readonly class ToolSearchOffer
{
    /**
     * Adds `$tools` to `$agent`, the ones named in `$searchable` behind the search when there are enough tools.
     *
     * @param  list<Tool>  $tools
     * @param  list<string>  $searchable  the names of the tools that may wait in the pool
     */
    public static function attach(Agent $agent, array $tools, array $searchable): void
    {
        $threshold = max(0, ai_config_int('ai.features.tools.search.threshold', 30));
        $searchable = array_flip($searchable);
        $pool = [];

        foreach ($tools as $tool) {
            if ($threshold > 0 && count($tools) > $threshold && isset($searchable[$tool->getName()])) {
                $pool[] = $tool;

                continue;
            }

            $agent->addTool($tool);
        }

        if ($pool !== []) {
            // Global: a run that resumes at its tool step must find the tools the model found before the pause.
            $agent->addGlobalMiddleware(new ToolSearchMiddleware($pool, max(1, ai_config_int('ai.features.tools.search.top_n', 5))));
        }
    }

    /**
     * The names of the definitions that act on one entity: the ones an installation multiplies.
     *
     * @param  list<ToolDefinition>  $definitions
     * @return list<string>
     */
    public static function searchableNames(array $definitions): array
    {
        return array_values(array_map(
            static fn (ToolDefinition $definition): string => $definition->name,
            array_filter($definitions, static fn (ToolDefinition $definition): bool => $definition->entity !== null),
        ));
    }
}

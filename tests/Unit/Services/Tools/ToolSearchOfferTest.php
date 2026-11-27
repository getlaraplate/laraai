<?php

declare(strict_types=1);

use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Services\Tools\CallbackTool;
use Modules\AI\Services\Tools\ToolDefinition;
use Modules\AI\Services\Tools\ToolSearchOffer;
use NeuronAI\Tools\Tool;

/**
 * @return list<Tool>
 */
function searchOfferTools(int $count, string $prefix = 'crud_list_core_entity'): array
{
    return array_map(
        static fn (int $index): Tool => new CallbackTool("{$prefix}{$index}", "Lists entity {$index}.", static fn (): array => []),
        range(1, $count),
    );
}

function searchOfferToolNames(ChatAgent $agent): array
{
    return array_map(static fn (Tool $tool): string => $tool->getName(), $agent->getTools());
}

it('offers every tool directly up to the threshold', function (): void {
    config()->set('ai.features.tools.search.threshold', 3);
    $agent = ChatAgent::make();
    $tools = searchOfferTools(3);

    ToolSearchOffer::attach($agent, $tools, array_map(static fn (Tool $tool): string => $tool->getName(), $tools));

    expect(searchOfferToolNames($agent))->toHaveCount(3)
        ->and(new ReflectionProperty($agent, 'globalMiddleware')->getValue($agent))->toBe([]);
});

it('puts the entity tools behind the search above the threshold and keeps the others offered', function (): void {
    config()->set('ai.features.tools.search.threshold', 3);
    $agent = ChatAgent::make();
    $entity_tools = searchOfferTools(4);
    $other = new CallbackTool('show_widget', 'Shows a widget.', static fn (): array => []);

    ToolSearchOffer::attach($agent, [...$entity_tools, $other], array_map(static fn (Tool $tool): string => $tool->getName(), $entity_tools));

    expect(searchOfferToolNames($agent))->toBe(['show_widget'])
        ->and(new ReflectionProperty($agent, 'globalMiddleware')->getValue($agent))->toHaveCount(1);
});

it('offers every tool directly when the threshold is 0', function (): void {
    config()->set('ai.features.tools.search.threshold', 0);
    $agent = ChatAgent::make();
    $tools = searchOfferTools(40);

    ToolSearchOffer::attach($agent, $tools, array_map(static fn (Tool $tool): string => $tool->getName(), $tools));

    expect(searchOfferToolNames($agent))->toHaveCount(40);
});

it('names as searchable only the definitions that act on one entity', function (): void {
    $entity = new ToolDefinition('crud_list_core_role', 'Lists roles.', [], 'low', static fn (): array => [], entity: 'core.role', operation: 'list');
    $other = new ToolDefinition('application_content_search', 'Searches.', [], 'low', static fn (): array => []);

    expect(ToolSearchOffer::searchableNames([$entity, $other]))->toBe(['crud_list_core_role']);
});

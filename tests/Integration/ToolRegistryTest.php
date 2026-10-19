<?php

declare(strict_types=1);

use Modules\AI\Services\Tools\CallbackTool;
use Modules\AI\Services\Tools\ToolDefinition;
use Modules\AI\Services\Tools\ToolRegistry;
use NeuronAI\Tools\Tool;

/**
 * @param  list<array{name: string, type: string, description: string, required?: bool}>  $parameters
 */
function registryDefinition(string $name, callable $handler, string $description, array $parameters = [], ?int $maxRuns = null): ToolDefinition
{
    return new ToolDefinition($name, $description, $parameters, 'low', $handler, $maxRuns);
}

beforeEach(function (): void {
    $this->registry = new ToolRegistry;
});

it('builds NeuronAI Tool instances from definitions', function (): void {
    $tools = $this->registry->getNeuronToolsForDefinitions([
        registryDefinition('search', static fn (string $q): string => "Result: {$q}", 'Search something', [['name' => 'query', 'type' => 'string', 'description' => 'Search query']]),
    ]);

    expect($tools)->toHaveCount(1)
        ->and($tools[0])->toBeInstanceOf(Tool::class)
        ->and($tools[0]->getName())->toBe('search')
        ->and($tools[0]->getDescription())->toBe('Search something');
});

it('builds every tool as a callable class with the definition name, description and properties', function (): void {
    $tools = $this->registry->getNeuronToolsForDefinitions([
        registryDefinition('add', fn (int $a, int $b): string => 'sum:' . ($a + $b), 'Add two numbers', [
            ['name' => 'a', 'type' => 'integer', 'description' => 'First number'],
            ['name' => 'b', 'type' => 'integer', 'description' => 'Second number'],
        ]),
    ]);
    $tool = $tools[0];
    $tool->setInputs(['a' => 2, 'b' => 3]);
    $tool->execute();

    expect($tool)->toBeInstanceOf(CallbackTool::class)
        ->and($tool->getName())->toBe('add')
        ->and($tool->getDescription())->toBe('Add two numbers')
        ->and(array_map(static fn ($property): string => $property->getName(), $tool->getProperties()))->toBe(['a', 'b'])
        ->and($tool->getResult())->toBe('sum:5');
});

it('builds tools with correct parameter properties', function (): void {
    $tools = $this->registry->getNeuronToolsForDefinitions([
        registryDefinition('calculate', fn (int $a, int $b): int => $a + $b, 'Add two numbers', [
            ['name' => 'a', 'type' => 'integer', 'description' => 'First number'],
            ['name' => 'b', 'type' => 'integer', 'description' => 'Second number'],
        ]),
    ]);
    $properties = $tools[0]->getProperties();

    expect($properties)->toHaveCount(2)
        ->and($properties[0]->getName())->toBe('a')
        ->and($properties[1]->getName())->toBe('b');
});

it('executes the handler of a built tool', function (): void {
    $tools = $this->registry->getNeuronToolsForDefinitions([
        registryDefinition('greet', fn (string $name): string => "Hello, {$name}!", 'Greet someone', [['name' => 'name', 'type' => 'string', 'description' => 'Name to greet']]),
    ]);
    $tools[0]->setInputs(['name' => 'World']);
    $tools[0]->execute();

    expect($tools[0]->getResult())->toBe('Hello, World!');
});

it('maps property types correctly', function (): void {
    $tools = $this->registry->getNeuronToolsForDefinitions([
        registryDefinition('typed_tool', fn (): string => 'ok', 'Typed tool', [
            ['name' => 'str_param', 'type' => 'string', 'description' => 'A string'],
            ['name' => 'int_param', 'type' => 'integer', 'description' => 'An integer'],
            ['name' => 'bool_param', 'type' => 'boolean', 'description' => 'A boolean'],
            ['name' => 'num_param', 'type' => 'number', 'description' => 'A number'],
            ['name' => 'arr_param', 'type' => 'array', 'description' => 'An array'],
            ['name' => 'obj_param', 'type' => 'object', 'description' => 'An object'],
            ['name' => 'unknown_param', 'type' => 'custom', 'description' => 'Defaults to string'],
        ]),
    ]);
    $json_properties = array_map(fn ($p) => $p->jsonSerialize(), $tools[0]->getProperties());

    expect($json_properties[0]['type'])->toBe('string')
        ->and($json_properties[1]['type'])->toBe('integer')
        ->and($json_properties[2]['type'])->toBe('boolean')
        ->and($json_properties[3]['type'])->toBe('number')
        ->and($json_properties[4]['type'])->toBe('array')
        ->and($json_properties[5]['type'])->toBe('object')
        ->and($json_properties[6]['type'])->toBe('string');
});

it('keeps only the allowed tool names when a list is given', function (): void {
    $definitions = [
        registryDefinition('kept', fn (): string => 'ok', 'Kept'),
        registryDefinition('dropped', fn (): string => 'ok', 'Dropped'),
    ];

    $tools = $this->registry->getNeuronToolsForDefinitions($definitions, ['kept']);

    expect($tools)->toHaveCount(1)
        ->and($tools[0]->getName())->toBe('kept');
});

it('limits how often the model may call a tool in a turn when the tool says so, and leaves Neuron\'s limit otherwise', function (): void {
    $tools = collect($this->registry->getNeuronToolsForDefinitions([
        registryDefinition('limited', fn (): string => 'ok', 'A tool with a limit', maxRuns: 2),
        registryDefinition('unlimited', fn (): string => 'ok', 'A tool without one'),
    ]))->keyBy(static fn (Tool $tool): string => $tool->getName());

    expect($tools['limited']->getMaxRuns())->toBe(2)
        ->and($tools['unlimited']->getMaxRuns())->toBeNull();
});

it('limits the graph and CRUD tools to a few calls per turn', function (): void {
    expect(Modules\AI\Services\Tools\GraphToolProvider::MAX_RUNS)->toBe(3)
        ->and(Modules\AI\Services\Tools\CrudToolProvider::MAX_RUNS)->toBe(3);
});

it('admits a tool class by wildcard and lets a denial override it', function (): void {
    $definitions = [
        registryDefinition('crud_update_cms_content', fn (): string => 'ok', 'Update'),
        registryDefinition('crud_approve_cms_content', fn (): string => 'ok', 'Approve'),
        registryDefinition('graph_search', fn (): string => 'ok', 'Graph'),
    ];

    $tools = $this->registry->getNeuronToolsForDefinitions($definitions, ['crud_*'], ['crud_approve_*']);

    expect(array_map(static fn (Tool $tool): string => $tool->getName(), $tools))->toBe(['crud_update_cms_content']);
});

<?php

declare(strict_types=1);

use Modules\AI\Services\Tools\CallbackTool;
use Modules\AI\Services\Tools\SchemaToolProperty;
use NeuronAI\Exceptions\InvalidToolInput;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;

function schemaToolProperty(PropertyType $type): SchemaToolProperty
{
    return new SchemaToolProperty('value', $type, 'A value', true);
}

it('is never nullable, as no schema parameter is', function (): void {
    expect(schemaToolProperty(PropertyType::STRING)->isNullable())->toBeFalse();
});

it('converts what the model sends in the wrong type to the declared one', function (PropertyType $type, mixed $input, mixed $expected): void {
    expect(schemaToolProperty($type)->cast($input))->toBe($expected);
})->with([
    'integer from a numeric string' => [PropertyType::INTEGER, '42', 42],
    'number from a decimal string' => [PropertyType::NUMBER, '1.5', 1.5],
    'string from an integer' => [PropertyType::STRING, 42, '42'],
    'boolean from "true"' => [PropertyType::BOOLEAN, 'true', true],
    'boolean from 1' => [PropertyType::BOOLEAN, 1, true],
    'boolean from "false"' => [PropertyType::BOOLEAN, 'false', false],
    'boolean from 0' => [PropertyType::BOOLEAN, 0, false],
]);

it('returns a value it cannot convert unchanged, for the tool itself to refuse', function (): void {
    expect(schemaToolProperty(PropertyType::INTEGER)->cast('abc'))->toBe('abc');
});

it('lets arrays and objects through as they are', function (PropertyType $type, array $input): void {
    expect(schemaToolProperty($type)->cast($input))->toBe($input);
})->with([
    'array' => [PropertyType::ARRAY, ['a', 'b']],
    'object' => [PropertyType::OBJECT, ['key' => 'value']],
]);

it('converts a value before it checks it against the enum', function (): void {
    $property = new SchemaToolProperty('priority', PropertyType::INTEGER, 'Ticket priority', true, [1, 2, 3]);

    expect($property->cast('2'))->toBe(2);
});

it('refuses a value outside the enum, telling the model what is allowed', function (): void {
    $property = new SchemaToolProperty('locale', PropertyType::STRING, 'A locale', true, ['en', 'it']);

    $property->cast('de');
})->throws(InvalidToolInput::class, 'must be one of "en", "it"; "de" given');

it('answers the model with an error and does not run the tool for a value outside the enum', function (): void {
    $ran = false;
    $tool = new CallbackTool('pick', 'Pick a locale', static function (string $locale) use (&$ran): string {
        $ran = true;

        return $locale;
    });
    $tool->addProperty(new SchemaToolProperty('locale', PropertyType::STRING, 'A locale', true, ['en', 'it']));

    $tool->setInputs(['locale' => 'de']);
    $tool->execute();

    expect($ran)->toBeFalse()
        ->and($tool->getResult())->toBeInstanceOf(ToolOutput::class)
        ->and($tool->getResult()->isError())->toBeTrue()
        ->and($tool->getResult()->getText())->toBe('Parameter "locale" must be one of "en", "it"; "de" given.');
});

it('runs a tool whose id is declared a string when the model sends a number', function (): void {
    $tool = new CallbackTool('lookup', 'Look a record up', static fn (string $id): string => "record:{$id}");
    $tool->addProperty(new SchemaToolProperty('id', PropertyType::STRING, 'The record id', true));

    $tool->setInputs(['id' => 42]);
    $tool->execute();

    expect((string) $tool->getResult())->toBe('record:42');
});

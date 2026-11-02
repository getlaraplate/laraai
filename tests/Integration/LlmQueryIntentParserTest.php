<?php

declare(strict_types=1);

use Modules\AI\Services\LlmQueryIntentParser;
use Modules\AI\Services\LlmSearchService;
use Modules\Core\Search\Contracts\IQueryIntentParser;

it('implements IQueryIntentParser contract', function (): void {
    $llm = Mockery::mock(LlmSearchService::class);
    expect(new LlmQueryIntentParser($llm))->toBeInstanceOf(IQueryIntentParser::class);
});

it('maps LLM intent to contract format', function (): void {
    $llm = Mockery::mock(LlmSearchService::class);
    $llm->shouldReceive('extractSearchIntent')->with('climate change')->andReturn([
        'keywords' => ['climate', 'change', 'global warming'],
        'filters' => ['date_range' => null],
        'query_expansion' => ['must' => 'climate change global warming effects'],
    ]);

    $parser = new LlmQueryIntentParser($llm);
    $result = $parser->parse('climate change');

    expect($result['keywords'])->toBe(['climate', 'change', 'global warming']);
    expect($result['date_range'])->toBeNull();
    expect($result['query']['expanded'])->toBe('climate change global warming effects');
});

it('falls back to original query when expansion is missing', function (): void {
    $llm = Mockery::mock(LlmSearchService::class);
    $llm->shouldReceive('extractSearchIntent')->andReturn([
        'keywords' => [],
        'filters' => [],
        'query_expansion' => [],
    ]);

    $parser = new LlmQueryIntentParser($llm);
    $result = $parser->parse('my query');

    expect($result['query']['expanded'])->toBe('my query');
});

it('keeps the interval the rules read and asks the model only when they read none', function (): void {
    $llm = Mockery::mock(LlmSearchService::class);
    $llm->shouldReceive('extractSearchIntent')->andReturn([
        'keywords' => ['reports'],
        'filters' => ['date_range' => ['from' => '2019-01-01', 'to' => '2019-12-31']],
        'query_expansion' => [],
    ]);

    $parser = new LlmQueryIntentParser($llm);

    expect($parser->parse('reports of 2024')['date_range'])->toBe(['from' => '2024-01-01', 'to' => '2024-12-31'])
        ->and($parser->parse('reports before the pandemic')['date_range'])->toBe(['from' => '2019-01-01', 'to' => '2019-12-31']);
});

it('says whether the query asks for recent results', function (): void {
    $llm = Mockery::mock(LlmSearchService::class);
    $llm->shouldReceive('extractSearchIntent')->andReturn(['keywords' => [], 'filters' => [], 'query_expansion' => []]);

    $parser = new LlmQueryIntentParser($llm);

    expect($parser->parse('latest reports')['recent'])->toBeTrue()
        ->and($parser->parse('reports')['recent'])->toBeFalse();
});

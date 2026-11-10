<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Modules\AI\Console\LaraplateHelpCommand;
use Modules\AI\Services\DocumentationService;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    Config::set('ai.features.faq.enabled', true);
});

it('returns failure when FAQ is disabled', function (): void {
    Config::set('ai.features.faq.enabled', false);

    $mock = Mockery::mock(DocumentationService::class);
    app()->instance(DocumentationService::class, $mock);

    $command = new LaraplateHelpCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['--question' => 'How do I use CRUD APIs?']);

    expect($tester->getStatusCode())->toBe(LaraplateHelpCommand::FAILURE);
});

it('returns failure when rag index is not available', function (): void {
    $mock = Mockery::mock(DocumentationService::class);
    $mock->shouldReceive('isAvailable')
        ->once()
        ->andReturn(false);
    app()->instance(DocumentationService::class, $mock);

    $command = new LaraplateHelpCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['--question' => 'How do I use CRUD APIs?']);

    expect($tester->getStatusCode())->toBe(LaraplateHelpCommand::FAILURE)
        ->and($tester->getDisplay())->toContain('ai:index-rag-docs');
});

it('answers one-shot question and prints sources', function (): void {
    $mock = Mockery::mock(DocumentationService::class);
    $mock->shouldReceive('isAvailable')
        ->once()
        ->andReturn(true);
    $mock->shouldReceive('answerDeveloperQuestion')
        ->once()
        ->with('How do I use CRUD APIs?', Modules\AI\Enums\AssistantProfile::DeveloperHelp)
        ->andReturn([
            'answer' => 'Use /crud/select and /crud/update endpoints.',
            'citations' => [
                ['source' => 'faq-module-Core:/docs/rag/MODULE.md', 'score' => 0.91],
            ],
        ]);
    app()->instance(DocumentationService::class, $mock);

    $command = new LaraplateHelpCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['--question' => 'How do I use CRUD APIs?']);

    expect($tester->getStatusCode())->toBe(LaraplateHelpCommand::SUCCESS)
        ->and($tester->getDisplay())->toContain('Assistant:')
        ->and($tester->getDisplay())->toContain('Sources:')
        ->and($tester->getDisplay())->toContain('faq-module-Core:/docs/rag/MODULE.md');
});

it('prints one source line per page, with how many passages it gave and the best score', function (): void {
    $mock = Mockery::mock(DocumentationService::class);
    $mock->shouldReceive('isAvailable')->once()->andReturn(true);
    $mock->shouldReceive('answerDeveloperQuestion')
        ->once()
        ->andReturn([
            'answer' => 'The passages found.',
            'citations' => [
                ['source' => 'faq-module-Core/VERSIONING_DEVELOPER.md', 'excerpt' => 'a', 'score' => 0.937],
                ['source' => 'faq-module-Core/MODULE.md', 'excerpt' => 'b', 'score' => 0.911],
                ['source' => 'faq-module-Core/VERSIONING_DEVELOPER.md', 'excerpt' => 'c', 'score' => 0.949],
                ['source' => 'faq-module-Core/VERSIONING_DEVELOPER.md', 'excerpt' => 'd', 'score' => 0.939],
            ],
        ]);
    app()->instance(DocumentationService::class, $mock);

    $command = new LaraplateHelpCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['--question' => 'How do I get the versions of a model?']);

    $display = $tester->getDisplay();

    expect($display)->toContain('- faq-module-Core/VERSIONING_DEVELOPER.md (3 passages, best score: 0.949)')
        ->and($display)->toContain('- faq-module-Core/MODULE.md (score: 0.911)')
        ->and(mb_substr_count($display, '- faq-module-Core/VERSIONING_DEVELOPER.md'))->toBe(1)
        ->and(mb_strpos($display, 'VERSIONING_DEVELOPER.md'))->toBeLessThan(mb_strpos($display, 'MODULE.md'));
});

it('counts the passages of a source that has no score', function (): void {
    $mock = Mockery::mock(DocumentationService::class);
    $mock->shouldReceive('isAvailable')->once()->andReturn(true);
    $mock->shouldReceive('answerDeveloperQuestion')
        ->once()
        ->andReturn([
            'answer' => 'The passages found.',
            'citations' => [
                ['source' => 'faq-app-rag/a.md', 'excerpt' => 'a', 'score' => null],
                ['source' => 'faq-app-rag/a.md', 'excerpt' => 'b', 'score' => null],
            ],
        ]);
    app()->instance(DocumentationService::class, $mock);

    $command = new LaraplateHelpCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['--question' => 'anything']);

    expect($tester->getDisplay())->toContain('- faq-app-rag/a.md (2 passages)');
});

it('returns failure when answering fails', function (): void {
    $mock = Mockery::mock(DocumentationService::class);
    $mock->shouldReceive('isAvailable')
        ->once()
        ->andReturn(true);
    $mock->shouldReceive('answerDeveloperQuestion')
        ->once()
        ->with('How do I use CRUD APIs?', Modules\AI\Enums\AssistantProfile::DeveloperHelp)
        ->andThrow(new Exception('Provider timeout'));
    app()->instance(DocumentationService::class, $mock);

    $command = new LaraplateHelpCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['--question' => 'How do I use CRUD APIs?']);

    expect($tester->getStatusCode())->toBe(LaraplateHelpCommand::FAILURE)
        ->and($tester->getDisplay())->toContain('Provider timeout');
});

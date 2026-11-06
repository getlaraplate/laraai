<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Console\IndexDocumentationCommand;
use Modules\AI\Services\DocumentationService;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    Config::set('ai.features.faq.enabled', true);
});

it('returns failure when FAQ disabled', function (): void {
    Config::set('ai.features.faq.enabled', false);

    $mock = Mockery::mock(DocumentationService::class);
    app()->instance(DocumentationService::class, $mock);

    $command = new IndexDocumentationCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(IndexDocumentationCommand::FAILURE);
});

it('returns failure when path does not exist', function (): void {
    $mock = Mockery::mock(DocumentationService::class);
    app()->instance(DocumentationService::class, $mock);

    $command = new IndexDocumentationCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['--path' => '/nonexistent/path/12345']);

    expect($tester->getStatusCode())->toBe(IndexDocumentationCommand::FAILURE);
});

it('calls documentationService indexDocuments and shows count', function (): void {
    $mock = Mockery::mock(DocumentationService::class);
    $mock->shouldReceive('indexDocuments')
        ->once()
        ->with(null, false, DocumentationIndexProfile::Developer, Mockery::type(Closure::class))
        ->andReturn(42);
    app()->instance(DocumentationService::class, $mock);

    $command = new IndexDocumentationCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(IndexDocumentationCommand::SUCCESS)
        ->and($tester->getDisplay())->toContain('42');
});

it('passes full flag to documentationService', function (): void {
    $mock = Mockery::mock(DocumentationService::class);
    $mock->shouldReceive('indexDocuments')
        ->once()
        ->with(null, true, DocumentationIndexProfile::Developer, Mockery::type(Closure::class))
        ->andReturn(3);
    app()->instance(DocumentationService::class, $mock);

    $command = new IndexDocumentationCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['--full' => true]);

    expect($tester->getStatusCode())->toBe(IndexDocumentationCommand::SUCCESS)
        ->and($tester->getDisplay())->toContain('3');
});

it('warns that full indexing resets the in-process memory store', function (): void {
    Config::set('ai.features.faq.vector_store', 'memory');

    $mock = Mockery::mock(DocumentationService::class);
    $mock->shouldReceive('indexDocuments')
        ->once()
        ->with(null, true, DocumentationIndexProfile::Developer, Mockery::type(Closure::class))
        ->andReturn(3);
    app()->instance(DocumentationService::class, $mock);

    $command = new IndexDocumentationCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['--full' => true]);

    expect($tester->getStatusCode())->toBe(IndexDocumentationCommand::SUCCESS)
        ->and($tester->getDisplay())->toContain('Vector store driver is "memory"')
        ->and($tester->getDisplay())->toContain('3');
});

it('returns failure on exception', function (): void {
    $mock = Mockery::mock(DocumentationService::class);
    $mock->shouldReceive('indexDocuments')
        ->once()
        ->with(null, false, DocumentationIndexProfile::Developer, Mockery::type(Closure::class))
        ->andThrow(new Exception('Indexing error'));
    app()->instance(DocumentationService::class, $mock);

    $command = new IndexDocumentationCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(IndexDocumentationCommand::FAILURE)
        ->and($tester->getDisplay())->toContain('Indexing error');
});

it('indexes the explicitly selected user corpus', function (): void {
    $mock = Mockery::mock(DocumentationService::class);
    $mock->shouldReceive('indexDocuments')
        ->once()
        ->with(null, false, DocumentationIndexProfile::User, Mockery::type(Closure::class))
        ->andReturn(2);
    app()->instance(DocumentationService::class, $mock);

    $command = new IndexDocumentationCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['--profile' => 'user']);

    expect($tester->getStatusCode())->toBe(IndexDocumentationCommand::SUCCESS)
        ->and($tester->getDisplay())->toContain('2');
});

it('rejects an invalid documentation profile', function (): void {
    $mock = Mockery::mock(DocumentationService::class);
    $mock->shouldNotReceive('indexDocuments');
    app()->instance(DocumentationService::class, $mock);

    $command = new IndexDocumentationCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['--profile' => 'unsafe']);

    expect($tester->getStatusCode())->toBe(IndexDocumentationCommand::FAILURE);
});

it('stops with a readable error naming the file and the value of an unknown audience', function (): void {
    $tmp_dir = sys_get_temp_dir() . '/ai-command-bad-audience-' . uniqid();
    mkdir($tmp_dir, 0755, true);
    file_put_contents($tmp_dir . '/wrong.md', "---\naudience: admin\n---\n# Wrong\n\nBody.");
    Config::set('ai.features.faq.vector_store', 'memory');
    app()->instance(DocumentationService::class, new DocumentationService);

    $command = new IndexDocumentationCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);

    try {
        $tester->execute(['--path' => $tmp_dir, '--profile' => 'all', '--full' => true]);

        expect($tester->getStatusCode())->toBe(IndexDocumentationCommand::FAILURE)
            ->and($tester->getDisplay())->toContain('Indexing failed')
            ->and($tester->getDisplay())->toContain('wrong.md')
            ->and($tester->getDisplay())->toContain('"admin"')
            ->and($tester->getDisplay())->not->toContain('Indexed');
    } finally {
        File::deleteDirectory($tmp_dir);
    }
});

it('prints one plain line per batch when the console is not interactive', function (): void {
    $mock = Mockery::mock(DocumentationService::class);
    $mock->shouldReceive('indexDocuments')
        ->once()
        ->andReturnUsing(function (?string $path, bool $full, DocumentationIndexProfile $profile, Closure $onProgress): int {
            $onProgress(0, 150, ['docs/a.md', 'docs/b.md', 'docs/c.md', 'docs/d.md']);
            $onProgress(100, 150, ['docs/e.md']);
            $onProgress(150, 150, []);

            return 150;
        });
    app()->instance(DocumentationService::class, $mock);

    $command = new IndexDocumentationCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute([], ['interactive' => false]);

    expect($tester->getStatusCode())->toBe(IndexDocumentationCommand::SUCCESS)
        ->and($tester->getDisplay())->toContain('Indexing the developer documentation: 0/150 chunks, docs/a.md, docs/b.md (+2 more)')
        ->and($tester->getDisplay())->toContain('Indexing the developer documentation: 100/150 chunks, docs/e.md')
        ->and(mb_substr_count($tester->getDisplay(), 'chunks,'))->toBe(2);
});

it('draws a progress bar with the file being indexed when the console is interactive', function (): void {
    $mock = Mockery::mock(DocumentationService::class);
    $mock->shouldReceive('indexDocuments')
        ->once()
        ->andReturnUsing(function (?string $path, bool $full, DocumentationIndexProfile $profile, Closure $onProgress): int {
            $onProgress(0, 150, ['docs/a.md']);
            $onProgress(100, 150, ['docs/b.md']);
            $onProgress(150, 150, []);

            return 150;
        });
    app()->instance(DocumentationService::class, $mock);

    $command = new IndexDocumentationCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute([], ['interactive' => true]);

    $drawn = $tester->getDisplay();

    expect($tester->getStatusCode())->toBe(IndexDocumentationCommand::SUCCESS)
        ->and($drawn)->toContain('Indexing the developer documentation')
        ->and($drawn)->toContain('docs/a.md')
        ->and($drawn)->toContain('docs/b.md')
        ->and($drawn)->toContain('150 / 150');
});

it('draws the bar in the error state and reports the failure when the indexing stops midway', function (): void {
    $mock = Mockery::mock(DocumentationService::class);
    $mock->shouldReceive('indexDocuments')
        ->once()
        ->andReturnUsing(function (?string $path, bool $full, DocumentationIndexProfile $profile, Closure $onProgress): never {
            $onProgress(0, 150, ['docs/a.md']);

            throw new Exception('Elasticsearch said no');
        });
    app()->instance(DocumentationService::class, $mock);

    $command = new IndexDocumentationCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute([], ['interactive' => true]);

    expect($tester->getStatusCode())->toBe(IndexDocumentationCommand::FAILURE)
        ->and($tester->getDisplay())->toContain('Indexing failed: Elasticsearch said no')
        ->and($tester->getDisplay())->toContain('0 / 150');
});

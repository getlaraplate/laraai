<?php

declare(strict_types=1);

use Modules\AI\Ai\Agents\DocumentationAgent;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Ai\Rag\FaqVectorStoreConfig;
use Modules\AI\Services\DocumentationService;
use NeuronAI\RAG\Document;
use NeuronAI\Testing\FakeEmbeddingsProvider;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir() . '/faq-store-' . uniqid();
    mkdir($this->directory);
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->directory . '/*') ?: []);
    rmdir($this->directory);
});

it('names the file of a profile from the configured path, with -user before the extension for the user index', function (string $configured, string $developer, string $user): void {
    config()->set('ai.features.faq.vector_store_path', $configured);

    expect(FaqVectorStoreConfig::file(DocumentationIndexProfile::Developer)->path)->toBe($developer)
        ->and(FaqVectorStoreConfig::file(DocumentationIndexProfile::User)->path)->toBe($user);
})->with([
    'a store file' => ['/tmp/x/docs.store', '/tmp/x/docs.store', '/tmp/x/docs-user.store'],
    'a json file' => ['/tmp/x/docs.json', '/tmp/x/docs.json', '/tmp/x/docs-user.json'],
    'no extension' => ['/tmp/x/docs', '/tmp/x/docs', '/tmp/x/docs-user'],
    'dots in the name' => ['/tmp/x/docs.v2.store', '/tmp/x/docs.v2.store', '/tmp/x/docs.v2-user.store'],
]);

it('falls back to the default file, and splits a path the way FileVectorStore joins it', function (): void {
    config()->set('ai.features.faq.vector_store_path', null);
    $file = FaqVectorStoreConfig::file(DocumentationIndexProfile::Developer);

    expect($file->path)->toBe(storage_path('app/ai/faq-vectorstore.store'))
        ->and($file->directory() . DIRECTORY_SEPARATOR . $file->name() . $file->extension())->toBe($file->path);
});

it('reads the driver from one place, with the default of the module config', function (): void {
    config()->set('ai.features.faq.vector_store', null);

    expect(FaqVectorStoreConfig::driver())->toBe(FaqVectorStoreConfig::DEFAULT_DRIVER);

    config()->set('ai.features.faq.vector_store', 'filesystem');

    expect(FaqVectorStoreConfig::driver())->toBe('filesystem');
});

it('writes the vectors to the file that the service checks, whatever its extension', function (string $extension): void {
    $path = $this->directory . '/documentation' . $extension;
    config()->set('ai.features.faq.enabled', true);
    config()->set('ai.features.faq.vector_store', 'filesystem');
    config()->set('ai.features.faq.vector_store_path', $path);

    $agent = DocumentationAgent::make(vectorStoreDriver: 'filesystem', vectorStorePath: $path);
    $agent->setEmbeddingsProvider(new FakeEmbeddingsProvider);
    $agent->addDocuments([new Document('Paris is the capital of France.')]);

    expect(is_file($path))->toBeTrue()
        ->and(glob($this->directory . '/*'))->toBe([$path, $path . '.lock'])
        ->and((new DocumentationService)->isAvailable())->toBeTrue();
})->with(['store' => '.store', 'json' => '.json', 'none' => '']);

it('removes the file that the store wrote when the documentation is rebuilt in full', function (): void {
    $path = $this->directory . '/documentation.json';
    config()->set('ai.features.faq.enabled', true);
    config()->set('ai.features.faq.vector_store', 'filesystem');
    config()->set('ai.features.faq.vector_store_path', $path);

    $agent = DocumentationAgent::make(vectorStoreDriver: 'filesystem', vectorStorePath: $path);
    $agent->setEmbeddingsProvider(new FakeEmbeddingsProvider);
    $agent->addDocuments([new Document('Paris is the capital of France.')]);

    $docs = $this->directory . '/guides';
    mkdir($docs);
    file_put_contents($docs . '/empty.md', "---\nmodule: core\naudience: developer\n---\n");

    (new DocumentationService)->indexDocuments($docs, true);

    expect(is_file($path))->toBeFalse();
    unlink($docs . '/empty.md');
    rmdir($docs);
});

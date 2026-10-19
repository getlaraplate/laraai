<?php

declare(strict_types=1);

use Modules\AI\Services\Documentation\FileDocumentReader;

it('carries the cross_cutting_user frontmatter marker into document metadata', function (): void {
    $dir = sys_get_temp_dir() . '/laraplate-doc-marker-' . bin2hex(random_bytes(5));
    mkdir($dir, 0700, true);
    $path = $dir . '/guide.md';
    file_put_contents($path, "---\nmodule: core\naudience: user\ncross_cutting_user: true\n---\n# Approve a modification\nSteps.\n");

    try {
        $documents = (new FileDocumentReader($path))->getDocuments();

        expect($documents)->toHaveCount(1)
            ->and($documents[0]->getMetadata()['cross_cutting_user'] ?? null)->toBeTrue()
            ->and($documents[0]->getMetadata()['module'] ?? null)->toBe('core');
    } finally {
        @unlink($path);
        @rmdir($dir);
    }
});

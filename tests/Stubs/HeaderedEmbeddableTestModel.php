<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs;

use Override;

/**
 * Embeddable test model that declares an embedding context header, read from its `context_header`
 * column so a test can change or remove it between two embedding runs.
 */
final class HeaderedEmbeddableTestModel extends EmbeddableTestModel
{
    #[Override]
    public function embedHeader(?string $locale = null): ?string
    {
        $header = $this->getAttribute('context_header');

        return is_string($header) ? $header : null;
    }
}

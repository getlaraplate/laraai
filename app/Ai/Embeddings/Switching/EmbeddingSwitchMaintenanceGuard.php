<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings\Switching;

use Modules\Core\Search\Contracts\ISearchMaintenanceGuard;
use Override;

/**
 * Keeps `scout:rebuild` (and anything else that asks Core's {@see ISearchMaintenanceGuard}) from
 * dropping, recreating or refilling a search index while an embedding model switch owns them:
 * the switch rebuilds the same indexes in the background, and the two would overwrite each other.
 * "Switching" is {@see EmbeddingSwitchStore::isSwitching()}: running, or failed after its preflight.
 */
final readonly class EmbeddingSwitchMaintenanceGuard implements ISearchMaintenanceGuard
{
    public function __construct(private EmbeddingSwitchStore $switch) {}

    #[Override]
    public function blockedReason(): ?string
    {
        if (! $this->switch->isSwitching()) {
            return null;
        }

        return 'an embedding model switch is in progress and is rebuilding the search indexes. '
            . 'Follow it with ai:embeddings:status; continue it with ai:embeddings:switch --resume or give it up with ai:embeddings:switch --abandon, then run this command again.';
    }
}

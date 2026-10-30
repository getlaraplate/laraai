<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings;

use function ai_config_bool;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Core\Search\Contracts\IVectorProfileProvider;
use Modules\Core\Search\DTOs\VectorProfile;

/**
 * Answers Core's question "which vector profile does search run against": the profile of the model
 * that serves search, while the embeddings switch is on. It reads the runtime config only, so it
 * costs no query and no cache read per call, and it warns once for a key it does not know.
 */
final class ActiveEmbeddingProfileProvider implements IVectorProfileProvider
{
    private ?string $warnedKey = null;

    public function __construct(private readonly EmbeddingModelRegistry $registry) {}

    public function current(): ?VectorProfile
    {
        if (! ai_config_bool('ai.features.embeddings.enabled', false)) {
            return null;
        }

        $key = $this->registry->activeKey();

        try {
            $profile = $this->registry->get($key);
        } catch (InvalidArgumentException $exception) {
            if ($this->warnedKey !== $key) {
                $this->warnedKey = $key;
                Log::warning('The active embedding model profile is not usable, so vector search is off', [
                    'key' => $key,
                    'reason' => $exception->getMessage(),
                ]);
            }

            return null;
        }

        return $profile->vectorProfile();
    }
}

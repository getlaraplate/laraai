<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Modules\AI\Ai\Embeddings\EmbeddingDimensionMismatch;
use Modules\AI\Ai\Embeddings\EmbeddingDimensionProbe;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Override;
use Throwable;

/**
 * Embeds a fixed text with a configured profile and reports the vector length the model really
 * produces, failing when it differs from the dimensions the profile declares.
 */
final class EmbeddingsProbeCommand extends Command
{
    #[Override]
    protected $signature = 'ai:embeddings:probe
                            {profile : Embedding model profile key, e.g. openai_compatible:intfloat/multilingual-e5-small}';

    #[Override]
    protected $description = 'Measure the vector length an embedding profile really produces and compare it with its declared dimensions <fg=magenta>(✨ Modules\AI)</fg=magenta>';

    public function handle(EmbeddingModelRegistry $registry, EmbeddingDimensionProbe $probe): int
    {
        try {
            $profile = $registry->get((string) $this->argument('profile'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $measured = $probe->verify($profile);
        } catch (EmbeddingDimensionMismatch $mismatch) {
            $this->error($mismatch->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error("The embedding probe failed for profile \"{$profile->key}\": {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("profile: {$profile->key}");
        $this->info("model: {$profile->serviceModel}");
        $this->info("measured dimensions: {$measured}");

        return self::SUCCESS;
    }
}

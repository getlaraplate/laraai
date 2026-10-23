<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchStore;
use Modules\Core\Models\ModelEmbedding;
use Modules\Core\Search\Contracts\IProfileVectorIndex;
use Override;

/**
 * Deletes the stored embeddings of one model key: a manual cleanup for rows nobody uses (a model
 * removed from config, an abandoned switch), since the activation of a switch already deletes the
 * previous model's rows. It refuses the active model and any key while a switch is running.
 */
final class EmbeddingsPruneCommand extends Command
{
    #[Override]
    protected $signature = 'ai:embeddings:prune
                            {--model-key= : The model key whose embedding rows are deleted, e.g. openai_compatible:all-MiniLM-L6-v2}';

    #[Override]
    protected $description = 'Delete the stored embeddings of a model key nobody uses; refuses the active model and a running switch <fg=magenta>(✨ Modules\AI)</fg=magenta>';

    public function handle(EmbeddingModelRegistry $registry, EmbeddingSwitchStore $store): int
    {
        $option = $this->option('model-key');
        $modelKey = is_string($option) ? mb_trim($option) : '';

        if ($modelKey === '') {
            $this->error('Pass the model key to prune with --model-key.');

            return self::FAILURE;
        }

        if ($modelKey === $registry->activeKey()) {
            $this->error("\"{$modelKey}\" is the active embedding model: its rows serve vector search. Nothing was deleted.");

            return self::FAILURE;
        }

        $state = $store->get();

        if ($state->status === 'running') {
            $this->error("An embedding model switch is running (phase {$state->phase}, target {$state->target}): prune once it ends. Nothing was deleted.");

            return self::FAILURE;
        }

        $deleted = ModelEmbedding::query()->producedBy($modelKey)->delete();

        $indexes = app(IProfileVectorIndex::class);
        $connection = new ModelEmbedding()->getConnection();

        if ($indexes->supports($connection)) {
            $indexes->drop($connection, $modelKey);
        }

        $this->info("Deleted {$deleted} embedding row(s) of \"{$modelKey}\".");

        return self::SUCCESS;
    }
}

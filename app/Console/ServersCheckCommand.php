<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Modules\AI\Ai\SelfHosted\ServerCheck;
use Override;

/**
 * Probes every configured AI server and says whether it is misconfigured: an embeddings server that does not run
 * its profile's model, answers vectors of another length or scale, or accepts smaller batches than the client
 * sends; a reranker that does not answer one score per pair. Read-only; exits 1 when any probe fails.
 */
final class ServersCheckCommand extends Command
{
    #[Override]
    protected $signature = 'ai:servers:check';

    #[Override]
    protected $description = 'Probe the configured embeddings, rerank and transcription servers and report what is misconfigured <fg=magenta>(✨ Modules\AI)</fg=magenta>';

    public function handle(ServerCheck $check): int
    {
        $rows = $check->run();

        if ($rows === []) {
            $this->info('No AI server is configured: set AI_EMBEDDINGS_URL, AI_RERANK_URL or AI_TRANSCRIPTION_URL.');

            return self::SUCCESS;
        }

        $this->table(
            ['Server', 'Capability', 'URL', 'Result', 'Detail'],
            array_map(static fn (array $row): array => [$row['server'], $row['capability'], $row['url'], $row['ok'] ? 'OK' : 'FAILED', $row['detail']], $rows),
        );

        return in_array(false, array_column($rows, 'ok'), true) ? self::FAILURE : self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Providers\AiModelChoice;
use Modules\AI\Ai\Providers\AiRole;
use Modules\AI\Ai\Providers\RoleEgress;
use Modules\AI\Ai\SelfHosted\ServerCheck;
use Override;
use Throwable;

/**
 * Probes every configured AI server and says whether it is misconfigured: an embeddings server that does not run
 * its profile's model, answers vectors of another length or scale, or accepts smaller batches than the client
 * sends; a reranker that does not answer one score per pair. Read-only; exits 1 when any probe fails.
 *
 * It first lists, per {@see AiRole}, the model in use and whether the text sent to it leaves the installation
 * ({@see RoleEgress}), from configuration only: no URL and no key is printed.
 */
final class ServersCheckCommand extends Command
{
    #[Override]
    protected $signature = 'ai:servers:check';

    #[Override]
    protected $description = 'Say per role whether text leaves the installation, then probe the configured embeddings, rerank and transcription servers and report what is misconfigured <fg=magenta>(✨ Modules\AI)</fg=magenta>';

    public function handle(ServerCheck $check, RoleEgress $egress): int
    {
        $this->table(
            ['Role', 'Model', 'Text'],
            array_map(fn (string $role): array => [$role, $this->modelOf($role), RoleEgress::describe($egress->leavesInstallation($role))], AiRole::ALL),
        );

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

    /**
     * The model choice of the role's feature, or the key of the active embedding profile; a dash when there is none.
     */
    private function modelOf(string $role): string
    {
        try {
            if ($role === AiRole::EMBEDDING) {
                return resolve(EmbeddingModelRegistry::class)->activeKey() ?: '-';
            }

            $feature = AiRole::feature($role);

            return $feature === null ? '-' : AiModelChoice::forFeature($feature)->value();
        } catch (Throwable) {
            return '-';
        }
    }
}

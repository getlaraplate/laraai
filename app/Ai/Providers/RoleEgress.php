<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Providers;

use InvalidArgumentException;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Providers\Models\ProviderConfiguration;
use Modules\AI\Ai\SelfHosted\AiServers;
use Throwable;

/**
 * Whether the text a role sends to its model leaves the installation, derived from configuration
 * only: nothing is called. A role served by a server the installation declares (a configured server
 * of `ai.servers`, or the URL of a provider that runs where the installer says) keeps the text; a
 * provider reached by its key alone is an external service. When the provider is unknown or not
 * configured nothing is claimed.
 *
 * The answer is about where the installer pointed the role, not about the network: a declared URL
 * that names a hosted service is still taken as the installation's own.
 */
final readonly class RoleEgress
{
    public function __construct(
        private ProviderConfiguration $providers = new ProviderConfiguration,
        private AiServers $servers = new AiServers,
    ) {}

    /**
     * The translated sentence for an answer of this class: the text leaves, stays, or it cannot be told.
     */
    public static function describe(?bool $leaves): string
    {
        return match ($leaves) {
            true => __('ai.messages.egressLeaves'),
            false => __('ai.messages.egressStays'),
            null => __('ai.messages.egressUnknown'),
        };
    }

    /**
     * True when the role's text goes to an external service, false when it stays on a server the
     * installation declares, null when it cannot be told. `$role` is an {@see AiRole} or the value of
     * an {@see \Modules\AI\Enums\AiModelFeature}; the embedding role is the active embedding profile.
     */
    public function leavesInstallation(string $role): ?bool
    {
        if ($role === AiRole::EMBEDDING) {
            try {
                $profile = resolve(EmbeddingModelRegistry::class)->active();
            } catch (Throwable) {
                return null;
            }

            return $this->forEmbeddingProfile($profile);
        }

        $feature = AiRole::feature($role);

        if ($feature === null) {
            return null;
        }

        try {
            $choice = AiModelChoice::forFeature($feature);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $this->forProvider($choice->provider);
    }

    /**
     * The same answer for a model chosen in Settings (`provider:model`), before it is saved.
     */
    public function forChoice(string $choice): ?bool
    {
        try {
            return $this->forProvider(AiModelChoice::parse($choice)->provider);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The same answer for an embedding profile: a profile of the `openai_compatible` provider runs on
     * the server of `ai.servers` it names (the default embeddings server when it names none).
     */
    public function forEmbeddingProfile(EmbeddingModelProfile $profile): ?bool
    {
        if ($profile->provider === 'openai_compatible') {
            return $this->servers->forProfile($profile)->isConfigured() ? false : null;
        }

        return self::negate($this->providers->reachesDeclaredServer($profile->provider, embeddings: true));
    }

    private static function negate(?bool $declared): ?bool
    {
        return $declared === null ? null : ! $declared;
    }

    private function forProvider(string $provider): ?bool
    {
        return self::negate($this->providers->reachesDeclaredServer($provider));
    }
}

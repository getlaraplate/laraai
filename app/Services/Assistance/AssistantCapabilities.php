<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance;

use function ai_config_bool;

use InvalidArgumentException;
use Modules\AI\Ai\Providers\ProviderFactory;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyCompiler;

/**
 * What the in-app assistant offers an instance, as a client may learn it before it shows any
 * assistant surface. Nothing here depends on the user or on what the request carries.
 */
final readonly class AssistantCapabilities
{
    public const string PROPOSALS_CAPABILITY = 'ui_proposals';

    public const string READS_CAPABILITY = 'crud_reads';

    public const string WRITES_CAPABILITY = 'governed_writes';

    public function __construct(private AssistantPolicyCompiler $policy_compiler) {}

    /**
     * The assistant's one switch is `features.chat.enabled`, off until an operator turns it on. It is
     * apart from the documentation: an assistant may serve information through its tools only.
     */
    public function enabled(): bool
    {
        return ai_config_bool('ai.features.chat.enabled', false);
    }

    /**
     * The assistant answers from the user documentation (`features.faq.enabled`). With it off the
     * assistant still serves what its tools reach, and reads no documentation.
     */
    public function help(): bool
    {
        return ai_config_bool('ai.features.faq.enabled', true);
    }

    /**
     * The chat provider chosen in Settings has what it needs to be called: a key, or a URL.
     */
    public function configured(): bool
    {
        return ProviderFactory::isConfigured();
    }

    /**
     * Proposals are offered when the policy compiled for the in-app profile with the capability
     * `ui_proposals` still allows a tool: the capability only holds the proposal tools, so an empty
     * result means that the catalog lacks the capability or that the profile does not grant it.
     */
    public function proposals(): bool
    {
        try {
            $policy = $this->policy_compiler->compile(AssistantProfile::InAppAssistance, [self::PROPOSALS_CAPABILITY]);
        } catch (InvalidArgumentException) {
            return false;
        }

        return $policy->allowedTools !== [];
    }

    /**
     * Writes are offered when the policy compiled for the in-app profile with the capability
     * `governed_writes` still allows a tool, and the instance has opted an entity into a write
     * operation (`ai.features.tools.crud.entities`). Which entities and operations a given person
     * may use is decided per user, by the tools that are built for them.
     */
    public function writes(): bool
    {
        try {
            $policy = $this->policy_compiler->compile(AssistantProfile::InAppAssistance, [self::WRITES_CAPABILITY]);
        } catch (InvalidArgumentException) {
            return false;
        }

        if ($policy->allowedTools === []) {
            return false;
        }

        $entities = config('ai.features.tools.crud.entities', []);

        foreach (is_array($entities) ? $entities : [] as $operations) {
            foreach (is_array($operations) ? $operations : [] as $operation) {
                if (in_array(mb_strtolower((string) $operation), ['create', 'update', 'delete', 'bulk_update', 'bulk_delete'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * `POST /app/ai/agent` streams the run as lifecycle events and complete validated messages.
     */
    public function streaming(): bool
    {
        return true;
    }

    /**
     * @return array{enabled: bool, configured: bool, features: array{help: bool, proposals: bool, writes: bool, streaming: bool}}
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled(),
            'configured' => $this->configured(),
            'features' => [
                'help' => $this->help(),
                'proposals' => $this->proposals(),
                'writes' => $this->writes(),
                'streaming' => $this->streaming(),
            ],
        ];
    }
}

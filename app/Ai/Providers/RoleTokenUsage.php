<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Providers;

/**
 * The tokens of one answered question per {@see AiRole}: only the roles whose provider reported a
 * usage are present, so a provider that reports nothing leaves its role out instead of claiming zero.
 */
final readonly class RoleTokenUsage
{
    /**
     * @param  array<string, TokenUsage>  $usage  keyed by role
     */
    public function __construct(
        public array $usage = [],
    ) {}

    /**
     * A copy with `$usage` added to the role; a null usage changes nothing.
     */
    public function with(string $role, ?TokenUsage $usage): self
    {
        if (! $usage instanceof TokenUsage) {
            return $this;
        }

        return new self([...$this->usage, $role => $usage->plus($this->usage[$role] ?? null)]);
    }
}

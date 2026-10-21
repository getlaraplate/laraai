<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Proposals;

use Illuminate\Support\Str;
use JsonException;
use Modules\AI\Data\UiProposal;
use Modules\AI\Services\Assistance\AssistanceGuardrailPipeline;
use Modules\AI\Services\Assistance\PlainText;

/**
 * Validates what a proposal tool receives from the model and keeps the proposals of one message.
 *
 * Tool arguments come from a model steered by text that is not fully ours, so each one is checked
 * as untrusted input: the target must be one the client declared, the value must satisfy the
 * schema it declared, the reason must be plain text of bounded length that passes the output
 * guardrails, and a message carries at most three proposals. Nothing here applies a change; the
 * answer to the model says the proposal waits for the user.
 */
final class UiProposalCollector
{
    public const int MAX_PROPOSALS = 3;

    public const int MAX_REASON_LENGTH = 240;

    private const int MAX_PROPOSED_BYTES = 2000;

    /**
     * @var list<UiProposal>
     */
    private array $proposals = [];

    public function __construct(
        private readonly ProposableTargets $targets,
        private readonly AssistanceGuardrailPipeline $guardrails,
    ) {}

    public function targets(): ProposableTargets
    {
        return $this->targets;
    }

    /**
     * @return list<UiProposal>
     */
    public function proposals(): array
    {
        return $this->proposals;
    }

    public function hasProposals(): bool
    {
        return $this->proposals !== [];
    }

    public function proposePreference(mixed $namespace, mixed $key, mixed $proposed, mixed $reason): string
    {
        if (! is_string($namespace) || ! is_string($key)) {
            return $this->refusal('the target is not a known namespace and key');
        }

        return $this->propose(UiProposal::KIND_PREFERENCE, ['namespace' => $namespace, 'key' => $key], $proposed, $reason);
    }

    public function proposeViewState(mixed $resource, mixed $view, mixed $proposed, mixed $reason): string
    {
        if (! is_string($resource) || ! is_string($view)) {
            return $this->refusal('the target is not a known resource and view');
        }

        return $this->propose(UiProposal::KIND_VIEW_STATE, ['resource' => $resource, 'view' => $view], $proposed, $reason);
    }

    /**
     * @param  array<string, string>  $target
     */
    private function propose(string $kind, array $target, mixed $proposed, mixed $reason): string
    {
        if (count($this->proposals) >= self::MAX_PROPOSALS) {
            return $this->refusal('a message carries at most ' . self::MAX_PROPOSALS . ' proposals');
        }

        $declared = $this->targets->find($kind, $target);

        if (! $declared instanceof ProposableTarget) {
            return $this->refusal('that target is not among the ones the page declares');
        }

        foreach ($this->proposals as $existing) {
            if ($existing->kind === $kind && $existing->target === $target) {
                return $this->refusal('that target already has a proposal in this message');
            }
        }

        $value = $this->decode($proposed);

        if ($value === null || ! ProposalSchema::accepts($declared->schema, $value['value'])) {
            return $this->refusal('the proposed value is not allowed for that target');
        }

        $text = PlainText::validated($reason, self::MAX_REASON_LENGTH, $this->guardrails);

        if ($text === null) {
            return $this->refusal('the reason must be plain text of at most ' . self::MAX_REASON_LENGTH . ' characters');
        }

        $this->proposals[] = new UiProposal(
            id: (string) Str::uuid(),
            kind: $kind,
            target: $target,
            current: $declared->current,
            proposed: $value['value'],
            reason: $text,
        );

        return 'Proposal recorded. It waits for the user, who may accept or refuse it, and nothing has been changed. '
            . 'Tell the user that you are suggesting it. Never say that it was applied, done or saved.';
    }

    /**
     * The model passes the value as JSON text, since its type depends on the target.
     *
     * @return array{value: mixed}|null
     */
    private function decode(mixed $proposed): ?array
    {
        if (! is_string($proposed)) {
            return ['value' => $proposed];
        }

        if (mb_strlen($proposed) > self::MAX_PROPOSED_BYTES) {
            return null;
        }

        try {
            return ['value' => json_decode($proposed, true, 8, JSON_THROW_ON_ERROR)];
        } catch (JsonException) {
            return null;
        }
    }

    private function refusal(string $why): string
    {
        return "Not proposed: {$why}. Nothing was recorded.";
    }
}

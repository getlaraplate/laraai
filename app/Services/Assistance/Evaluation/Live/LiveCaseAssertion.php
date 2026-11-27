<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Evaluation\Live;

use Closure;
use InvalidArgumentException;
use NeuronAI\Evaluation\AssertionResult;
use NeuronAI\Evaluation\Assertions\AbstractAssertion;
use Override;

/**
 * One expectation of a dataset case on what the live assistant did, as a Neuron assertion: it passes or fails
 * with score 1 or 0, and its name is the metric the report groups it under.
 */
final class LiveCaseAssertion extends AbstractAssertion
{
    /**
     * @param  Closure(LiveAssistantRun): bool  $holds
     */
    public function __construct(
        private readonly string $metric,
        private readonly Closure $holds,
        private readonly string $failure,
    ) {}

    #[Override]
    public function getName(): string
    {
        return $this->metric;
    }

    #[Override]
    public function evaluate(mixed $actual): AssertionResult
    {
        if (! $actual instanceof LiveAssistantRun) {
            throw new InvalidArgumentException('A live case assertion evaluates a LiveAssistantRun, got ' . get_debug_type($actual) . '.');
        }

        return ($this->holds)($actual)
            ? AssertionResult::pass(1.0)
            : AssertionResult::fail(0.0, $this->failure);
    }
}

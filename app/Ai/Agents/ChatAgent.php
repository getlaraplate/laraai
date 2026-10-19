<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Agents;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\AI\Ai\Providers\AiModelChoice;
use Modules\AI\Ai\Providers\ProviderFactory;
use Modules\AI\Enums\AiModelFeature;
use Modules\AI\Exceptions\AssistancePolicyViolationException;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use Override;
use Throwable;

/**
 * General-purpose chat agent powered by NeuronAI.
 * Supports multi-provider configuration via ProviderFactory.
 *
 * @method static static make(?string $providerName = null, ?string $systemPrompt = null, ?string $model = null, ?int $maxOutputTokens = null, ?float $timeout = null)
 */
class ChatAgent extends Agent
{
    public function __construct(
        protected ?string $providerName = null,
        protected ?string $systemPrompt = null,
        protected ?string $model = null,
        protected ?int $maxOutputTokens = null,
        protected ?float $timeout = null,
    ) {
        // Neuron runs nothing without a thread id; the application keeps its own conversations, so each agent gets a throwaway one.
        parent::__construct(workflowId: (string) Str::uuid7());
    }

    /**
     * An agent on the provider and model chosen in Settings for this feature, limited to
     * `$maxOutputTokens` of output when given.
     */
    public static function forFeature(AiModelFeature $feature, ?string $systemPrompt = null, ?int $maxOutputTokens = null): static
    {
        $choice = AiModelChoice::forFeature($feature);

        return static::make($choice->provider, $systemPrompt, $choice->model, $maxOutputTokens);
    }

    /**
     * The answer to a prompt as plain text, trimmed: for the one-shot calls that want a string and
     * nothing else (a summary, a translation, a rewritten note).
     */
    public function ask(string $prompt): string
    {
        return mb_trim($this->chat(new UserMessage($prompt))->getMessage()?->getContent() ?? '');
    }

    /**
     * Caps the seconds one call to the model may take, for a caller that would rather degrade than wait.
     */
    public function withTimeout(float $seconds): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * A tool that fails does not end the turn: the model gets a fixed message as the tool's result and
     * can try again or answer without it. The message never carries the exception's text, which can name
     * a parameter, a class or a record. A policy violation is not a failure of the tool and is not
     * handled here: it ends the turn as it always did.
     *
     * @return (callable(Throwable, ToolCall): (string|ToolOutput|null))|null
     */
    #[Override]
    protected function resolveToolErrorHandler(): ?callable
    {
        return $this->toolErrorHandler ?? static function (Throwable $exception, ToolCall $call): string {
            if ($exception instanceof AssistancePolicyViolationException) {
                throw $exception;
            }

            Log::warning('Assistant tool call failed', ['tool' => $call->getName(), 'exception' => $exception::class]);

            return $exception instanceof ToolRunsExceededException
                ? 'This tool has been called too many times in this conversation turn. Answer with what you already have.'
                : 'The tool call failed. Check the arguments against the tool definition and try once more, or answer without it.';
        };
    }

    protected function provider(): AIProviderInterface
    {
        return ProviderFactory::make($this->providerName, $this->model, $this->maxOutputTokens, $this->timeout);
    }

    #[Override]
    protected function instructions(): SystemMessage
    {
        return new SystemMessage($this->systemPrompt ?? 'You are a helpful AI assistant.');
    }
}

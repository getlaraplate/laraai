<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\AI\Data\UiProposal;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Exceptions\AssistancePolicyViolationException;
use Modules\AI\Http\Resources\WriteProposalResource;
use Modules\AI\Jobs\GenerateConversationTitleJob;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\Message;
use Modules\AI\Models\WriteProposal;
use Modules\AI\Services\ApplicationContent\ApplicationContentCitationMapper;
use Modules\AI\Services\ApplicationContent\Data\ApplicationContentRequestContext;
use Modules\AI\Services\Assistance\Contracts\InAppAssistanceServiceInterface;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyCompiler;
use Modules\AI\Services\Assistance\Policies\CompiledAssistantPolicy;
use Modules\AI\Services\Assistance\Proposals\ProposableTargets;
use Modules\AI\Services\Assistance\Proposals\UiProposalCollector;
use Modules\AI\Services\Assistance\Scope\AssistantScope;
use Modules\AI\Services\Assistance\Scope\AssistantScopeResolver;
use Modules\AI\Services\Assistance\Scope\DataAccess;
use Modules\AI\Services\Assistance\Stream\RunProgress;
use Modules\AI\Services\Assistance\Writes\ActingIdentityBlock;
use Modules\AI\Services\Assistance\Writes\WriteProposalService;
use Modules\AI\Services\ChatService;
use Modules\AI\Services\Documentation\Analytics\RagQueryRecorder;
use Modules\AI\Services\DocumentationService;
use Modules\AI\Services\Tools\CompositeContextualToolProvider;
use Modules\AI\Services\Tools\ContextualToolProviderInterface;
use Modules\AI\Services\Tools\ProposePreferenceChangeTool;
use Modules\AI\Services\Tools\ProposeViewStateTool;
use Modules\AI\Services\Tools\ToolDefinition;
use Modules\AI\Services\Tools\ToolRegistry;
use Modules\AI\Services\Tools\ToolResultGuard;
use Modules\Core\Models\User;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\Tools\Tool;
use Throwable;

final readonly class InAppAssistanceService implements InAppAssistanceServiceInterface
{
    /**
     * @param  (Closure(string, AssistantAccessContext, AssistantScope): list<Document>)|null  $documentation_retrieval
     * @param  (Closure(string, string, AssistantPromptContext, list<Tool>): string)|null  $completion
     */
    public function __construct(
        private AssistantAccessContextFactory $access_context_factory,
        private AssistantPolicyCompiler $policy_compiler,
        private AssistanceGuardrailPipeline $guardrails,
        private DocumentationService $documentation,
        private ContextualToolProviderInterface $tool_provider,
        private ToolRegistry $tool_registry,
        private ChatService $chat_service,
        private Request $request,
        private AssistantScopeResolver $scope_resolver,
        private ?Closure $documentation_retrieval = null,
        private ?Closure $completion = null,
        private ?ApplicationContentCitationMapper $application_content_citations = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $request_context
     * @param  (Closure(string, string): void)|null  $progress  told where the run is, with the words of {@see RunProgress}
     */
    public function respond(
        Conversation $conversation,
        User $authenticated_user,
        string $user_input,
        ?array $request_context = null,
        ?Closure $progress = null,
    ): Message {
        $access = $this->access_context_factory->forInApp($conversation, $authenticated_user);
        $this->assertRequestIdentity($access);
        $application_content = $this->applicationContentCitations();
        $application_content->reset();
        $write_proposals = app(WriteProposalService::class);
        $write_proposals->startTurn();

        try {
            // With the documentation setting off the assistant still serves what its tools reach: it
            // neither reads the documentation nor is told to answer from it.
            $reads_documentation = app(AssistantCapabilities::class)->help();
            $policy = $this->policy_compiler->compile(
                AssistantProfile::InAppAssistance,
                [
                    'application_content',
                    ...($reads_documentation ? ['in_app_rag'] : []),
                    'read_only_graph',
                    AssistantCapabilities::PROPOSALS_CAPABILITY,
                    AssistantCapabilities::READS_CAPABILITY,
                    AssistantCapabilities::WRITES_CAPABILITY,
                ],
            );
            $input = $this->guardrails->validateInput($user_input);
            $this->report($progress, RunProgress::STARTED, RunProgress::STEP_RETRIEVE);
            $module_context = $this->serverApplicationContext();
            $scope = $this->scope_resolver->resolve($access->profile, $module_context?->module);
            $retrieval_started_at = hrtime(true);
            $documents = match (true) {
                ! $reads_documentation => [],
                $this->documentation_retrieval instanceof Closure => ($this->documentation_retrieval)($input, $access, $scope),
                default => $this->documentation->retrieveForInApp($input, $access, $scope),
            };
            $retrieval_latency_ms = (hrtime(true) - $retrieval_started_at) / 1_000_000;
            $prompt_context = $this->guardrails->validateContext(
                $this->promptContext($policy, $documents, $request_context),
            );
            $documentation_citation_count = count($prompt_context->safeCitations);
            // DataAccess::None is currently unreachable via respond() (forInApp always resolves InAppAssistance profile);
            // reserved as a seam for future guest/superadmin profiles routed through this service (see docs/rag/ASSISTANT_SCOPE.md).
            $definitions = $scope->dataAccess === DataAccess::None
                ? []
                : $this->contextualDefinitions($access, $input, $policy);
            $identity = ActingIdentityBlock::render($authenticated_user, $definitions);

            if ($identity !== null) {
                $policy = $policy->withInstructions($identity);
            }

            $guard = new ToolResultGuard($this->guardrails);
            $tools = $this->tool_registry->getNeuronToolsForDefinitions(array_map($guard->wrap(...), $definitions));
            $proposals = $this->proposalCollector($policy);
            $tools = [...$tools, ...$this->proposalTools($policy, $proposals)];
            $this->report($progress, RunProgress::FINISHED, RunProgress::STEP_RETRIEVE);
            $this->report($progress, RunProgress::STARTED, RunProgress::STEP_ANSWER);

            if ($application_content->clarificationRequired()) {
                $output = $this->guardrails->clarificationRequired($access->locale);
            } else {
                $output = $this->completion instanceof Closure
                    ? ($this->completion)($input, $policy->systemPrompt, $prompt_context, $tools)
                    : $this->complete($input, $policy, $prompt_context, $tools);
            }

            $this->report($progress, RunProgress::FINISHED, RunProgress::STEP_ANSWER);
            $this->report($progress, RunProgress::STARTED, RunProgress::STEP_VALIDATE);

            if ($application_content->attempted() && ! $application_content->hasEvidence()) {
                $output = $this->guardrails->insufficientEvidence($access->locale);
            } else {
                $output = $this->guardrails->validateOutput($output);
            }

            $prompt_context = $this->mergeApplicationContent($prompt_context, $application_content);
            $metadata = ['citations' => $prompt_context->safeCitations];

            if ($proposals instanceof UiProposalCollector && $proposals->hasProposals()) {
                $output = $this->guardrails->reportPendingProposals($output, $access->locale);
                $metadata['proposals'] = array_map(
                    static fn (UiProposal $proposal): array => $proposal->toArray(),
                    $proposals->proposals(),
                );
            }

            $pending_writes = $write_proposals->proposedInTurn();

            if ($pending_writes !== []) {
                $output = $this->guardrails->reportPendingWrites($output, $access->locale);
                $metadata['writes'] = array_map(
                    static fn (WriteProposal $proposal): array => (new WriteProposalResource($proposal))->resolve(),
                    $pending_writes,
                );
            }

            $conversation->addMessage('user', $input, $this->presentationPreferences($request_context));

            $reply = $conversation->addMessage('assistant', $output, $metadata);
            $this->titleTheConversation($conversation, $reply);
            app(RagQueryRecorder::class)->record(
                $authenticated_user,
                $access,
                $input,
                count($documents),
                $documentation_citation_count,
                $retrieval_latency_ms,
            );
            $this->report($progress, RunProgress::FINISHED, RunProgress::STEP_VALIDATE);

            return $reply;
        } catch (Throwable $exception) {
            $reason_code = $exception instanceof AssistancePolicyViolationException
                ? $exception->reasonCode
                : 'assistance_unavailable';

            Log::notice('In-app assistance refused', [
                'reason_code' => $reason_code,
                'profile' => AssistantProfile::InAppAssistance->value,
                'conversation_id' => $access->conversationId,
            ]);

            // The refusal that is stored says nothing of why. A run that is watched learns only the
            // coarse code of the contract: a policy said no, or something else failed.
            $this->report(
                $progress,
                RunProgress::REFUSED,
                $exception instanceof AssistancePolicyViolationException ? RunProgress::POLICY_DENIED : RunProgress::PROVIDER_ERROR,
            );

            return $conversation->addMessage('assistant', $this->safeRefusal(), [
                'refused' => true,
            ]);
        }
    }

    /**
     * Tells whoever watches the run where it is. A watcher that fails never fails the run.
     *
     * @param  (Closure(string, string): void)|null  $progress
     */
    private function report(?Closure $progress, string $kind, string $detail): void
    {
        if (! $progress instanceof Closure) {
            return;
        }

        try {
            $progress($kind, $detail);
        } catch (Throwable) {
            // The run goes on.
        }
    }

    /**
     * The tool definitions the policy lets this turn have: what the providers offer for the acting user,
     * filtered by the compiled policy. Providers decide what exists, the policy decides what the surface
     * may reach, and the offer is the intersection.
     *
     * @return list<ToolDefinition>
     */
    private function contextualDefinitions(
        AssistantAccessContext $access,
        string $input,
        CompiledAssistantPolicy $policy,
    ): array {
        $definitions = $this->tool_provider instanceof CompositeContextualToolProvider
            ? $this->tool_provider->toolsForRequest($access, $input, $this->serverApplicationContext())
            : $this->tool_provider->tools($access);

        return array_values(array_filter(
            $definitions,
            static fn (ToolDefinition $definition): bool => $policy->allowsTool($definition->name),
        ));
    }

    /**
     * The proposals of this message, when the compiled policy allows a proposal tool and the page
     * declared something proposable. Without both, the assistant has no proposal tool at all.
     */
    private function proposalCollector(CompiledAssistantPolicy $policy): ?UiProposalCollector
    {
        if (array_intersect([ProposePreferenceChangeTool::NAME, ProposeViewStateTool::NAME], $policy->allowedTools) === []) {
            return null;
        }

        $targets = ProposableTargets::fromPageContext($this->request->input('context.page.proposable'));

        return $targets->isEmpty() ? null : new UiProposalCollector($targets, $this->guardrails);
    }

    /**
     * @return list<Tool>
     */
    private function proposalTools(CompiledAssistantPolicy $policy, ?UiProposalCollector $collector): array
    {
        if (! $collector instanceof UiProposalCollector) {
            return [];
        }

        $definitions = [];

        if (in_array(ProposePreferenceChangeTool::NAME, $policy->allowedTools, true) && $collector->targets()->has(UiProposal::KIND_PREFERENCE)) {
            $definitions[] = new ProposePreferenceChangeTool($collector)->definition();
        }

        if (in_array(ProposeViewStateTool::NAME, $policy->allowedTools, true) && $collector->targets()->has(UiProposal::KIND_VIEW_STATE)) {
            $definitions[] = new ProposeViewStateTool($collector)->definition();
        }

        return $this->tool_registry->getNeuronToolsForDefinitions($definitions);
    }

    private function serverApplicationContext(): ?ApplicationContentRequestContext
    {
        $context = $this->request->attributes->get('assistant_application_context');

        if (! is_array($context) || ! is_string($context['module'] ?? null)) {
            return null;
        }

        try {
            return new ApplicationContentRequestContext(
                module: $context['module'],
                entity: is_string($context['entity'] ?? null) ? $context['entity'] : null,
                recordKey: is_int($context['record_key'] ?? null) || is_string($context['record_key'] ?? null)
                    ? $context['record_key']
                    : null,
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function applicationContentCitations(): ApplicationContentCitationMapper
    {
        return $this->application_content_citations ?? app(ApplicationContentCitationMapper::class);
    }

    private function mergeApplicationContent(
        AssistantPromptContext $context,
        ApplicationContentCitationMapper $applicationContent,
    ): AssistantPromptContext {
        if (! $applicationContent->hasEvidence()) {
            return $context;
        }

        return $this->guardrails->validateContext(new AssistantPromptContext(
            policyVersion: $context->policyVersion,
            presentationPreferences: $context->presentationPreferences,
            safeCitations: array_slice([
                ...$applicationContent->citations(),
                ...$context->safeCitations,
            ], 0, 10),
            authorizedResults: array_slice([
                ...$applicationContent->results(),
                ...$context->authorizedResults,
            ], 0, 50),
        ));
    }

    /**
     * The first answer that is not a refusal asks for a title, when the conversation has none. The
     * answer is returned without waiting for it, and a failure to queue the job is not the reply's.
     */
    private function titleTheConversation(Conversation $conversation, Message $reply): void
    {
        if ($conversation->title !== null) {
            return;
        }

        try {
            GenerateConversationTitleJob::dispatch($reply->id);
        } catch (Throwable $exception) {
            Log::notice('Conversation title not queued', ['conversation_id' => $conversation->id, 'exception' => $exception::class]);
        }
    }

    private function assertRequestIdentity(AssistantAccessContext $access): void
    {
        $request_user_id = $this->request->user()?->getAuthIdentifier();

        if ($request_user_id === null || $access->userId !== mb_trim((string) $request_user_id)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Assistant access context is unavailable.');
        }
    }

    /**
     * @param  list<Document>  $documents
     * @param  array<string, mixed>|null  $request_context
     */
    private function promptContext(
        CompiledAssistantPolicy $policy,
        array $documents,
        ?array $request_context,
    ): AssistantPromptContext {
        $citations = [];
        $authorized_results = [];

        foreach ($documents as $document) {
            if (! $document instanceof Document) {
                throw new AssistancePolicyViolationException('unsafe_document');
            }

            $label = mb_trim($document->getSourceName());

            if ($label === '') {
                throw new AssistancePolicyViolationException('unsafe_citation');
            }

            $excerpt = Str::limit($document->getContent(), 1000);
            $citations[] = [
                'label' => $label,
                'excerpt' => Str::limit($excerpt, 300),
                'score' => $document->getScore(),
            ];
            $authorized_results[] = array_filter([
                'content' => $excerpt,
                'heading_breadcrumb' => $this->metadataString($document, 'heading_breadcrumb'),
                'label' => $label,
                'locale' => $this->metadataString($document, 'locale'),
                'module' => $this->metadataString($document, 'module'),
                'version' => $this->metadataString($document, 'version'),
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return new AssistantPromptContext(
            policyVersion: $policy->version,
            presentationPreferences: $this->presentationPreferences($request_context),
            safeCitations: $citations,
            authorizedResults: $authorized_results,
        );
    }

    /**
     * @param  list<Tool>  $tools
     */
    private function complete(
        string $input,
        CompiledAssistantPolicy $policy,
        AssistantPromptContext $prompt_context,
        array $tools,
    ): string {
        $agent = $this->chat_service->buildProtectedAgent($policy, $prompt_context);

        foreach ($tools as $tool) {
            $agent->addTool($tool);
        }

        return $agent->chat(new UserMessage($input))->getMessage()?->getContent() ?? '';
    }

    /**
     * @param  array<string, mixed>|null  $request_context
     * @return array<string, mixed>
     */
    private function presentationPreferences(?array $request_context): array
    {
        if ($request_context === null) {
            return [];
        }

        return array_intersect_key($request_context, array_flip([
            'accessibility',
            'locale',
            'response_format',
            'verbosity',
        ]));
    }

    private function metadataString(Document $document, string $key): ?string
    {
        $value = $document->getMetadata()[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    private function safeRefusal(): string
    {
        return $this->guardrails->validateOutput(
            'I cannot provide that information. I can help you use the features available in the application.',
        );
    }
}

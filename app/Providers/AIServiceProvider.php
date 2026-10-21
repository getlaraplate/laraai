<?php

declare(strict_types=1);

namespace Modules\AI\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingModelSettingConfirmation;
use Modules\AI\Ai\MediaAnalysis\Contracts\MediaTranscriber;
use Modules\AI\Ai\MediaAnalysis\Contracts\MediaVisionAnalyzer;
use Modules\AI\Ai\MediaAnalysis\MediaAnalysisGate;
use Modules\AI\Ai\MediaAnalysis\MediaAnalysisModelRegistry;
use Modules\AI\Ai\MediaAnalysis\Transcription\WhisperTranscriber;
use Modules\AI\Ai\MediaAnalysis\Vision\NeuronVisionAnalyzer;
use Modules\AI\Ai\Rag\RagIndexRebuilder;
use Modules\AI\Console\PruneRagQueryAnalyticsCommand;
use Modules\AI\Console\RefreshAiModelsCommand;
use Modules\AI\Console\RepairMissingEmbeddingsCommand;
use Modules\AI\Contracts\IChatService;
use Modules\AI\Contracts\IEmbeddableModels;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Contracts\IRagIndexRebuilder;
use Modules\AI\Contracts\ITranslatableModelClassNames;
use Modules\AI\Filament\MediaAnalysisSchemaContributor;
use Modules\AI\Models\ContextualSuggestion;
use Modules\AI\Models\Conversation;
use Modules\AI\Observers\MediaAnalysisRefcountObserver;
use Modules\AI\Observers\PurgeAssistantDataOfDeletedUserObserver;
use Modules\AI\Observers\PurgeDeletedConversationObserver;
use Modules\AI\Search\AiSearchStrategyResolver;
use Modules\AI\Search\MediaAnalysisSearchContributor;
use Modules\AI\Services\ApplicationContent\ApplicationContentCitationMapper;
use Modules\AI\Services\ApplicationContent\ApplicationContentToolProvider;
use Modules\AI\Services\ApplicationContent\Evaluation\ApplicationContentEvaluationService;
use Modules\AI\Services\ApplicationContent\Evaluation\ApplicationContentRetrievalStrategyEvaluationService;
use Modules\AI\Services\ApplicationContent\Evaluation\Contracts\PerStrategyEngineRetrieverInterface;
use Modules\AI\Services\ApplicationContent\Evaluation\PerStrategyEngineRetriever;
use Modules\AI\Services\Assistance\AssistanceGuardrailPipeline;
use Modules\AI\Services\Assistance\Contracts\AssistantTenantResolverInterface;
use Modules\AI\Services\Assistance\Contracts\InAppAssistanceServiceInterface;
use Modules\AI\Services\Assistance\GlobalAssistantTenantResolver;
use Modules\AI\Services\Assistance\InAppAssistanceService;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyCatalog;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyCompiler;
use Modules\AI\Services\Assistance\Writes\AssistantWriteBudget;
use Modules\AI\Services\Assistance\Writes\WriteProposalService;
use Modules\AI\Services\ChatService;
use Modules\AI\Services\DiscoveryTranslatableModelClassNames;
use Modules\AI\Services\Documentation\Chunking\SplitterFactory;
use Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationService;
use Modules\AI\Services\EmbeddableModels;
use Modules\AI\Services\EmbeddingService;
use Modules\AI\Services\EmbeddingVectorSearchAvailability;
use Modules\AI\Services\SearchEmbedder;
use Modules\AI\Services\Tools\CompositeContextualToolProvider;
use Modules\AI\Services\Tools\ContextualToolProviderInterface;
use Modules\AI\Services\Tools\CrudToolProvider;
use Modules\AI\Services\Tools\GraphToolProvider;
use Modules\Core\Filament\ResourceSchemaContributorRegistry;
use Modules\Core\Models\Media;
use Modules\Core\Models\User;
use Modules\Core\Overrides\ModuleServiceProvider;
use Modules\Core\Search\Contracts\ISearchStrategyResolver;
use Modules\Core\Search\Contracts\ITextEmbedder;
use Modules\Core\Search\Contracts\IVectorSearchAvailability;
use Modules\Core\Search\SearchableContributorRegistry;
use Modules\Core\Search\Services\VectorSearchAvailability;
use Modules\Core\Services\SettingChangeConfirmations;
use NeuronAI\RAG\Splitter\SplitterInterface;
use Override;

class AIServiceProvider extends ModuleServiceProvider
{
    #[Override]
    protected string $name = 'AI';

    #[Override]
    protected string $nameLower = 'ai';

    #[Override]
    public function register(): void
    {
        parent::register();

        $this->app->singleton(IChatService::class, ChatService::class);
        $this->app->singleton(IEmbeddingService::class, EmbeddingService::class);
        $this->app->singleton(IEmbeddableModels::class, EmbeddableModels::class);
        $this->app->bind(IRagIndexRebuilder::class, RagIndexRebuilder::class);
        $this->app->singleton(EmbeddingModelRegistry::class);
        $this->app->singleton(
            IVectorSearchAvailability::class,
            static fn ($app): IVectorSearchAvailability => new EmbeddingVectorSearchAvailability(
                $app->make(VectorSearchAvailability::class),
                $app->make(EmbeddingModelRegistry::class),
            ),
        );

        // Media analysis (M6, M21): the model registry plus the swappable analyzer
        // contracts. Vision is neuron-ai-backed; transcription posts to the self
        // -hosted Whisper service (a no-op when WHISPER_URL is unset).
        $this->app->singleton(MediaAnalysisModelRegistry::class);
        $this->app->bind(MediaVisionAnalyzer::class, NeuronVisionAnalyzer::class);
        $this->app->bind(MediaTranscriber::class, WhisperTranscriber::class);
        $this->app->singleton(ITranslatableModelClassNames::class, DiscoveryTranslatableModelClassNames::class);
        $this->app->bind(GraphToolProvider::class);
        $this->app->bind(CrudToolProvider::class);
        $this->app->scoped(ApplicationContentCitationMapper::class);
        $this->app->scoped(AssistantWriteBudget::class);
        $this->app->scoped(WriteProposalService::class);
        $this->app->bind(ApplicationContentToolProvider::class);
        $this->app->singleton(ApplicationContentEvaluationService::class);
        $this->app->singleton(ApplicationContentRetrievalStrategyEvaluationService::class);
        $this->app->bind(PerStrategyEngineRetrieverInterface::class, PerStrategyEngineRetriever::class);
        $this->app->singleton(DocumentationEvaluationService::class);
        $this->app->bind(
            ContextualToolProviderInterface::class,
            static fn ($app): ContextualToolProviderInterface => new CompositeContextualToolProvider([
                $app->make(GraphToolProvider::class),
                $app->make(CrudToolProvider::class),
                $app->make(ApplicationContentToolProvider::class),
            ]),
        );
        $this->app->singleton(AssistantTenantResolverInterface::class, GlobalAssistantTenantResolver::class);
        $this->app->bind(InAppAssistanceServiceInterface::class, InAppAssistanceService::class);
        $this->app->singleton(
            AssistanceGuardrailPipeline::class,
            static fn (): AssistanceGuardrailPipeline => AssistanceGuardrailPipeline::defaults(),
        );
        $this->app->singleton(
            AssistantPolicyCatalog::class,
            static fn (): AssistantPolicyCatalog => AssistantPolicyCatalog::defaults(),
        );
        $this->app->singleton(
            AssistantPolicyCompiler::class,
            static fn ($app): AssistantPolicyCompiler => new AssistantPolicyCompiler(
                $app->make(AssistantPolicyCatalog::class),
            ),
        );

        $this->app->bind(SplitterInterface::class, static fn (): SplitterInterface => SplitterFactory::make());
    }

    #[Override]
    public function boot(): void
    {
        parent::boot();

        $this->registerSearchBindings();
        $this->registerRateLimiters();

        // Contribute AI media analysis to the media search document/vector through
        // Core's contributor seam (M4a). No-op until analysis rows exist, so Core
        // stays AI-agnostic and this is safe to register unconditionally.
        $this->app->make(SearchableContributorRegistry::class)
            ->register(new MediaAnalysisSearchContributor());

        // Contribute the AI analysis panel + re-analyze action to the media Filament
        // view through Core's resource-schema seam (M22). Gated by the master switch
        // inside the contributor, so Core stays AI-agnostic.
        $this->app->make(ResourceSchemaContributorRegistry::class)
            ->register(new MediaAnalysisSchemaContributor($this->app->make(MediaAnalysisGate::class)));

        Media::observe(MediaAnalysisRefcountObserver::class);

        // What the assistant keeps about a user (suggestions, conversations) goes with the user, and
        // what a conversation holds goes with the conversation: both are soft deleted by default,
        // which no foreign key sees.
        User::observe(PurgeAssistantDataOfDeletedUserObserver::class);
        Conversation::observe(PurgeDeletedConversationObserver::class);

        // Changing the embedding model from the settings page asks for a confirmation that shows
        // what the switch costs, starts it on confirm and locks the field while one runs.
        $this->app->make(SettingChangeConfirmations::class)
            ->register($this->app->make(EmbeddingModelSettingConfirmation::class));
    }

    #[Override]
    protected function registerCommandSchedules(): void
    {
        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)
                ->command(RefreshAiModelsCommand::class)
                ->dailyAt('03:00')
                ->onOneServer();

            // Heals the records an embedding outage degraded to keyword-only: a job that spent its
            // exception budget indexes the record without a vector and nothing else brings it back.
            // The command probes the service first, so an outage costs nothing, and skips while
            // jobs are queued, so a long backfill is not dispatched twice.
            $this->app->make(Schedule::class)
                ->command(RepairMissingEmbeddingsCommand::class, ['--all', '--if-idle'])
                ->hourly()
                ->withoutOverlapping()
                ->onOneServer();

            // Retention of the contextual suggestions: older rows are stale data about what a user
            // was doing. The limit is ContextualSuggestion::RETENTION_DAYS, in code.
            $this->app->make(Schedule::class)
                ->command('model:prune', ['--model' => [ContextualSuggestion::class]])
                ->daily()
                ->onOneServer();

            // Retention of the documentation query log: documents past the window of the setting
            // features.faq.query_logging.retention_days (docs/rag/query-analytics-privacy-review.md).
            $this->app->make(Schedule::class)
                ->command(PruneRagQueryAnalyticsCommand::class)
                ->daily()
                ->onOneServer();
        });
    }

    /**
     * The documentation search route, per signed-in user: every call costs an embedding. The route
     * requires a session, so a call without a user is counted per client address. The ceiling is
     * read when a request arrives.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('documentation-search', static function (Request $request): Limit {
            $user = $request->user();
            $limit = Limit::perMinute((int) config('ai.features.faq.search.rate_per_minute', 60));

            return $user === null ? $limit->by($request->ip()) : $limit->by($user->getAuthIdentifier());
        });
    }

    /**
     * Override Core search contract bindings with AI-powered implementations.
     */
    private function registerSearchBindings(): void
    {
        // One contract overlaid, per request: Core's own components stay bound to its search contracts,
        // so a search that asks for `fast` never pays for a model. The embedder stays bound for the
        // commands that embed outside a search.
        $this->app->singleton(ISearchStrategyResolver::class, AiSearchStrategyResolver::class);
        $this->app->singleton(ITextEmbedder::class, SearchEmbedder::class);
    }
}

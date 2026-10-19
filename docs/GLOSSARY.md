# AI module glossary

Canonical English names for AI entities in this module. Use these terms in code, APIs, and cross-module documentation.

## Chat and conversations

| Term | Meaning |
|------|---------|
| **Conversation** | Persistent chat session owned by a user; holds ordered `Message` rows. |
| **Message** | Single user or assistant utterance within a `Conversation`. |
| **ConversationSummary** | Condensed history used to keep context windows manageable. |
| **ChatService** | Creates conversations and builds the protected agent (`buildProtectedAgent`). It does not answer: every message endpoint calls `InAppAssistanceService`. |
| **ChatController** | HTTP layer for the conversation endpoints (`insertMessage`, `streamMessage`, listing and deleting). |
| **streamMessage** | Answers 422 `in_app_streaming_unavailable`: the protected assistant validates the whole answer before it sends any of it. The event stream is `POST /app/ai/agent` (`AgentController`, AG-UI events), which sends the message once, whole. |
| **insertMessage** | Non-streaming JSON response path (jobs, integrations, tests). |
| **ChatAgent** | NeuronAI agent wrapper that every call to the model goes through (`forFeature()`, `ask()`, `structured()`); the protected agent of `ChatService` is one. |

## Tool system (governed writes)

| Term | Definition |
|------|------------|
| **Write proposal** (`WriteProposal`) | A write the assistant asked for, stored with its exact payload; it changes nothing until the person confirms it. |
| **WriteProposalService** | Stores proposals, applies one when the person confirms it (once, under a row lock) and logs each step. |
| **AssistantWriteController** | The person's confirm, reject and show actions on a proposal, the only way a proposal is applied. |
| **governed_writes / crud_reads** | Policy capabilities that admit the `crud_*` write and read tools for the in-app profile only. |
| **unmoderated_writes** | Operator list of entities without approvals on which the assistant may nonetheless write; "applied directly, no vote". |
| **ToolResultGuard** | Withholds from the model the result of an entity read tool whose text reads like an instruction. |
| **ActionRequest, RiskClassifier** | Removed 2026-10-07. A tool-level approval path that nothing used; approval is Core's, at the model. |

## RAG and documentation intelligence

| Term | Meaning |
|------|---------|
| **DocumentationAgent** | NeuronAI `RAG` subclass: chunking, embedding, retrieval, answer synthesis. |
| **DocumentationService** | Application service for ingest, reindex, and query over module docs. |
| **EmbeddingService** | Implements `IEmbeddingService`; produces vectors for models and doc chunks. |
| **rag_paths()** | Helper returning documentation roots and source prefixes for indexing. |
| **FileVectorStore** | Filesystem-backed vector store for RAG persistence. |
| **MemoryVectorStore** | In-memory vector store (tests / ephemeral). |
| **MarkdownAwareSplitter** | Default `SplitterInterface`; keeps fenced blocks (e.g. Mermaid) intact. |
| **SplitterFactory** | Resolves the active document splitter implementation. |
| **reindexBySource** | Incremental RAG update per logical source prefix. |
| **indexDocuments** | Artisan-driven full or incremental documentation indexing pipeline. |

## Core search orchestration (AI bindings)

| Term | Meaning |
|------|---------|
| **HandleModelIndexingListener** | Listens to `ModelRequiresIndexing`; dispatches `GenerateEmbeddingsJob` when enabled. |
| **GenerateEmbeddingsJob** | Embeds searchable model content; emits `ModelPreProcessingCompleted('embeddings')`. |
| **HandleModelTranslationListener** | Listens to `TranslatedModelSaved`; dispatches `TranslateModelJob`. |
| **TranslateModelJob** | Auto-translates translatable models when configured. |
| **CrossEncoderService** | Optional `IReranker` binding for search result reranking. |
| **SearchOrchestratorAgent** | Optional `ISearchPlanner` binding for multi-step search plans. |
| **LlmQueryIntentParser** | Optional `IQueryIntentParser` binding. |
| **SearchEmbedder** | Optional `ITextEmbedder` binding for query/document embeddings in Core search. |

## Moderation (approval workflow)

| Term | Meaning |
|------|---------|
| **ModerationService** | LLM analysis of pending `Modification` records; returns structured verdict. |
| **ApproveModificationJob** | Applies AI vote on a `Modification` as the configured system user. |
| **HandleModificationModerationListener** | Entry listener on `ModificationRequiresModeration`. |
| **ModerationContextBuilderRegistry** | Core registry; AI resolves context without importing domain modules. |
| **ModerationResult** | Structured approve / reject / uncertain outcome from `ModerationService`. |
| **ModerationApprovalMode** | Policy enum: threshold, dual, uncertain-fallback variants. |
| **ai.features.moderation.entities.{table}** | Per-entity AI moderation switch, declared by the AI module for each model with a registered moderation adapter. |

## Contextual suggestions

| Term | Meaning |
|------|---------|
| **ContextualSuggestion** | Stored suggestion tied to UI context for proactive assistant hints. |
| **SuggestionController** | HTTP layer for contextual suggestion endpoints. |

## LLM providers

| Term | Meaning |
|------|---------|
| **EmbeddingsProviderFactory** | Resolves embedding provider from `config('ai.*')`. |
| **Neuron AI** | The agent framework (`neuron-core/neuron-ai` v4) behind every model call: providers, tools, RAG, structured output. It replaced LLPhant, which is no longer a dependency. |

## Related reading

- `docs/ARCHITECTURE.md` — module layout and message flows
- `docs/DESIGN_DECISIONS.md` — why the answer is sent whole, governed writes, memory, internal use
- `docs/SEARCH_AND_TRANSLATION.md` — indexing and translation listeners
- `docs/MODERATION.md` — moderation pipeline and approval modes
- `Modules/Core/docs/EVENT_ORCHESTRATION.md` — Core event bus contracts

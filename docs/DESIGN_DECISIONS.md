# AI Module - Design Decisions

This document explains the reasoning behind key architectural decisions and answers common questions about the module design.

---

## Q: Why is the answer not streamed token by token?

Every message of the in-app assistant goes through `InAppAssistanceService::respond()`, and the answer is
checked by the output guardrails before anyone sees it (no secret, no internal detail, citations that point
at what was retrieved). A token stream would show text that the checks may still refuse. So the answer is
sent whole: `POST /app/crud/insert/ai/conversations/{id}/messages` returns it as JSON, and
`POST /app/ai/agent` streams the run's lifecycle events with the validated message as one event. The old
token stream route answers 422 (`in_app_streaming_unavailable`). The previous `ChatService` paths
(`sendMessage`, `sendMessageStream`, `sendMessageWithTools`) were removed.

---

## Q: How does the assistant change data, and who decides?

The assistant never changes data by itself. A write tool (`crud_create_*`, `crud_update_*`, `crud_delete_*`, `crud_bulk_update_*`, `crud_bulk_delete_*`) only stores a **write proposal** (`ai_write_proposals`) and tells the model that nothing has changed. The person confirms or rejects the proposal through an authenticated action the model cannot perform, and only then is the stored payload applied, as that person, through `CrudService`. Whether the write is also sent for a vote is decided by Core approvals at the model (`HasApprovals`), not by the assistant.

**Why the person confirms outside the model.** The assistant is stateless (each `respond()` is one message, no history), so a model cannot carry a confirmation from one turn to the next, and a confirmation the model reads in a message can be influenced by text it retrieved. A proposal is applied only by an authenticated HTTP action of the person it was proposed to. The model cannot perform it, and neither can text it read.

**Why there is no risk level.** An earlier design classified tools by name (`delete_*` high, `update_*` medium, the rest low) and routed them through `ActionRequest`. It had no production caller, read no argument and defaulted to low, and it duplicated the approvals that Core enforces at the model for every surface. It was removed. Whether a write needs a vote is `wouldRequireApproval()` of the model; whether the person is asked is always yes.

**Privileged users.** A superadmin or an `approve` holder writes without a vote everywhere, so the assistant does the same for them once they confirm. What replaces the missing review is the protocol around the assistant: the acting user and their permissions are stated to the model and to the client, the assistant is confined to the application and refuses attempts to change its rules, and no write happens without the person's own confirmation.

**Entities without approvals.** The assistant may write to one only if the operator listed it under `ai.features.tools.crud.unmoderated_writes`: "applied directly, no vote".

**Pending proposals and messages.** A proposal is its own row, not a message; messages carry it in `metadata.writes`. Messages keep flowing while a proposal waits; it expires (`ai.features.tools.crud.proposal_ttl_minutes`, 30 by default) and a client sees `expired` if the person returns too late.

---

## Q: How does the Memory/Summarization system work?

`MemoryService` summarises a conversation after a number of messages, extracts its key facts (a Neuron
structured output) and keeps snapshots in `ConversationSummary`, to keep long conversations inside the
context window. It is kept for the persistent user memory plan, which reuses it. The in-app assistant does
not call it today: the assistant is stateless and replays no history until a security review accepts it.

- Switch: the setting `features.chat.summary.enabled` in Filament (seeded off).
- Threshold: `ai.features.chat.summary_threshold` in `config.php` (20 messages), a fixed value.
- Per conversation: `$conversation->memory_enabled`; `MemoryService::setMemoryEnabled($conversation, false)` also clears the existing summary.

---

## Q: Can I use the AI module without exposing public APIs?

### Yes - Internal Use Only

The module is designed for:

1. **Event-driven integration** - Embeddings, translations triggered by model events
2. **Job processing** - Background AI tasks
3. **Internal services** - Build a `ChatAgent` for a feature in your code

### Example: Internal AI Service

```php
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Enums\AiModelFeature;

final class MyService
{
    public function analyzeContent(Content $content): string
    {
        return ChatAgent::forFeature(AiModelFeature::TextGeneration, 'You are a content analyzer...')
            ->ask("Analyze this content: {$content->body}");
    }
}
```

`ChatAgent::forFeature()` builds the agent on the provider and model of that feature's setting; `ask()` is
a one-shot plain text call, and `structured()` (Neuron) returns a validated object.

No HTTP endpoint needed - use services directly.

---

## Summary: Feature Status

| Component | Status | Notes |
|-----------|--------|-------|
| In-app assistant | Active | JSON answer or event stream, answer sent whole |
| Governed writes | Active | Writes are proposals the person confirms |
| Embedding system | Active | Powers vector search; setting `features.embeddings.enabled` |
| Translation system | Active | Automatic translations; setting `features.translation.enabled` |
| In-app assistant | Active | One switch, setting `features.chat.enabled` (seeded off), enforced by `EnsureAssistantEnabled` on every assistant route; apart from the documentation, so an assistant may serve its tools only |
| RAG/FAQ | Active | Documentation answers; setting `features.faq.enabled` |
| Contextual suggestions | Active | Proactive AI suggestions with rate limiting; setting `features.contextual_suggestions.enabled` |
| Memory/Summary | Kept, not called | Setting `features.chat.summary.enabled`; for the persistent user memory plan |
| Guardrails | Active | In-app assistance guardrails, mandatory, no switch |

### Key Design Principles

1. **Event-Driven Integration** - AI module listens to Core events, Core never imports AI
2. **Privacy-First Default** - Ollama as default provider (local processing)
3. **Human-in-the-Loop** - The assistant only proposes writes; the person confirms each one outside the model
4. **Settings, not env** - Feature switches and tuning are settings in Filament > Settings; env holds provider keys and URLs
5. **Graceful Degradation** - App works normally when AI module is disabled

# Adaptive assistance: wire contract, proposals and stored data — developer guide

## Purpose and rules

The contract lets any client offer an experience that adapts to its user without the application changing by
itself. It covers where a client keeps preferences, how it tells the assistant where the user is, and how the
assistant proposes a change that the user accepts or refuses. It is public and client-neutral: the backend
knows no frontend by name, and a client's own meaning lives in a namespace that the client owns.

Rules that every part below keeps:

- **The assistant proposes, the client applies.** The server never applies a proposal and keeps no record of
  its acceptance or refusal.
- **No telemetry.** Nothing is recorded in the background. The instance stores what a user's own request or
  setting creates (see *Stored data*).
- **Authorization never widens.** Preferences, page context and proposals are untrusted input. They narrow or
  reorder what a user already sees; they never grant a field, a record, a tool or a permission.
- **A proposal is data**: a target and a value. Never code, markup, a link to follow or a tool call.

## Preferences

Stored in `users.preferences`, written through `PATCH`/`DELETE /app/auth/user/preferences`, as namespaces with
generic limits. See `Modules/Core/docs/rag/USER_PREFERENCES_DEVELOPER.md`.

## Page context

A client sends `context.page` with each message, to the base transport or to the stream endpoint:

```json
{
  "resource": "erp/orders",
  "recordKey": 42,
  "locale": "it",
  "list": { "filters": {}, "columns": [], "viewMode": "table" },
  "dashboard": { "layout": [] },
  "proposable": []
}
```

`ResolveAssistantApplicationContext` is the only writer of the request attribute
`assistant_application_context`. It reads `resource` (`module/entity`, lower case) and `recordKey`, resolves
the pair with Core's model lookup, and keeps it only when the model is registered in that module and the user
holds `select` on it. Anything else leaves the assistant generic. A `context.assistant_application_context`
sent by a client is ignored. Details and the effect on retrieval: `ASSISTANT_SCOPE.md`.

`list`, `dashboard` and the rest of the page are hints. They are not read by the server, and none of them
changes the policy, the tools or the prompt. The control-plane check of the message request applies to the
whole `context`: a key such as `profile`, `tools`, `permissions`, `roles`, `tenant_id`, `user_id` or
`system_prompt` anywhere in it answers 422.

## Capabilities

`GET /app/ai/capabilities`, for a signed-in user (401 when not signed in, 403 for the guest account):

```json
{ "data": { "enabled": true, "configured": true, "features": { "help": true, "proposals": true, "streaming": true } } }
```

| Field | Meaning |
|---|---|
| `enabled` | The setting `features.chat.enabled`, the assistant's one global switch. Seeded off: an instance has no assistant until an operator turns it on. |
| `configured` | The chat provider chosen in Settings has what it needs (a key or a URL). |
| `features.help` | The setting `features.faq.enabled`: the assistant answers from the user documentation. With it off the assistant still serves what its tools reach, reads no documentation and is not told to cite it. A client hides the help sources and the "help" group of its search. |
| `features.proposals` | The policy compiled for the in-app profile with `ui_proposals` still allows a tool. |
| `features.streaming` | `POST /app/ai/agent` exists. |

A client that sees `proposals: false` hides the proposal UI and sends no `proposable` list.

## Proposable targets

A client declares what it is willing to have proposed in `context.page.proposable`, a list of entries:

```json
{
  "kind": "preference",
  "target": { "namespace": "ui", "key": "defaultListLayout" },
  "schema": { "type": "string", "enum": ["table", "cards"] },
  "current": "table",
  "description": "Default layout of lists"
}
```

`kind` is `preference` (target: `namespace` and `key`) or `view_state` (target: `resource` as `module/entity`
and `view`). An entry that does not pass its bounds is dropped, never repaired: at most 30 entries, a target
that matches its patterns, a schema of at most 2000 bytes and 4 levels, a `current` of at most 500 bytes, a
`description` of at most 120 characters on one line.

The `schema` is the JSON Schema subset of `ProposalSchema`: `type`, `enum`, `const`, `minimum`, `maximum`,
`exclusiveMinimum`, `exclusiveMaximum`, `minLength`, `maxLength`, `minItems`, `maxItems`, `items`,
`properties`, `required` and `additionalProperties` as a boolean; `title`, `description`, `default`,
`examples` and `$comment` are ignored. It fails closed: any other keyword (`pattern`, `$ref`, `oneOf`,
`format`, ...) or a schema that constrains nothing makes the target not proposable. A client's regular
expression never runs on the server.

## Proposals

The assistant has two tools, `propose_preference_change` and `propose_view_state`, in the capability
`ui_proposals`, granted to the in-app profile only. A request has them only when the compiled policy allows
them and the page declared a target of that kind. The model gives the target, the proposed value as JSON text
and a reason. `UiProposalCollector` checks each call as untrusted input:

- the target is one the page declared;
- the value satisfies the schema the page declared for it;
- the reason is plain text of at most 240 characters (no markup, link or control character) and passes the
  output guardrails;
- a message holds at most three proposals and one for each target.

What the model is told is that the proposal waits for the user and that it must never say that it was
applied. The proposals of a message are in `message.metadata.proposals`:

```json
{
  "id": "uuid",
  "schemaVersion": 1,
  "kind": "preference",
  "target": { "namespace": "ui", "key": "defaultListLayout" },
  "current": "table",
  "proposed": "cards",
  "reason": "You open this list on a phone most of the time."
}
```

An answer that claims a pending proposal was applied ("I have updated", "has been applied", "ho modificato",
"è stato salvato") is replaced by a plain statement that the suggestion waits
(`AssistanceOutputPolicy::reportPendingProposals()`).

## Transport

**Base transport.** `POST /app/crud/insert/ai/conversations/{conversation}/messages`. The profile validates
the complete output, then delivers it. Proposals and citations come in `message.metadata`.

**Stream endpoint.** `POST /app/ai/agent`, a wrapper of the base transport: it runs the same
`InAppAssistanceService::respond()`, so the profile, the policy, the guardrails and the proposals are the
same, and it cannot select or imitate another profile. The body is `{ "threadId": <conversation id>,
"message": "...", "context": {...} }`. The conversation must be the user's own (403 otherwise), must exist
(404), and the input is validated as for the base transport (422 as JSON, whatever `Accept` the client sent).
With the assistant off the answer is a 403 `{"code": "FEATURE_DISABLED"}` before anything streams. The middleware `EnsureAssistantEnabled` gives the same answer on every assistant route: the conversations (list, create, detail, delete), their messages (list, send, stream), the agent, and the confirmation or rejection of a proposed write. A request that is not signed in passes the gate and is answered 401 by the controller, so the gate tells a stranger nothing. `GET /app/ai/capabilities` is not behind it: a client reads it to learn that the assistant is off. A client
that is not signed in gets 401.

The response is `text/event-stream` (Laravel's `response()->eventStream()`), one event per line pair, named by
its AG-UI `type`, with the event as JSON in `data`:

| Event | When | Carries |
|---|---|---|
| `RunStarted` | first | `threadId`, `runId` |
| `StepStarted`, `StepFinished` | around each step | `stepName`: `retrieve`, `answer`, `validate`, fixed by the server |
| `TextMessageStart`, `TextMessageContent`, `TextMessageEnd` | once the output is validated | `messageId`; `delta` is the **whole** message |
| `StateDelta` | when there are citations | `delta.citations` |
| `ToolCall` | one per proposal | `toolCallId` is the proposal id, `toolCallName`, `args` is the proposal |
| `RunFinished` | last, on success | `outcome`: `success`, or `interrupt` with one interrupt per proposal (`reason: confirm_proposal`) |
| `RunError` | last, on refusal or failure | `code`: `POLICY_DENIED` or `PROVIDER_ERROR`, a fixed `message` |

No event carries a token of the model: the protected profile validates the complete output before it
delivers any of it. A refusal sends the stored generic refusal message and then `RunError`; the code is
coarse and is not stored, so the message in the conversation still carries only `refused`. The interrupt is
the outcome of the run for the client; nothing is paused on the server.

## Conversation title

After the first answer that is not a refusal, when the conversation has no title, `respond()` queues
`GenerateConversationTitleJob`. The answer is returned without waiting; clients read `title` the next time
they list or load the conversation.

The model is asked for a Neuron structured output, `GeneratedConversationTitle`: a title of 2 to 5 words, at
most 40 characters, plain text with no quote, markdown, line break or ending punctuation. Neuron validates
it and asks again once with the list of what was wrong. A title that never passes, a provider that fails, or
output the guardrails refuse, ends the same way: the title is the first words of the question, cut at a word
boundary to 40 characters. The model runs on the model chosen in Settings for the chat summaries, capped to
60 output tokens, under the capability `conversation_title` (no tools, no corpora), and reads only the first
question and the answer to it, never citations, tool output, page context or the system message. The title is
written only when it is still null, so a title sent at creation is never overwritten and a generated one is
not regenerated. The log records the conversation id, whether the title was generated, and its length, never
the text.

## Stored data

| Data | Rule |
|---|---|
| `users.preferences` | The user's. Read, reset and deleted through the preferences routes. |
| `ai_contextual_suggestions.context` | Keeps only `page` and `action` (text, 255 characters); the `data` a client sends helps generate the suggestion and is never stored. Rows older than 7 days (`ContextualSuggestion::RETENTION_DAYS`) are pruned daily by `model:prune`. |
| Conversations, messages (with citations and proposals), summaries, titles | Removed with the conversation. |
| A deleted user's conversations and suggestions | Removed with the user. |

Users and conversations are soft deleted by default, and a soft delete never reaches a foreign key, so
observers do the work: `PurgeDeletedConversationObserver` calls `Conversation::purgeContent()` (messages and
summaries are deleted for good; the title, summary, system message and metadata of the row are emptied) and
`PurgeAssistantDataOfDeletedUserObserver` removes the suggestions and conversations of a deleted user. The
approval requests of the action flow are kept: they record who asked for what and who decided. Conversations
deleted before the purge existed keep their content until their user is deleted.

## Neuron

- Chat runs on `ChatAgent` (a Neuron `Agent`) built by `ChatService::buildProtectedAgent()`; the proposal
  tools are Neuron tools added to it.
- The title is a structured output with `SchemaProperty` and validation attributes (`NotBlank`, `Length`,
  `WordsCount`, `Regex`); there is no cleaning code of ours.
- `ProviderFactory::make()` and `ChatAgent::forFeature()` take `maxOutputTokens` and pass it to each provider
  under its own parameter name (`max_completion_tokens` OpenAI, `max_tokens` Mistral, `options.num_predict`
  Ollama, `max_tokens` Anthropic).
- Tests use Neuron's `FakeAIProvider`, which records the system prompt, the tools and the messages a model
  was given; `tests/Stubs/Assistance/ToolCallingFakeProvider.php` adds model tool calls built from the tools the
  agent really configured.

## Testing and evaluation

`UserPreferencesTest` (Core); in `Modules/AI/tests/Feature/Assistance/`: `ResolveAssistantApplicationContextTest`,
`CapabilitiesEndpointTest`, `UiProposalsTest`, `AgentEndpointTest`, `ConversationTitleTest`; `StoredDataPrivacyTest`
in `Modules/AI/tests/Feature/`. The evaluation cases are in `Modules/AI/docs/rag/evaluations/assistant-proposals.json`
and described in `ASSISTANT_EVALUATION.md`. Level 1 scripts the model, so it proves the plumbing and the guard,
not that a real model proposes when it should.

## Errors and troubleshooting

| Symptom | Check |
|---|---|
| `proposals` is false | The catalog lacks `ui_proposals`, or the in-app profile does not grant it. |
| The assistant never proposes | The page sent no `proposable` list, an entry broke its bounds (it is dropped), or its schema uses a keyword outside the subset. |
| A proposal is refused | The target is not declared, the value breaks the declared schema, or the reason is not plain text of 240 characters at most. |
| A message says "suggestion" instead of what the model wrote | It claimed a pending proposal was applied and was replaced. |
| `POST /app/ai/agent` answers 401, 403 or 422 as JSON | 401: not signed in. 403 with `FEATURE_DISABLED`: the assistant is off (`features.chat.enabled`). 403 without a code: the conversation is not the user's own. 422: the input broke the message request, for example a control field in `context`. |
| A conversation has no title | No non-refused answer yet, or the queue is not running. |
| `model:prune` leaves old suggestions | The scheduler is not running (`model:prune` for `ContextualSuggestion` is scheduled daily). |

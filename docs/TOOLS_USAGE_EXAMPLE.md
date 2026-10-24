# Assistant writes: propose, then the person confirms

> **Status.** This replaces the `sendMessageWithTools` / `ActionRequest` example. That flow was removed on
> 2026-10-07: nothing used it, and approval is enforced at the model by Core. What follows is the flow that
> is live. A message is sent to `POST /app/crud/insert/ai/conversations/{conversation}/messages` (or
> `POST /app/ai/agent`), which answers a single message; the confirmation is a separate, authenticated action
> of the person. The `messages-with-tools` route was removed on 2026-10-07.

## Why two steps, and why the second is not the model's

The in-app assistant is stateless: each message is answered on its own, with no history. A model therefore
cannot carry "yes, apply it" from one message to the next, and a "yes" read inside a message can be steered by
text the model retrieved. The assistant only **proposes**; the person **confirms through the application**,
an action the model cannot perform.

```mermaid
sequenceDiagram
    participant P as Person
    participant A as Assistant
    participant S as Application
    P->>A: message
    Note over A: model calls crud_update_core_role, stores a proposal, changes nothing
    A-->>P: message + metadata.writes
    Note over P: sees what would change, as whom, whether it then goes to a vote
    alt confirm
        P->>S: POST /app/crud/update/ai/assistant-writes/{id}/confirm
        S-->>P: applied, pending_approval or failed
    else reject
        P->>S: POST /app/crud/update/ai/assistant-writes/{id}/reject
        S-->>P: rejected
    end
```

## What the client receives

```jsonc
// POST /app/crud/insert/ai/conversations/12/messages  → 201
{
  "data": {
    "id": 90,
    "role": "assistant",
    "content": "I propose renaming the role from \"editor\" to \"reviewer\".\n\nI prepared a change for you to confirm. Nothing changes until you confirm it.",
    "metadata": {
      "citations": [],
      "writes": [
        {
          "id": 31,
          "tool": "crud_update_core_role",
          "module": "core",
          "entity": "role",
          "operation": "update",
          "status": "proposed",
          "acting_user_id": 5,
          "acting_user_name": "Maria Rossi",
          "summary": { "record_id": "4", "changes": { "name": { "from": "editor", "to": "reviewer" } } },
          "requires_approval": false,
          "outcome": null,
          "expires_at": "2026-10-07T10:30:00+00:00",
          "resolved_at": null
        }
      ]
    },
    "created_at": "2026-10-07T10:00:00+00:00"
  }
}
```

Show each entry of `metadata.writes` as a confirmation card: the acting user, the entity and operation, the
`summary`, and a note when `requires_approval` is true ("after you confirm, this is sent for approval").
`summary` is bounded: a single change shows each attribute as `from` and `to`; a bulk change shows
`matched_records`, `sample_ids` and the `changes`, and is applied to exactly those records.

## Confirming and rejecting

```bash
curl -X POST "https://yourapp.com/app/crud/update/ai/assistant-writes/31/confirm" \
  -H "Authorization: Bearer YOUR_TOKEN" -H "Accept: application/json"
```

```jsonc
// 200 — the proposal after the action
{ "data": { "id": 31, "status": "applied", "outcome": { "record": { "id": 4, "name": "reviewer" } }, "resolved_at": "..." } }
```

| `status` | Meaning | HTTP |
|---|---|---|
| `applied` | The change was made. | 200 |
| `pending_approval` | Core captured the write for a vote; `outcome` holds the modification ids. Nothing has taken effect yet. | 200 |
| `failed` | Refused at the moment of applying: the person no longer holds the permission, the operator no longer offers the operation, or the write failed. `outcome.error` says it in a sentence. | 200 |
| `rejected` | The person declined, or confirmed a rejected proposal. | 200 on reject, 409 on a later confirm |
| `expired` | Not confirmed within `ai.features.tools.crud.proposal_ttl_minutes` (30). | 409 |

Confirming twice is safe: the second call answers the same outcome and writes nothing. Only the person the
proposal belongs to, in their own conversation, can see, confirm or reject it (403 otherwise).

`GET /app/crud/detail/ai/assistant-writes/{id}` returns the same body, for a client that wants to show the
state again.

## What the assistant may do for the signed-in person

`GET /app/ai/capabilities` returns, beside `enabled`, `configured` and `features` (`features.writes` is true
when the operator opted an entity into a write), the list of what the assistant may do for **this** person:

```json
"actions": [
  { "entity": "core.role", "operation": "list",   "kind": "read",  "requires_approval": false },
  { "entity": "core.role", "operation": "update", "kind": "write", "requires_approval": false }
]
```

A client should show it (a settings panel, a hint under the input) so the person knows what they can ask the
assistant to do. The assistant is told the same list, and told to refuse anything outside it.

## Operator configuration

| Key | Effect |
|---|---|
| `ai.features.tools.crud.entities` | Opt-in per entity: `'cms.content' => ['list', 'detail', 'create', 'update']`. Empty means no entity tool. |
| `ai.features.tools.crud.unmoderated_writes` | Entities without approvals on which the assistant may also write: "applied directly, no vote". Entities with approvals need no entry. |
| `ai.features.tools.crud.proposal_ttl_minutes` | How long a proposal can be confirmed. |

`approve` and `disapprove` are never offered to the assistant, whatever the entity list says.

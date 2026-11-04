# Assistant widget objects: the `show_widget` tool — developer guide

## Purpose

The in-app assistant can put a **figure** (`kpi`) or a **chart** (`chart`) in its answer. An object holds the
aggregate request that computes its data, never the data: the backend validates the request, runs it once so the
model can say what the object shows, and stores it in the message. A client runs the request again with the
session of the user and renders the result. The backend emits the objects; drawing them is the client's job.

Rules every part below keeps:

- **No figure is written by the model.** The object carries the validated request; the figures come from the
  aggregate route when the client runs it, under the permission and row-level ACL of the user at that moment.
- **The page narrows, it never widens.** What the page declares it can render limits what the assistant may show;
  it never grants a tool, an entity or a column.
- **A refusal is a sentence for the model**, never an exception and never an object.

The aggregate request, its result, its limits and its errors are described in
`Modules/Core/docs/CRUD_SYSTEM.md`, section *Aggregate Route*.

## When the tool is offered

`InAppAssistanceService::respond()` compiles the in-app policy with the capability `widget_objects` among the
others, and builds `show_widget` for the turn only when all three hold:

1. the compiled policy allows `show_widget` (capability `widget_objects`, granted to the in-app profile only);
2. the page declared at least one object type it renders in `context.page.objects`;
3. `ai.features.tools.crud.entities` lists `summarize` for at least one entity, which is the operator's opt-in to
   figures and charts.

Without the three the model has no widget tool at all. Which entity a given person may show figures of is decided
on each call (see *What a call is checked against*).

### `context.page.objects`

```json
{ "page": { "resource": "shop/orders", "objects": ["kpi", "chart"] } }
```

A list of types: `kpi`, `chart` and `graph` are known. `RenderableObjectTypes::fromPageContext()` is the only reader:
it reads the first three entries, keeps each known type once and drops anything else, never repairing it. A value
that is not a list declares nothing. `graph` is a known type that the tool does not show yet (see *Graph objects*).
The key passes the same control-plane check as the rest of `context`.

## The tool

`ShowWidgetTool` (`NAME = 'show_widget'`, risk level `low`) hands every argument to `WidgetObjectCollector::show()`.

| Parameter | Type | Meaning |
|---|---|---|
| `type` | `kpi` or `chart` | A kpi shows one figure, or its trend over time buckets; a chart shows figures by group or over time. |
| `title` | text, at most 80 characters | Plain text in the language of the user, without markup or links. |
| `request` | JSON text, at most 2000 characters | The aggregate request of the route plus `resource` (`module/entity`, lower case): `measures`, `group_by`, `period`, `filters`, `sort`, `limit`, `other`, `compare`. |
| `variant` | `bar`, `line`, `donut`, `ranking` | Required for a chart, refused for a kpi. |
| `format` | JSON text, at most 200 characters | Optional: `{style: number\|currency\|percent, currency?: three capital letters, max_fraction_digits?: 0 to 4, compact?: true\|false}` and no other key. |

The model may call it `max_objects_per_message` + 2 times in a turn (8 by default), so it can correct a refused
request.

### What a call is checked against

In this order, each with its own refusal. Every refusal reads `Not shown: <why>. Nothing was recorded.`:

| Check | `<why>` |
|---|---|
| The type is one the page declared | `the page does not show objects of that type` |
| The type is one the tool shows | `graph objects are not available yet` |
| The title is plain text of 1 to 80 characters (no control character, `<`, `>`, backtick or link) that passes the output guardrails | `the title must be plain text of 1 to 80 characters, without markup or links` |
| The request is JSON text of an object whose `resource` is `module/entity` | `the request must be JSON text of an object with a resource written as module/entity` |
| `CrudToolProvider::offeredOperations()` lists `summarize` on that entity for the acting person (opted in, and the person holds `select`) | `the assistant may not show figures of that resource` |
| The message holds fewer than `max_objects_per_message` objects | `a message shows at most <max_objects_per_message> objects` (6 by default) |
| `AggregateQuery::fromArray()`, the validator of the route, accepts the request | `the request was refused with <CODE> on <field>: <message>` |
| The filters are a list of filter nodes that the rules of the route accept | `the filters must be a list of filter objects`, `the filters of a node must themselves be a list of filter objects`, or `the filters were refused: <first message of the validator>` |
| A kpi has no column group and no variant | `a kpi shows one figure: leave group_by out, or group by a time bucket for its trend`, `a kpi takes no variant` |
| A chart has a variant and a `group_by`; a `line` groups by a time bucket; a `donut` has a `limit` of at most 8 | `a chart needs a variant, one of bar, line, donut, ranking`, `a chart needs a group_by`, `a line chart needs a group_by with a time bucket`, `a donut chart needs a limit of at most 8 groups` |
| The format is in the closed list | `the format must be JSON text of {style: number, currency or percent, currency?: three capital letters, max_fraction_digits?: 0 to 4, compact?: true or false} and nothing else` |
| No object of the message shows the same type, variant and request (two spellings of one request are the same request) | `an object showing the same request is already in this message` |

The request then runs once through `CrudToolProvider::aggregate()` and `CrudService::aggregate()`, with the filters
exactly as they were validated. An `AggregateException` is answered with its code, field and message (and reported
when it is a deployment fault); a refused permission with its own message; anything else is reported and answered
`the figures could not be computed`, since the message of a database error holds SQL.

### What the model is told

On success: `Widget recorded.`, a reminder never to state a figure it was not given, and compact JSON of the
`total`, the first five entries of the series (`key`, `label`, `values`), `other` when some groups were left out and
the `warnings` (for example `MIXED_CURRENCY`). When that text reads like an instruction the figures are withheld from
the model, the object is still recorded, and `Assistant tool result withheld` is logged as for the read tools.

Each recorded object is logged as `Assistant widget recorded` with its type, its resource and its canonical request;
the figures are never logged.

## The object in the message

The assistant message carries the objects in `message.metadata.objects`, after `citations`, `proposals` and
`writes`: `{ citations, proposals?, writes?, objects? }`. A message with no object has no `objects` key, and a refused
turn keeps only `{ "refused": true }`, whatever the tool recorded before the refusal.

```json
{
  "id": "0b1f6c1e-6a52-4a39-9b55-1e0f2a9c7d10",
  "type": "chart",
  "title": "Orders by status",
  "ref": {
    "request": {
      "kind": "aggregate",
      "resource": "shop/orders",
      "measures": [{ "fn": "count" }],
      "group_by": { "field": "status" },
      "sort": { "by": "measure", "dir": "desc" },
      "limit": 5,
      "other": true,
      "filters": [{ "property": "currency", "operator": "=", "value": "EUR" }]
    },
    "variant": "donut",
    "format": { "style": "number", "compact": true }
  }
}
```

- `id` is a UUID, `type` is `kpi` or `chart`, `title` is the validated title.
- `ref.request` is the validated request, not what the model wrote: `kind` (`aggregate`), `resource`, then the request
  as `AggregateQuery::toArray()` writes it (the `sort` that applies is always written, and a `limit` written as text
  is a number), then `filters` when there are some, keeping only the words of the filter vocabulary at every depth.
  A key the model adds anywhere else is dropped.
- `ref.variant` is present for a chart, `ref.format` when a format was given. A kpi has neither a variant nor a
  `delta`.

**Running it.** A client sends `ref.request` without `kind` and `resource` to
`POST /app/crud/aggregate/{module}/{entity}`, the module and entity being the two halves of `resource`. The route
reads the stored request unchanged and applies the permission, the ACL and the limits again.

**Where a client reads it.** The objects travel with the stored message wherever it is returned:

| Route | Where |
|---|---|
| `POST /app/crud/insert/ai/conversations/{conversation}/messages` | `data.metadata.objects` of the answer |
| `GET /app/crud/select/ai/conversations/{conversation}/messages` | `metadata.objects` of each assistant message |
| `GET /app/crud/detail/ai/conversations/{conversation}` | `metadata.objects` of each message in `data.messages` |
| `POST /app/ai/agent` | one `ToolCall` event per object (below) |

The objects are stored with the message and removed with it (`Conversation::purgeContent()`).

## The stream event

`POST /app/ai/agent` sends one event per object, after the `ToolCall` events of the proposals and before
`RunFinished` (`AgentEvent::object()`):

```json
{ "type": "ToolCall", "toolCallId": "<object id>", "toolCallName": "show_widget", "args": { "...": "the object" } }
```

An object does not interrupt the run: nothing waits for the user, so `RunFinished` keeps its outcome (`success`, or
`interrupt` with one interrupt per **proposal** only).

## Capabilities

`GET /app/ai/capabilities` publishes what a client needs before it declares any object type:

```json
{
  "data": {
    "enabled": true,
    "configured": true,
    "features": { "help": true, "proposals": true, "writes": false, "streaming": true, "objects": ["kpi", "chart"] },
    "limits": { "aggregate": { "max_measures": 3, "max_groups": 50, "max_points": 400, "max_period_days": 1096 } },
    "actions": []
  }
}
```

| Field | Meaning |
|---|---|
| `features.objects` | The types the backend can emit (`AssistantCapabilities::objects()`): `[]` when the policy compiled for the in-app profile with `widget_objects` allows no tool (the catalog lacks the capability, or the profile does not grant `show_widget`) or no entity in `ai.features.tools.crud.entities` lists `summarize`; otherwise `["kpi", "chart"]`. |
| `limits.aggregate` | The four ceilings of `core.aggregate.*` a client may plan with: `max_measures`, `max_groups`, `max_points`, `max_period_days`, as configured. Every limit is also a structured error of the route, so a client works without them. |

Like the rest of the endpoint, these depend on the instance, not on the person: which entities a person may
summarize is listed in `actions` (`operation: summarize`, `kind: read`). A client that reads an empty list, or a
response without `features.objects` (an older backend), sends no `context.page.objects` and gets answers in text only.

## What `summarize` does

`summarize` is the read tool the same opt-in enables, and it runs on the same aggregate engine:

- it covers every row the person may read that matches the filters, not a sample;
- it groups by one column, or cuts a date column into `day`, `week`, `month`, `quarter` or `year` buckets over a
  `period`; a second `group_by` column is refused;
- a column group returns its first `max_groups` (50) groups ranked by the first metric (by count when none is asked),
  and the figures of the rest under `other`; a bucketed group returns every bucket;
- `sum` and `avg` need a numeric column that is not a key;
- a figure that adds more than one currency is `null`, and the answer carries the `MIXED_CURRENCY` warning;
- a refusal of the engine comes back with its code and the place it is about, so the model can correct the call.

## Graph objects

`graph` is a type a page may already declare, and the tool refuses it (`graph objects are not available yet`). Graph
objects, built on the graph tools, are the next step of the plan that introduced widgets
(`docs/superpowers/plans/2026-10-20-aggregate-route-and-widget-tool.md`); until then `features.objects` never lists
`graph`.

## Code map

Paths are under `Modules/AI/app/`.

| Piece | Role |
|---|---|
| `Services/Tools/ShowWidgetTool` | The tool definition and its parameters. |
| `Services/Assistance/Widgets/WidgetObjectCollector` | Checks each call, runs the request once, keeps the objects of one message. `CAPABILITY = 'widget_objects'`. |
| `Services/Assistance/Widgets/RenderableObjectTypes` | Reads `context.page.objects`. |
| `Data/WidgetObject` | `{id, type, title, ref}`. |
| `Services/Assistance/InAppAssistanceService` | Builds the tool for the turn and writes `metadata.objects`. |
| `Services/Assistance/Stream/AgentEvent::object()`, `Services/AssistAgentService` | The stream event. |
| `Services/Assistance/AssistantCapabilities` | `WIDGETS_CAPABILITY`, `widgets()`, `summaries()`, `objects()`, `limits` in `toArray()`. |
| `Services/Assistance/Policies/AssistantPolicyCatalog` | The capability and its instruction to the model. |

## Testing

In `Modules/AI/tests/Feature/Assistance/`: `WidgetObjectsTest` (every check of a call, the stored object, the
audit log, withholding), `WidgetObjectsFlowTest` (when the tool is offered, the metadata, the refusal path, the chat
routes), `AgentEndpointTest` (the stream event), `CapabilitiesEndpointTest` (`features.objects`, `limits.aggregate`).
`summarize` is covered by `Modules/AI/tests/Feature/Tools/CrudToolProviderTest.php`.

## Errors and troubleshooting

| Symptom | Check |
|---|---|
| `features.objects` is empty | The catalog lacks `widget_objects`, the in-app profile does not grant `show_widget`, or no entity lists `summarize` in `ai.features.tools.crud.entities`. |
| The model never shows an object | The page sent no `objects` list, or only unknown types, or the person holds no `select` on any entity opted into `summarize` (every call is then refused). |
| An object is refused | The answer to the model says why: see the table of checks. |
| The figures the client shows differ from what the model said | The client runs the request later, with the permission and ACL of that moment, and the data may have changed. |

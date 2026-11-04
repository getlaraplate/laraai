# Assistant widget objects and the `show_widget` tool: developer guide

## Purpose

The in-app assistant can put a **figure** (`kpi`), a **chart** (`chart`) or the **relation map of a record**
(`graph`) in its answer. An object holds a title and the request that computes it, not its figures or records: the
backend validates the request, runs it once so the model can say what the object shows, and stores it in the message.
A client runs the request again with the session of the user and renders the result. The backend emits the objects;
drawing them is the client's job.

Rules every part below keeps:

- **The figures come from the application.** The object carries the validated request: the figures, the totals,
  the series and the comparison come from the aggregate route, and the nodes and edges of a map from the graph
  route, when the client runs it, under the permission and row-level ACL of the user at that moment. The assistant
  writes the request and the title. The title is plain text of at most 80 characters that the assistant chooses, so a
  reader should not take a number in a title as a computed figure.
- **The page narrows, it never widens.** What the page declares it can render limits what the assistant may show;
  it never grants a tool, an entity or a column.
- **A refusal is a sentence for the model**, never an exception and never an object.

The aggregate request, its result, its limits and its errors are described in
`Modules/Core/docs/CRUD_SYSTEM.md`, section *Aggregate Route*; the graph routes, their parameters and their
`graphMeta` in `Modules/Core/docs/GRAPH_SYSTEM.md`.

## When the tool is offered

`InAppAssistanceService::respond()` compiles the in-app policy with the capabilities `widget_objects` and
`read_only_graph` among the others, and builds `show_widget` for the turn only when:

1. the assistant scope reaches data (a scope with no data access gets no entity tool and no widget tool);
2. the compiled policy allows `show_widget` (capability `widget_objects`, granted to the in-app profile only);
3. the page declared, in `context.page.objects`, at least one object type that the tool can show in this run, with
   its own condition:
   - `kpi` or `chart`: `ai.features.tools.crud.entities` lists `summarize` for at least one entity (the operator's
     opt-in to figures and charts), and `CrudToolProvider::offeredOperations()` offers `summarize` to the acting
     person on at least one of them (the entity is opted in and the person holds `select` on it);
   - `graph`: the compiled policy allows `graph_expand` (capability `read_only_graph`). No entity needs to offer
     `summarize`: the graph gateway of Core applies the permission and the row-level ACL of each record itself.

Without them the model has no widget tool at all. A page that declares only `graph` gets the tool when `graph_expand`
is allowed, and none when it is not; a page that declares `kpi` or `chart` keeps the conditions above, whatever the
graph. Which entity or record a given call may show is checked again on each call (see *What a call is checked
against*).

### `context.page.objects`

```json
{ "page": { "resource": "shop/orders", "objects": ["kpi", "chart"] } }
```

A list of types: `kpi`, `chart` and `graph` are known. `RenderableObjectTypes::fromPageContext()` is the only reader:
it reads the first three entries, keeps each known type once and drops anything else, never repairing it. A value
that is not a list declares nothing.
The key passes the same control-plane check as the rest of `context`.

## The tool

`ShowWidgetTool` (`NAME = 'show_widget'`, risk level `low`) hands every argument to `WidgetObjectCollector::show()`.

| Parameter | Type | Meaning |
|---|---|---|
| `type` | `kpi`, `chart`, and `graph` in a run whose policy allows `graph_expand` | A kpi shows one figure, or its trend over time buckets; a chart shows figures by group or over time; a graph shows the relation map of one record. |
| `title` | text, at most 80 characters | Plain text in the language of the user, without markup or links. |
| `request` | JSON text, at most 2000 characters | For a kpi or a chart, the aggregate request of the route plus `resource` (`module/entity`, lower case): `measures`, `group_by`, `period`, `filters`, `sort`, `limit`, `other`, `compare`. For a graph, the graph request (see *Graph objects*). |
| `variant` | `bar`, `line`, `donut`, `ranking` | Required for a chart, refused for a kpi and a graph. |
| `format` | JSON text, at most 200 characters | Optional for a kpi or a chart: `{style: number\|currency\|percent, currency?: three capital letters, max_fraction_digits?: 0 to 4, compact?: true\|false}` and no other key. Refused for a graph. |

The `type` enum and the description of `request` mention the graph only in a run whose policy allows `graph_expand`
(`WidgetObjectCollector::types()`), so the model is never offered a type it cannot use. The model may call the tool
`max_objects_per_message` + 2 times in a turn (8 by default), so it can correct a refused request.

### What a call is checked against

In this order, each with its own refusal. Every refusal reads `Not shown: <why>. Nothing was recorded.`:

| Check | `<why>` |
|---|---|
| The type is one the page declared | `the page does not show objects of that type` |
| The type is one the tool shows in this run (a graph needs `graph_expand`) | `the assistant may not show graph objects` |
| The title is plain text of 1 to 80 characters (no control character, `<`, `>`, backtick or link) that passes the output guardrails | `the title must be plain text of 1 to 80 characters, without markup or links` |
| The request is JSON text of an object whose `resource` is `module/entity` | `the request must be JSON text of an object with a resource written as module/entity` |

A graph goes on with its own checks (see *Graph objects*). A kpi or a chart goes on with these:

| Check | `<why>` |
|---|---|
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

For a kpi or a chart (a graph: see *Graph objects*), on success: `Widget recorded.`, a reminder never to state a
figure it was not given, and compact JSON of the `total`, the first five entries of the series (`key`, `label`, `values`), `other` when some groups were left out and
the `warnings` (for example `MIXED_CURRENCY`). When that text reads like an instruction the figures are withheld from
the model, the object is still recorded, and `Assistant tool result withheld` is logged as for the read tools.

Each recorded object is logged as `Assistant widget recorded` with its type, its resource and its canonical request;
the figures, the nodes and the labels are never logged.

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

- `id` is a UUID, `type` is `kpi`, `chart` or `graph`, `title` is the validated title.
- `ref.request` of a kpi or a chart is the validated request, not what the model wrote: `kind` (`aggregate`), `resource`, then the request
  as `AggregateQuery::toArray()` writes it (the `sort` that applies is always written, and a `limit` written as text
  is a number), then `filters` when there are some, keeping only the words of the filter vocabulary at every depth.
  A key the model adds anywhere else is dropped.
- `ref.variant` is present for a chart, `ref.format` when a format was given. A kpi has neither a variant nor a
  `delta`. A graph has only `ref.request` (see *Graph objects*).

**Running it.** A client sends `ref.request` of a kpi or a chart without `kind` and `resource` to
`POST /app/crud/aggregate/{module}/{entity}`, the module and entity being the two halves of `resource`. The route
reads the stored request unchanged and applies the permission, the ACL and the limits again. A graph runs on the
graph route (see *Graph objects*).

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
    "features": { "help": true, "proposals": true, "writes": false, "streaming": true, "objects": ["kpi", "chart", "graph"] },
    "limits": { "aggregate": { "max_measures": 3, "max_groups": 50, "max_points": 400, "max_period_days": 1096 } },
    "actions": []
  }
}
```

| Field | Meaning |
|---|---|
| `features.objects` | The types the backend can emit (`AssistantCapabilities::objects()`), in the order `kpi`, `chart`, `graph`. `[]` when the policy compiled for the in-app profile with `widget_objects` allows no tool (the catalog lacks the capability, or the profile does not grant `show_widget`). Otherwise `kpi` and `chart` when an entity in `ai.features.tools.crud.entities` lists `summarize`, and `graph` when the policy compiled for the in-app profile with `read_only_graph` allows `graph_expand`, whether or not an entity lists `summarize`. |
| `limits.aggregate` | The four ceilings of `core.aggregate.*` a client may plan with: `max_measures`, `max_groups`, `max_points`, `max_period_days`, as configured. Every limit is also a structured error of the route, so a client works without them. |

Like the rest of the endpoint, these depend on the instance, not on the person: which entities a person may
summarize is listed in `actions` (`operation: summarize`, `kind: read`), and a figure or a chart is offered for a
turn only when that list is not empty for the acting person. Which records a person may see in a map is decided by
the graph gateway on each call. A client that reads an empty list, or a response without `features.objects` (an older
backend), sends no `context.page.objects` and gets answers in text only.

## What `summarize` does

`summarize` is the read tool the same opt-in enables, and it runs on the same aggregate engine:

- it covers every row the person may read that matches the filters, not a sample;
- it groups by one column, or cuts a date column into `day`, `week`, `month`, `quarter` or `year` buckets over a
  `period`; a second `group_by` column is refused;
- a column group returns its first `max_groups` groups (50 by default) ranked by the first metric (by count when none is asked),
  and the figures of the rest under `other`; a bucketed group returns every bucket;
- `sum` and `avg` need a numeric column that is not a key;
- a figure that adds more than one currency is `null`, and the answer carries the `MIXED_CURRENCY` warning;
- a refusal of the engine comes back with its code and the place it is about, so the model can correct the call.

## Graph objects

A graph object is the relation map of one record: the record, and the records its requested relations reach. It is
shown only in a run whose compiled policy allows `graph_expand` (capability `read_only_graph`), and only on a page
that declares `graph`.

### The request

The model writes it as JSON text in `request`:

```json
{ "resource": "core/users", "id": 7, "relations": ["roles", "roles.permissions"], "depth": 2, "node_detail": "summary" }
```

| Key | Rule |
|---|---|
| `resource` | `module/entity`, lower case, as for the other types. |
| `id` | The key of the record: an integer, or a text of 1 to 255 characters that is not blank. |
| `relations` | A list of 1 to 10 distinct relation paths, each of relation names joined by dots (`^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$`), of at most 128 characters, and with no more steps than the depth. |
| `depth` | Optional, `1` or `2`; `1` when left out. |
| `node_detail` | Optional, `minimal` or `summary`. |

No other key is accepted, `kind` included. These are the bounds of the `graph_expand` tool.

### What a graph call is checked against

After the checks common to every type (above), in this order, each with its own refusal:

| Check | `<why>` |
|---|---|
| The message holds fewer than `max_objects_per_message` objects, counting the figures and charts too | `a message shows at most <max_objects_per_message> objects` |
| The request has only the keys above | `a graph request holds only resource, id, relations, depth and node_detail` |
| `id` | `the id must be an integer or a text of 1 to 255 characters` |
| `relations` is a list of 1 to 10 entries | `the relations must be a list of 1 to 10 relation paths` |
| Each entry is a relation path of at most 128 characters | `each relation must be a path of relation names joined by dots, of at most 128 characters` |
| No path is repeated | `each relation may appear only once` |
| `depth` | `the depth must be 1 or 2` |
| No path has more steps than the depth | `a relation path may not have more steps than the depth` |
| `node_detail` | `node_detail must be minimal or summary` |
| No variant | `a graph takes no variant` |
| No format | `a graph takes no format` |
| No object of the message shows the same graph: the same resource, id (`7` and `"7"` are one id), relations in any order, depth and `node_detail` | `an object showing the same request is already in this message` |

The graph then expands once through `GraphToolGatewayInterface::expand()` with a `GraphExpandToolInput` of the
module, the entity, the id as text, the relations and the depth, at most 25 nodes and at most 10 records per
relation (the maximums of the graph tools). The gateway runs Core Graph as the acting person: the permission on each
entity, the row-level ACL of each record and the rules of its provider. It answers an entity it cannot resolve, a
record that does not exist or may not be read, a relation it refuses, and any failure of its own with an
unavailable result, which is answered with a fixed sentence and not reported:
`the graph cannot be shown: the record does not exist, the user may not read it, or a relation cannot be expanded`.
Any exception the gateway throws is reported and answered `the figures could not be computed`; its message never
reaches the model.

### What the model is told

On success: `Widget recorded.`, a reminder never to name a record or state a count it was not given, and compact JSON
of counts only:

```json
{ "nodes": { "total": 3, "by_type": { "core/roles": 2, "core/users": 1 } }, "edges": { "total": 2, "by_relation": { "roles": 2 } }, "truncated": false }
```

`by_type` counts the nodes of each `module/entity` as the gateway names them, `by_relation` the edges of each
relation, and `truncated` says that the map was cut at its limits. No label, attribute, node id or record key is
handed over: those are text people wrote, and a channel for instructions. The gateway does not pass on whether
records were left out for permissions, so the model is not told that. The counts still go through the same check as
the other results: when they read like an instruction they are withheld, the object is still recorded, and
`Assistant tool result withheld` is logged with `operation: graph_expand`.

### The object

```json
{
  "id": "5d0c2f7e-3a1b-4c8e-9f60-2b7d1e4a9c33",
  "type": "graph",
  "title": "Roles of Alice",
  "ref": {
    "request": { "kind": "graph", "resource": "core/users", "id": 7, "relations": ["roles", "roles.permissions"], "depth": 2, "node_detail": "summary" }
  }
}
```

`ref.request` is the validated request, never the raw text of the model: `kind` (`graph`), `resource`, `id` as the
model wrote it (an integer or a text), `relations` in the order given, `depth` always written (`1` when left out),
and `node_detail` only when it was given. The audit log writes the same request canonically (keys sorted, the id as
text, the relations sorted).

**Running it.** A client calls `GET /app/crud/graph/expand/{module}/{entity}/{id}` with `relations[]`, `depth` and,
when present, `node_detail` from `ref.request`. The route applies the permission and the ACL again and returns the
nodes, the edges and `graphMeta`, with `truncated` and `filteredByAcl`. It applies its own `limit` and
`relation_limit` (see `GRAPH_SYSTEM.md`), which the object does not carry: the map a client draws may hold more
nodes than the 25 the model's counts came from.

## Code map

Paths are under `Modules/AI/app/`.

| Piece | Role |
|---|---|
| `Services/Tools/ShowWidgetTool` | The tool definition and its parameters. |
| `Services/Assistance/Widgets/WidgetObjectCollector` | Checks each call, runs the request once, keeps the objects of one message. `CAPABILITY = 'widget_objects'`, `GRAPH_TOOL = 'graph_expand'`, `types()` (the types of the run). |
| `Services/Assistance/Widgets/RenderableObjectTypes` | Reads `context.page.objects`. |
| `Data/WidgetObject` | `{id, type, title, ref}`. |
| `Services/Assistance/InAppAssistanceService` | Builds the tool for the turn, passes the collector whether the policy allows `graph_expand` and the `GraphToolGatewayInterface` of the container, and writes `metadata.objects`. |
| `Services/Assistance/Stream/AgentEvent::object()`, `Services/AssistAgentService` | The stream event. |
| `Services/Assistance/AssistantCapabilities` | `WIDGETS_CAPABILITY`, `GRAPH_CAPABILITY`, `widgets()`, `summaries()`, `objects()`, `limits` in `toArray()`. |
| `Modules\Core\Graph\Contracts\GraphToolGatewayInterface` (Core) | The gateway a graph expands through, shared with the graph tools. |
| `Services/Assistance/Policies/AssistantPolicyCatalog` | The capability and its instruction to the model. |

## Testing

In `Modules/AI/tests/Feature/Assistance/`: `WidgetObjectsTest` (every check of a call, the graph bounds, the
gateway refusals, the counts handed to the model, the stored object, the audit log, withholding),
`WidgetObjectsFlowTest` (when the tool is offered, with and without `graph_expand`, the type enum, the metadata, the
refusal path, the chat routes), `AgentEndpointTest` (the stream event), `CapabilitiesEndpointTest`
(`features.objects`, `limits.aggregate`). The graph tests fake `GraphToolGatewayInterface` in the container, and one
expands a real record through the gateway of Core.
`summarize` is covered by `Modules/AI/tests/Feature/Tools/CrudToolProviderTest.php`.

## Errors and troubleshooting

| Symptom | Check |
|---|---|
| `features.objects` is empty | The catalog lacks `widget_objects` or the in-app profile does not grant `show_widget`; or no entity lists `summarize` in `ai.features.tools.crud.entities` and the policy does not allow `graph_expand`. |
| `features.objects` lists only `graph` | No entity lists `summarize`: figures and charts need that opt-in, a graph does not. |
| The model never shows an object | The page sent no `objects` list, or only unknown types; or it declared only `kpi` or `chart` and the person holds no `select` on any entity opted into `summarize`; or it declared only `graph` and the policy does not allow `graph_expand`: the tool is then not built. |
| A graph is refused with `the graph cannot be shown` | The entity cannot be resolved, the record does not exist, the person may not read it, or Core Graph refuses a relation (a provider rule, an excluded relation, a relation that does not exist). The gateway does not say which. |
| An object is refused | The answer to the model says why: see the table of checks. |
| The figures the client shows differ from what the model said | The client runs the request later, with the permission and ACL of that moment, and the data may have changed. |

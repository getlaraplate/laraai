# Documentation retrieval evaluation — developer and operator guide

## Purpose

This document describes the internals and extension points of the documentation RAG evaluation harness (RAG goal R0). The harness mirrors the application-content evaluation harness (`Modules/AI/app/Services/ApplicationContent/Evaluation/*`) but adapts it to the documentation retrieval contract. It is deterministic and provider-free by design so it runs in CI without Elasticsearch or a live LLM.

## Architecture

The harness scores retrieval only. It routes through the **real** `InAppDocumentationRetrieval::retrieve()` via that class's injectable `$search` seam, so production audience/permission/tenant/locale filtering and the safe-field projection all run; only the Elasticsearch vector store is replaced by a deterministic in-memory fixture.

```mermaid
flowchart TD
    dataset["dataset (JSON)"]
    loader["DocumentationEvaluationDataset"]
    service["DocumentationEvaluationService"]
    context["DocumentationEvaluationCase::accessContext()"]
    callable["retrieval callable"]
    retrieve["InAppDocumentationRetrieval::retrieve(question, access)"]
    embed["StubDocumentationEmbeddingService (embedText → [crc32])"]
    search["injected $search closure (fixture vector store + filtering)"]
    safe["safeDocuments() (real audience/permission/tenant projection)"]
    report["metrics + slices report (aggregate floats only)"]
    dataset --> loader --> service
    service -->|per case| context --> callable --> retrieve
    retrieve --> embed
    retrieve --> search
    retrieve --> safe
    embed --> report
    search --> report
    safe --> report
```

Grading identity is `Document::$sourceName` (the safe source label), the only stable per-document identity that survives the safe projection.

## Index profiles (`--index=user|developer`)

`ai:evaluate-documentation` evaluates one index profile per run; `--index` defaults to `user`.

- **`user`** (default) routes through `InAppDocumentationRetrieval` exactly as described above: the user-facing corpus with the full audience/permission/tenant filtering and safe projection. Grading identity is `safe_source_label`.
- **`developer`** routes through `DeveloperDocumentationRetrieval`: a plain vector search over the developer index (`DocumentationIndexProfile::Developer`) with **no** ACL, audience, or permission gate, because the developer corpus is not per-user access-controlled. Grading identity is the stored `Document::$sourceName` (e.g. `faq-module-Core/EVENT_ORCHESTRATION.md`); developer chunks carry no `safe_source_label`.

The two retrievals are physically separate classes: the developer path exists so the developer corpus is measurable without adding a bypass branch to the security-gated in-app path. A developer dataset declares `"index_profile": "developer"` and is run with `--index=developer`; its `expected_source_labels` are the stored source names, not middle-dot safe labels. Developer fixtures use `FakeDocumentationSearch::forDeveloperRetrieval([...])` (an injectable search closure taking only the embedding, no access context), mirroring `::forInAppRetrieval`.

## Domain objects

Owned by AI, mirroring `Modules/AI/app/Services/ApplicationContent/Evaluation/`:

- `Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationCase` — immutable, validated case. Exposes `accessContext(): AssistantAccessContext` (an `InAppAssistance` context built from the case's `tenantScope`/`tenantId`/`locale`/`effectivePermissions`).
- `Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationDataset` — `fromFile()`/`fromArray()` with exact-key assertions and bounded sizes; builds a list of cases.
- `Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationService` — `evaluate(DocumentationEvaluationDataset $dataset, string $driver, callable $retrieval): array`, where `$retrieval` is `fn(string $question, AssistantAccessContext $access, DocumentationEvaluationCase $case): list<Document>`. Registered as a singleton in `AIServiceProvider`.
- `Modules\AI\Services\Evaluation\{EvaluationStatistics, EvaluationDatasetReader, EvaluationCaseRules}` — the code that the documentation, application-content, strategy and assistant evaluations share: ratios, rounding, percentiles and the per-locale and per-category slices; the reading of a dataset file (size cap, depth cap, at most 1000 cases, exact keys, typed fields, the label that its exception messages carry); and the checks that every case class repeats (id and locale patterns, the length of the query, lists of strings and of slugs). `IrMetrics` holds the rank metrics (`firstRank()`, `reciprocalRank()`). A report must come out byte for byte as before a change to any of them: the committed reports under `evaluations/` pin the rounded values.
- `Modules\AI\Console\Concerns\WritesJsonReport` — the option and report handling of the four evaluation and tuning commands: refuse an existing report without `--force`, refuse an unwritable directory, write the JSON to a temporary file and move it into place.
- `Modules\AI\Console\EvaluateDocumentationCommand` — `ai:evaluate-documentation`, auto-registered by the module provider (no manual wiring, like `EvaluateApplicationContentCommand`).

Test support (namespace `Modules\AI\Tests\Stubs\Documentation\`):

- `StubDocumentationEmbeddingService` — `embedText($text)` returns `[(float) crc32($text)]`.
- `FakeDocumentationSearch` — the injected `$search` closure. Keyed by `(int) crc32($query)`; applies locale, tenant, and permission filtering equivalent to the production Elasticsearch filter, truncates to `context->topK`. `::document(...)` builds a safe-projection-passing `Document`; `::forInAppRetrieval([...])` wires a real `InAppDocumentationRetrieval` with the stub embedder + this closure.
- `CoreUserDocumentationCorpus` — the Core/user fixture corpus whose `safe_source_label`s are byte-identical to the Core dataset's `expected_source_labels`.

## InternalFlow

1. `DocumentationEvaluationService::evaluate()` iterates the dataset's cases, timing each.
2. For each case it calls `$retrieval($case->query, $case->accessContext(), $case)`.
3. It collects each returned `Document`'s `sourceName`; a retrieval that throws counts the case as `unavailable` with empty labels (fail-closed).
4. Metrics are computed over the records; slices recompute the same metrics per locale and per slice tag (deterministic `ksort`).

Metric keys: `source_hit_at_k, mean_reciprocal_rank, citation_precision, authorized_empty_accuracy, supported_answer_rate, refusal_accuracy, unavailable_rate`. Report keys: `schema_version, module, index_profile, driver, dataset_version, corpus_revision, data_classification, case_count, metrics, latency_ms, slices`. Denominators follow the application-content mirror: hit@k/MRR over cases with expected labels; citation precision over total returned labels; refusal accuracy over all cases.

## Why the fixtures pass the real safe projection

`InAppDocumentationRetrieval::retrieve()` always runs `safeDocuments()`, which requires each document's metadata to satisfy `DocumentAudiencePolicy::allows(..., User)` **and** `permissions_metadata_validated === true` **and** `required_permissions_count === count(required_permissions)`, then keeps only `audience, heading_breadcrumb, locale, module, safe_source_label, version` and sets `sourceName = safe_source_label`. `FakeDocumentationSearch::document()` therefore sets every required metadata key (`audience`, `module`, `locale`, `canonical_source`, `safe_source_label`, `version`, `policy_classification`, `policy_classification_version`, `required_permissions`, `required_permissions_count`, `permissions_metadata_validated`, `heading_breadcrumb` as a list, `tenant_scope`). This keeps the harness honest: the ranking, filtering, and projection are the production ones.

The stub embedder and fake search agree on the key `(int) crc32($query)`, so per-query ranking is authored while the real retrieval code path still runs.

## HowToUse — add a report card for a new module

1. Author `Modules/{Module}/docs/rag/evaluations/<slug>.json` (see the user guide for the schema). `expected_source_labels` must match the `safe_source_label`s the retriever returns for those pages.
2. Add a fixture corpus stub under `Modules/AI/tests/Stubs/Documentation/` (mirror `CoreUserDocumentationCorpus`): map each dataset `query` to `FakeDocumentationSearch::document($label, ...)` documents whose `safe_source_label` equals the dataset's `expected_source_labels`; map refusal queries to `[]`.
3. Add a gate test under `Modules/AI/tests/Feature/` (mirror `DocumentationBaselineGateTest`): load the dataset via `base_path(...)`, run `DocumentationEvaluationService` against the corpus, assert the committed Level-1 thresholds and `unavailable_rate === 0.0`.
4. Run `composer dump-autoload -o` after adding the stub, then the gate test.

Dataset↔corpus label alignment is the property that makes the gate non-vacuous — verify it byte-for-byte (the labels contain a Unicode middle dot `·`).

## Configuration

- `ai.features.faq.max_documents` — resolved top-K (`DocumentationRetrievalContext`, clamped 1–10). The case's `top_k` is informational; tests set this config to the intended K.
- `ai.features.faq.policy_classification_version` (the setting `features.faq.policy_classification_version`) — must match the documents' `policy_classification_version` (default `in-app-docs-v1`).

## PermissionsAndSecurity

The harness never modifies production retrieval behavior — the only production touch is the `DocumentationEvaluationService` singleton binding. Reports leak no payload: only aggregate floats, latency, and slugged slice keys. `data_classification` is restricted to `synthetic`. Guest/tenant/permission rules apply exactly as in production because the real `retrieve()` path runs.

## ErrorsAndTroubleshooting

- **Fatal "Cannot redeclare function ..."** — two Pest test files declared the same top-level helper. Give each evaluation test file a uniquely named helper (e.g. `docDatasetArray` vs `docServiceDatasetArray`); do not rely on `function_exists()` guards.
- **Gate passes vacuously** — check dataset↔corpus label alignment and that queries match the corpus map keys (`crc32`).
- **`safeDocuments()` rejects a fixture (retrieve throws)** — the fixture metadata is incomplete; compare against `FakeDocumentationSearch::document()`, `DocumentAudiencePolicy`, and `InAppDocumentationRetrieval::safeDocuments()`.

## PerformanceAndLimits

- Deterministic Level-1 only; no external services in tests.
- The deterministic gate measures ranking + harness + the emulated permission/tenant/locale filter; real Elasticsearch filtering and Level-2 generation are opt-in and out of scope.
- **Level-2 (generation)** is defined but unimplemented: grading the written answer (`DocumentationService::answerQuestion()`) for groundedness, citation faithfulness, and correct refusal is non-deterministic and never gates CI.

## Forward contract — assistant-level evaluation (R1)

R0 defines, without implementing, the shape R1 will populate so the future grounded module assistant is measurable from birth, staying per-module. An assistant-level case adds `expected_surface` (`documentation` | `application_content` | `graph` | `refuse`), `expected_citations_by_surface`, and `expect_clarification`; assistant-level metrics add `surface_routing_accuracy`, `cross_surface_citation_precision`, `clarification_accuracy`. See the design spec.

## Related

- User/operator guide: `Modules/AI/docs/rag/DOCUMENTATION_EVALUATION_USER.md`
- Design spec: `docs/superpowers/specs/2026-08-04-documentation-rag-evaluation-baseline-design.md`
- Mirror harness: `Modules/AI/app/Services/ApplicationContent/Evaluation/`

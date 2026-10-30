# Documentation retrieval evaluation — user and operator guide

## Purpose

The documentation evaluation baseline answers one question for every future change: *did documentation retrieval get better or worse?* It is a per-module "report card" (a versioned set of graded questions plus a score report) for the questions your module's users ask the in-app assistant. It is a maintainer/operator tool, not an end-user application feature; it does not call the chat model and never touches production data.

Each module owns its own report card. One agent lives in one module's app context, so one module's evaluation is isolated and never mixes questions from another module.

## Capabilities

- Score how well documentation retrieval finds the correct guide page for a set of graded questions, per module and index profile (`user` or `developer`).
- Report deterministic Level-1 retrieval metrics: hit rate, ranking, citation precision, correct refusal, and permission/tenant exclusion accuracy.
- Break scores into slices (by topic, hop class, locale) to show where a module is strong or weak.
- Fail a build when retrieval quality regresses below the committed baseline (regression gate).

Level-2 (grading the written answer with a live model) is specified but opt-in and is not part of this deterministic baseline.

## HowToUse

### Run an evaluation

```bash
php artisan test:ai:evaluate-documentation \
  --module=core --index=user \
  --dataset=Modules/Core/docs/rag/evaluations/2026-08-documentation-user.json \
  --output=storage/app/doc-eval-core-user.json
```

- `--module` must match the dataset's `module`.
- `--index` must match the dataset's `index_profile` (only `user` is supported today).
- `--output` is a new JSON report path; the command refuses to overwrite an existing report unless you pass `--force`.

The command runs retrieval only — no chat model, no cost. Against a live Elasticsearch index it measures real retrieval quality; without a configured index it reports zeros, so treat the committed deterministic gate (below) as the source of truth in CI.

### Author a report card for your module

A dataset is a JSON file of graded cases stored under your module at `Modules/{Module}/docs/rag/evaluations/`. Each case is one question with the correct answer already marked:

```json
{
  "version": "1",
  "corpus_revision": "core-2026-08",
  "module": "core",
  "index_profile": "user",
  "data_classification": "synthetic",
  "cases": [
    {
      "id": "search-required-terms",
      "query": "how do I force a search term to be required?",
      "locale": "en",
      "top_k": 5,
      "expected_source_labels": ["Core · Adaptive search matching · Required terms and exact phrases"],
      "expected_citation_labels": ["Core · Adaptive search matching · Required terms and exact phrases"],
      "expect_authorized_empty": false,
      "expect_supported_answer": true,
      "expect_refusal": false,
      "slices": ["search", "single_hop"],
      "tenant_scope": "global",
      "tenant_id": null,
      "effective_permissions": []
    }
  ]
}
```

Authoring rules:

- **`query`** is what a user of your module would ask.
- **`expected_source_labels`** are the correct guide page(s), identified by the retrieval's *safe source label*. A retrieved chunk counts as a hit when its label appears here.
- **Off-topic / unanswerable questions** set `expect_refusal: true` and leave `expected_source_labels` and `expected_citation_labels` empty — they check that the assistant stays silent when the corpus has no answer.
- **`slices`** are lowercase slug tags (topic, `single_hop`/`multi_hop`, locale) used to break the score into sub-scores.
- **`expect_authorized_empty: true`** marks a case where the correct page exists but the requesting principal (its `effective_permissions` / `tenant_scope`) must not see it — the correct result is an empty answer.

### Read the report

The report is aggregated numbers only — it never contains the raw question text or any record content:

| Metric | Meaning | Good value |
|---|---|---|
| `source_hit_at_k` | fraction of answerable questions whose correct page is in the top-K | 1.0 |
| `mean_reciprocal_rank` | how high the correct page ranks (1.0 = always first) | close to 1.0 |
| `citation_precision` | fraction of returned pages that were expected | 1.0 |
| `refusal_accuracy` | answered/refused exactly when it should | 1.0 |
| `supported_answer_rate` | returned evidence when an answer exists | 1.0 |
| `authorized_empty_accuracy` | correctly returned nothing for hidden pages | 1.0 |
| `unavailable_rate` | share of questions where retrieval failed | 0.0 |

`slices.locale` and `slices.category` repeat the same metrics per locale and per slice tag. A raw similarity score is never presented as answer confidence.

## Configuration

- `ai.features.faq.max_documents` — retrieval depth (top-K), clamped to 1–10. This, not the dataset's `top_k`, sets the actual K.
- `ai.features.faq.policy_classification_version` (the setting `features.faq.policy_classification_version`) — the user-safe classification version documents must carry.

## PermissionsAndSecurity

Evaluation runs the real retrieval path, including audience, permission, tenant, locale filtering, and the safe-field projection. A dataset case carries the requesting principal (`effective_permissions`, `tenant_scope`, `tenant_id`); the harness applies the same rules production would. Reports contain only aggregate metrics and slugged slice keys — never queries, labels, permissions, or record content.

## ErrorsAndTroubleshooting

- **"The output report already exists"** — pass `--force` or choose a new `--output` path.
- **"dataset module or index does not match"** — `--module`/`--index` must equal the dataset's `module`/`index_profile`.
- **All metrics are 0.0** — no live Elasticsearch index is bound; the command ran against an empty index. Use the deterministic gate for CI truth.
- **Gate test fails after a change** — retrieval regressed, or your dataset's `expected_source_labels` no longer match the pages the retriever returns. Re-check label alignment before lowering any threshold.

## PerformanceAndLimits

- Level-1 is deterministic and cheap; it runs in CI on every change.
- The deterministic regression gate lives in `Modules/AI/tests/Feature/DocumentationBaselineGateTest.php` and asserts the committed baseline thresholds. Raise thresholds only with an intentional, reviewed commit that also refreshes the baseline.
- Live-Elasticsearch and Level-2 (written-answer) runs are opt-in and out of the deterministic suite.

## FAQPrompts

- "How do I add an evaluation for my module?" → author a dataset JSON under `Modules/{Module}/docs/rag/evaluations/` and run `test:ai:evaluate-documentation`.
- "What does a hit mean?" → a retrieved page whose safe source label matches an `expected_source_labels` entry.
- "Why is my score zero?" → likely no live index bound, or dataset labels don't match the retriever's labels.

## Related

- Developer/internals guide: `Modules/AI/docs/rag/DOCUMENTATION_EVALUATION_DEVELOPER.md`
- Design spec: `docs/superpowers/specs/2026-08-04-documentation-rag-evaluation-baseline-design.md`

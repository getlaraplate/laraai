# AI module — search pre-processing and modification moderation

## Purpose

The AI module listens to **Core events** for optional pre-processing before search indexing and before human approval workflows complete. It does **not** import CMS, ERP, or other domain modules.

Canonical bus documentation: `Modules/Core/docs/rag/EVENT_ORCHESTRATION.md`.

## Capabilities

| Pipeline | Core event | AI entrypoint | Outcome |
|----------|------------|---------------|---------|
| Embeddings | `ModelRequiresIndexing` | `HandleModelIndexingListener` | Vectors in DB, then `ModelPreProcessingCompleted('embeddings')` |
| Translation (with indexing) | `TranslatedModelSaved` / indexing cache | `HandleModelTranslationListener` | `TranslateModelJob` |
| Moderation | `ModificationRequiresModeration` | `HandleModificationModerationListener` | `ApproveModificationJob` → approval/disapproval rows |
| Post-approve translate | `ModificationApproved` | `HandleModificationApprovedTranslationListener` | `TranslateModelJob` |

## InternalFlow — moderation (AI only)

### Listener: `HandleModificationModerationListener`

Runs when all are true:

1. `config('ai.features.moderation.enabled')`
2. A user carries the username in `config('permission.users.system')` (Core-seeded system user)
3. `Modification` is active
4. `ModerationContextBuilderRegistry::supports($modification)`
5. Modifiable model: `ai.features.moderation.entities.{table}` enabled (`ModerationEntitySettings`)

Actions: `addRequiredPreProcessing('ai_approval')`, cache `modification_moderation:{id}`, dispatch `ApproveModificationJob`, `markAsHandled()`.

### Job: `ApproveModificationJob`

1. `ModerationContextBuilderRegistry::build($modification)` → `ModerationContext`
2. `ModerationService::analyze()` (LLM)
3. System user votes on `Modification` (approve/disapprove + `meta` audit JSON)
4. `ModificationPreProcessingCompleted('ai_approval')`

Humans can still approve/reject in Filament after the AI vote.

## InternalFlow — search (AI only)

### `HandleModelIndexingListener`

When embeddings enabled and model has `$embed`: register `embeddings` pre-processing, dispatch `GenerateEmbeddingsJob`, `markAsHandled()`.

### `HandleModelTranslationListener`

On `TranslatedModelSaved`: if `translations.auto.{table}` enabled, may attach `translation` to pending `model_indexing:*` cache or run standalone `TranslateModelJob`.

If AI does not handle indexing, Core `IndexModelFallbackListener` still runs `IndexInSearchJob`.

## Configuration

| Key | Set by | Meaning |
|-----|--------|---------|
| `ai.features.moderation.enabled` | setting `features.moderation.enabled` | Master switch |
| `permission.users.system` | `SYSTEM_USER` (env) | Username of the system user AI votes as |
| `ai.features.moderation.threshold.{approve,reject}` | settings `features.moderation.threshold.*` | Confidence thresholds |
| `ai.features.moderation.queue` | setting `features.moderation.queue` | Queue of the moderation job, read at dispatch |
| `ai.features.embeddings.enabled` | setting `features.embeddings.enabled` | Embedding pipeline |
| `ai.features.chat.enabled` | setting `features.chat.enabled` | The in-app assistant (conversations, messages, agent, proposed writes); seeded off |
| `ai.features.faq.enabled` | setting `features.faq.enabled` | Documentation RAG: the assistant reads the user documentation to answer how the application works |

The settings are in Filament > Settings, group `ai`. No `AI_MODERATION_*` or `AI_COMMENT_*` env variable is read.

AI moderation switches are `ai.features.moderation.entities.{table}` settings, declared by the AI module only for models with a registered `ModerationAdapter` and read from config; `translations.auto.{table}` is resolved by `PerModelSettingResolver`.

## HowToUse — documentation RAG

Index FAQ corpus (not Elasticsearch model index):

```bash
php artisan ai:index-rag-docs
php artisan ai:index-rag-docs --full
php artisan ai:help --question="How does comment AI moderation work?"
```

Corpus roots: `docs/rag/`, active `Modules/*/docs/rag/` (see `docs/rag/README.md`). Technical docs under `Modules/*/docs/` without `rag/` are **not** scanned unless passed via `--path` or `AI_FAQ_DOCS_PATH`.

## ErrorsAndTroubleshooting

| Symptom | Check |
|---------|--------|
| Job never queued | Feature flags, queue worker, a user with the `permission.users.system` username |
| BindingResolutionException on moderation | Builder registered in domain `ServiceProvider` |
| AI votes but comment still hidden | Human `approvers_required` not satisfied |
| RAG answers wrong on moderation | Re-run `ai:index-rag-docs` after updating `docs/rag` files |

## FAQPrompts

- What config enables AI comment moderation?
- What does ApproveModificationJob do?
- How does AI relate to ModificationRequiresModeration?
- Difference between ai:index-rag-docs and Elasticsearch indexing?
- Which env vars control AI_MODERATION?

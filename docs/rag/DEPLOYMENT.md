# RAG vector store — deployment guide

Documentation RAG (`ai:index-rag-docs`) persists chunk embeddings in a **vector store** selected by the setting `features.faq.vector_store` in Filament > Settings, group `ai` (read as `ai.features.faq.vector_store`). It was the env variable `AI_FAQ_VECTOR_STORE` until 2026-10-07; that variable is no longer read. After changing the store, rebuild the indexes (`ai:index-rag-docs --full`).

## Drivers

| Driver | Storage | Multi-instance |
|--------|---------|----------------|
| `filesystem` | Local file (`AI_FAQ_VECTOR_STORE_PATH` or `storage/app/ai/faq-vectorstore.store`) | **Only** if every replica mounts the **same read-write path** (shared PVC/NFS). |
| `memory` | In-process RAM | **Not for production.** Tests only, set in config; the setting does not offer it. |
| `elasticsearch` (default) | Shared Elasticsearch index (`AI_FAQ_ES_INDEX`) | **Recommended** when Elasticsearch is already in the stack. All app instances read the same index. |

## Filesystem on Kubernetes (interim)

Set `features.faq.vector_store` to `filesystem`, then run indexing from **one** job or pod, or ensure all pods share the store file:

```yaml
env:
  - name: AI_FAQ_VECTOR_STORE_PATH
    value: /shared/ai/faq-vectorstore.store
volumeMounts:
  - name: rag-vector-store
    mountPath: /shared/ai
volumes:
  - name: rag-vector-store
    persistentVolumeClaim:
      claimName: laraplate-rag-pvc
```

Without a shared volume, each replica has its own index and FAQ answers differ per pod.

## Elasticsearch (recommended for production)

1. The index's vector length is the `dimensions` of the active embedding profile (`ai.features.embeddings.models`); there is nothing to set.
2. Create the index:

```bash
php artisan ai:create-rag-es-index
```

3. Configure: the setting `features.faq.vector_store` is `elasticsearch` (its default), and the index name is env:

```env
AI_FAQ_ES_INDEX=laraplate_rag_docs
```

4. Index documentation (from any single instance or CI job):

```bash
php artisan ai:index-rag-docs
```

All replicas then share the same corpus via Elasticsearch.

### Documentation query log (optional)

The log of the questions answered from the user documentation is off by default. To turn it on:

1. Create its index (the name is `AI_FAQ_QUERY_LOG_INDEX`, default `{app}_rag_queries`):

```bash
php artisan ai:create-rag-query-index
```

2. In Filament > Settings, group `ai`, turn on `features.faq.query_logging.enabled`. `features.faq.query_logging.query_text_mode` keeps the question as typed (`raw`) or drops it (`off`); `features.faq.query_logging.retention_days` is how long a document is kept (30).
3. Run a queue worker: the log is written by a queued job.

The scheduler deletes expired documents every day (`ai:prune-rag-queries`). To delete everything logged for one user, run `php artisan ai:erase-rag-queries {user id}`. The privacy decisions behind these defaults are in `query-analytics-privacy-review.md`.

### Embedding dimension changes

Changing the embedding model goes through `ai:embeddings:switch`, which recreates these indexes for the new model (`ai:create-rag-index --profile=all --force`) and reindexes the documentation in `IndexDocumentsChunkJob` chunks of `AI_EMBEDDINGS_RAG_CHUNK_SIZE` files on the `embeddings-index` queue (the work of `ai:index-rag-docs`, one range of files per chunk). By hand: `ai:create-rag-index --force` drops and recreates each index with the active profile's dimensions.

### Embedding server timeout and batch size

`ai:index-rag-docs` calls the embeddings server in batches. A CPU-bound server is slow (~0.4s per long chunk on the former service), so a large batch can exceed the HTTP timeout and abort indexing (`cURL error 28 ... /v1/embeddings`); the client halves a batch that fails or is slow, but the timeout still bounds one request. Both are configurable:

```env
AI_EMBEDDINGS_TIMEOUT=120   # seconds per /v1/embeddings request (default 30)
AI_EMBEDDINGS_BATCH_SIZE=16 # documents per request (default 32; at most the --max-client-batch-size of a TEI)
```

Keep `batch_size` small enough that one batch completes within `timeout`; on a GPU service the defaults are fine.

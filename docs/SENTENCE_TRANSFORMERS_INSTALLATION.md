# Self-hosted Sentence Transformers for embeddings

Laraplate does not run Sentence Transformers inside PHP. The AI module calls a **self-hosted HTTP API** on a separate host (VM, bare metal, or container). This guide covers that sidecar service and the Laraplate configuration that points to it.

---

## What uses the embeddings provider

Embeddings are selected **per feature**, not by a global `AI_PROVIDER` variable (that env name is not read by the application).

| Config key (`ai.*`) | Purpose | Default provider id |
|---------------------|---------|---------------------|
| `features.embeddings.model` (provider of the active profile) | Search indexing (`GenerateEmbeddingsJob`), vector search, RAG chunk vectors | `sentence_transformers` |

For Sentence Transformers you only need the **embeddings** provider and its URL.

---

## Architecture

```text
[Laravel + Horizon]  --POST /embed (Bearer key)-->  [Python host: gunicorn + Flask + sentence-transformers]
     .env: SENTENCE_TRANSFORMERS_URL=http://HOST:PORT
           SENTENCE_TRANSFORMERS_API_KEY=<same key as the service>
```

The Laravel application needs outbound HTTP access to the embedding host. Restrict inbound access on the embedding host to trusted clients (VPN, private network, or firewall rules) and set an API key on both sides (see *Security* below): the service is open when it has no key.

---

## HTTP contract (required)

Laraplate expects a REST endpoint at `{base_url}/embed` (no trailing slash on the base URL).

### Single text

```http
POST /embed
Content-Type: application/json

{
  "text": "hello world",
  "truncation": true,
  "normalize_embeddings": true,
  "max_length": 512
}
```

### Batch (up to `SENTENCE_TRANSFORMERS_BATCH_SIZE` texts per request, default 32)

```json
{
  "texts": ["first", "second"],
  "truncation": true,
  "normalize_embeddings": true,
  "max_length": 512,
  "model": "intfloat/multilingual-e5-small"
}
```

### Response

```json
{
  "model": "intfloat/multilingual-e5-small",
  "input_type": "passage",
  "prefix_applied": false,
  "embeddings": [[0.1, 0.2, "..."], [0.3, 0.4, "..."]]
}
```

Errors Laraplate can receive besides `5xx`: `400` for a malformed body, too many or too long texts, or a `model` the service does not allow; `401` without a valid key; `413` for a body over the service's size limit. The embedding call fails on any of them, like on a `5xx`.

### Input prefixes (`input_type`) and who applies them

Some models (e5, nomic, …) need a `query:` / `passage:` prefix; others (MiniLM)
need none. **Today Laraplate applies the prefix client-side** from the active
model profile (`query_prefix` / `passage_prefix` in `ai.features.embeddings`, applied by
`PrefixingEmbeddingsProvider`), so the service receives already-prefixed text and this field can be omitted.

The service **also** knows each family's convention and accepts an optional
`input_type` (`"query"` | `"passage"`). When present, it *ensures* the correct
prefix **idempotently**: if the text is already prefixed it is left untouched,
otherwise the prefix is added — so there is never a `query: query: …` double
prefix, whether the caller pre-prefixed or not. When `input_type` is omitted no
prefix is touched (current Laraplate behavior). This keeps the service safe for
other, prefix-unaware clients without changing Laraplate.

### Discovery: `GET /models`

Returns the default model, the models a request may name (`allowed_models`,
`null` when the host allows any), the currently resident models, the text
limits and the prefix families that support `input_type`, so a client can
discover behavior instead of hardcoding it. It requires the key like `/embed`.

```json
{
  "default_model": "intfloat/multilingual-e5-small",
  "allowed_models": ["intfloat/multilingual-e5-small"],
  "loaded_models": ["intfloat/multilingual-e5-small"],
  "model_cache": 2,
  "max_texts": 256,
  "max_text_chars": 100000,
  "input_types": ["query", "passage"],
  "prefix_families": {
    "e5": { "query": "query: ", "passage": "passage: " },
    "nomic": { "query": "search_query: ", "passage": "search_document: " }
  }
}
```

### Model selection (multi-model, request-driven)

The `model` field is **optional**. Laraplate sends the active embedding profile's service model (the part of its `provider:service_model` key in `ai.features.embeddings.models`) on every request, so the **Laravel config is the single source of truth** and the service never drifts from what the application expects. When `model` is omitted, the service uses its own `EMBEDDING_MODEL` default.

The service loads models **lazily** and keeps up to `EMBEDDING_MODEL_CACHE` of them resident (LRU), so switching the active model, or embedding with two models while a switch runs, needs no service restart. `/health` reports the default and the currently loaded models. Models of different output sizes can be served side by side: each Laraplate profile declares its own `dimensions`, and changing the model goes through `ai:embeddings:switch`, which rebuilds the indexes for the new length (see *Choose a model*).

A request may only name a model the host allows: its `EMBEDDING_MODEL` default, or one listed in `EMBEDDING_ALLOWED_MODELS` (comma-separated). Any other answers `400` without being downloaded. The entry must be the exact string Laraplate sends, which is the part of the profile key after `sentence_transformers:` (`all-MiniLM-L6-v2` for the profile `sentence_transformers:all-MiniLM-L6-v2`, not `sentence-transformers/all-MiniLM-L6-v2`).

### Security

When the service has a key (`SENTENCE_TRANSFORMERS_API_KEY` on the embedding host), `/embed`, `/score` and `/models` require `Authorization: Bearer {key}`, which Laraplate sends from its own `SENTENCE_TRANSFORMERS_API_KEY` (and `CROSS_ENCODER_API_KEY` for `/score`). Set the **same value on both sides**; a missing or wrong key answers `401`. `/health` stays open, which is what `EmbeddingServiceIdentity::healthModel()` reads. The service also caps the body size (`EMBEDDING_MAX_REQUEST_MB`, default 16), the texts per request (`EMBEDDING_MAX_TEXTS`, default 256) and the characters per text (`EMBEDDING_MAX_TEXT_CHARS`, default 100000); the defaults leave room for what Laraplate sends. It speaks plain HTTP: outside a private network, put it behind a TLS-terminating reverse proxy. Every service variable is listed in the [service README](https://github.com/getlaraplate/sentence-transformers-api#configuration-environment-variables).

Implementation reference:

- `Modules/AI/app/Ai/Embeddings/SentenceTransformersEmbeddingsProvider.php`
- `Modules/AI/app/Ai/Embeddings/EmbeddingsProviderFactory.php`

---

## Choose a port

Before starting the service, list listening ports on the **embedding host**:

```bash
ss -tlnp
# or, for one port:
ss -tlnp | grep ':8003'
sudo lsof -iTCP:8003 -sTCP:LISTEN
```

| Port | Typical use |
|------|-------------|
| 8000 | Documented default for `SENTENCE_TRANSFORMERS_URL`; often also used by `php artisan serve` |
| 8001 | Documented port of the Whisper service (`WHISPER_URL`); do not give it to the embedding service on the same host |
| 8003+ | Example when 8000–8002 are taken; pick any free port |

Use the same port in the Python process and in `SENTENCE_TRANSFORMERS_URL`.

---

## Choose a model

The vector length is declared by each embedding profile (`dimensions` in `ai.features.embeddings.models`) and is the source of truth: the RAG index is sized from the active profile, and Core's managed settings `search.vector.dimensions`, `search.vector.similarity` and `search.vector.model` are written by the model switch when it activates a profile. Do not edit them by hand (they are read-only in Settings). The two shipped profiles produce **384-dimensional** vectors.

Recommended models (384-d output):

| Hugging Face model | Dims | Notes |
|--------------------|------|-------|
| `intfloat/multilingual-e5-small` | 384 | **Default** (profile `sentence_transformers:intfloat/multilingual-e5-small`). Multilingual; requires the `query:` / `passage:` prefixes, which Laraplate adds from the model profile. |
| `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2` | 384 | Alternative multilingual model; no prefixes. Not shipped as a profile: add one (below). |
| `sentence-transformers/all-MiniLM-L6-v2` | 384 | Lighter; English-centric; no prefixes. |

The service is **multi-model**: `EMBEDDING_MODEL` is only the default served when a request omits `model`. Keep the default equal to the active profile's `service_model` so `/health` matches what `ai:embeddings:repair` expects. To use another model, add a profile keyed `sentence_transformers:<hugging face model>`, add its service model to `EMBEDDING_ALLOWED_MODELS` on the embedding host and restart the service (otherwise the probe and the switch get `400`), run `php artisan ai:embeddings:probe <key>` and put the measured length in its `dimensions`, then choose it in the setting `features.embeddings.model`: the confirmation starts `ai:embeddings:switch`, which re-embeds everything, rebuilds the index mappings when the length differs, verifies and activates it. Vector search is off meanwhile. Steps and commands: [rag/MODULE.md](rag/MODULE.md), section *Embedding model and model switch*.

`max_length: 512` in the API request is the **token truncation limit** for model input, not the embedding vector size.

---

## Install on the embedding host (Linux)

### System packages

```bash
sudo apt update
sudo apt install -y python3 python3-venv python3-pip git
```

Optional: NVIDIA driver + CUDA-compatible PyTorch for GPU inference under load.

### API server

The service code is **not duplicated here** — it lives in its own repository,
which is the single source of truth (code, pinned `requirements.txt`, systemd
unit, README):

**https://github.com/getlaraplate/sentence-transformers-api**

Clone it on the embedding host and install from its pinned requirements:

```bash
git clone https://github.com/getlaraplate/sentence-transformers-api.git
cd sentence-transformers-api
python3 -m venv .venv
source .venv/bin/activate
pip install --upgrade pip
pip install -r requirements.txt
```

In short: the service keeps the `EMBEDDING_MODEL` default warm, lazy-loads any
other allowed model named in a request, and keeps up to `EMBEDDING_MODEL_CACHE`
models resident (LRU). See the [HTTP contract](#http-contract-required) above for
the request/response shape Laraplate relies on.

Model weights download from Hugging Face on first use (~hundreds of MB per model). The service downloads each allowed model the first time it is asked for and caches it.

### Run manually (development)

```bash
source .venv/bin/activate
export EMBEDDING_MODEL=intfloat/multilingual-e5-small
export EMBEDDING_MODEL_CACHE=2
python sentence-api.py                             # Flask development server
gunicorn -c gunicorn.conf.py sentence-api:app      # the production server, same settings
```

### systemd unit (production)

The `sentence-transformers.service` unit ships with the
[service repository](https://github.com/getlaraplate/sentence-transformers-api);
install it from there rather than copying it here. It runs gunicorn (one
process, four threads) as the dedicated unprivileged user `sentence-api`, caches
models in `/var/cache/sentence-api` and reads the key and local settings from
`/opt/sentence-api/.env`. Create the user and the `.env` once, as the service
README describes in
[Deploy (systemd)](https://github.com/getlaraplate/sentence-transformers-api#deploy-systemd),
then:

```bash
sudo systemctl daemon-reload
sudo systemctl restart sentence-transformers
```

A host upgraded from the old unit, which ran as `root`, downloads its models
again into `/var/cache/sentence-api` unless they are copied there first (same
README section).

Keep `EMBEDDING_MODEL` equal to the active Laraplate profile's `service_model`. On a first request for a new model the service downloads and loads it (a few seconds to a minute); subsequent requests are fast while it stays in the LRU cache.

---

## Verify the embedding host

```bash
KEY=<the SENTENCE_TRANSFORMERS_API_KEY of the service>

curl -s "http://127.0.0.1:8000/health"   # open, no key
# {"status":"healthy","model":"intfloat/multilingual-e5-small",
#  "default_model":"intfloat/multilingual-e5-small",
#  "loaded_models":["intfloat/multilingual-e5-small"],"model_cache":2,"reranker":{...}}

# Without the key: expect 401 when the service has one.
curl -s -o /dev/null -w "%{http_code}\n" -X POST "http://127.0.0.1:8000/embed" \
  -H "Content-Type: application/json" -d '{"text":"hello"}'

# Default model (dimension check — expect 384):
curl -s -X POST "http://127.0.0.1:8000/embed" \
  -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/json" \
  -d '{"text":"hello","normalize_embeddings":true}' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['model'], len(d['embeddings'][0]))"

# Explicit model: it must be in EMBEDDING_ALLOWED_MODELS, otherwise 400.
# It lazy-loads, then shows in the response and /health:
curl -s -X POST "http://127.0.0.1:8000/embed" \
  -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/json" \
  -d '{"text":"hello","model":"all-MiniLM-L6-v2"}' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['model'], len(d['embeddings'][0]))"
```

From the Laraplate application server, repeat the same checks against the remote IP/hostname and port.

---

## Configure Laraplate

Switch embeddings on with the setting `features.embeddings.enabled` in Filament > Settings, group `ai` (seeded off; there is no env variable for it), then add or update `.env` on the Laravel host:

```env
SENTENCE_TRANSFORMERS_URL=http://EMBEDDING_HOST:8000
SENTENCE_TRANSFORMERS_API_KEY=<the key in /opt/sentence-api/.env on the embedding host>
```

The embedding model is chosen in the setting `features.embeddings.model`; the one that serves search is Core's managed setting `search.vector.model` (seeded with `sentence_transformers:intfloat/multilingual-e5-small`), which only a model switch changes. Neither is an env var. Laraplate sends the profile's service model (the part of the key after the provider) to the service per request. Keep the service's `EMBEDDING_MODEL` default equal to the active one.

Notes:

- The `sentence_transformers` profiles are offered only when `SENTENCE_TRANSFORMERS_URL` is set.
- A profile whose service model is not the host's `EMBEDDING_MODEL` also needs an entry in the host's `EMBEDDING_ALLOWED_MODELS` (see *Model selection*).
- Chat, translation and every other AI feature choose their model in Filament > Settings (`features.*.model`), not in env; see `rag/AI_MODEL_SELECTION_USER.md`.

Core's vector settings follow the active profile and need no edit. Enable Scout/vector search as in [SEARCH_AND_TRANSLATION.md](SEARCH_AND_TRANSLATION.md).

---

## After go-live

### Queue workers

Embedding generation uses `GenerateEmbeddingsJob`. Horizon or `queue:work` must run on the Laraplate host.

### Backfill existing content

```bash
php artisan ai:embeddings:repair "Modules\\CMS\\Models\\Content"
```

Replace the FQCN with each searchable model that defines `$embed`.

A model switch rebuilds the Elasticsearch documentation indexes itself. With a `filesystem` or `memory` documentation store, run `php artisan ai:index-rag-docs --full` after the switch.

---

## Optional: cross-encoder reranker

Hybrid search can rerank its top results with a cross-encoder through `POST {url}/score`. `CROSS_ENCODER_URL` is the base URL of that service (the client adds `/score`) and, when it is not set, `SENTENCE_TRANSFORMERS_URL` is used: the `sentence-api` service (repository `laraplate-sentencetransformer`) serves `/score` next to `/embed` with its default model (`CROSS_ENCODER_MODEL` on its host changes it, and an empty value turns it off). The key is `CROSS_ENCODER_API_KEY`, falling back to `SENTENCE_TRANSFORMERS_API_KEY`; when the reranker runs on the embedding service and the two Laraplate keys differ, list both on the host, comma-separated, in its `SENTENCE_TRANSFORMERS_API_KEY`. Laraplate never names a rerank model, so the host's default is used; another one in a request needs `CROSS_ENCODER_ALLOWED_MODELS` on the host. There is no built-in address: with no URL, or a service whose model is turned off (it answers 503), nothing is reranked. Not required for embeddings; if the service is down, or answers with anything but one numeric score per pair, search returns unreranked results (`meta.reranked = false`, a warning in the log). The evaluation commands (`ai:evaluate-retrieval-strategies`, `ai:tune-retrieval`) warn when it did not run, because their `reranked` figures are then the fused order. While no service answers `/score`, `search.reranker.enabled` can be turned off so that each search does not spend a failed call and a warning on it.

---

## Troubleshooting

| Symptom | Likely cause |
|---------|----------------|
| Connection refused from Laravel | Wrong URL/port, firewall, or service not listening on `0.0.0.0` |
| HTTP 401 | `SENTENCE_TRANSFORMERS_API_KEY` in Laraplate differs from `SENTENCE_TRANSFORMERS_API_KEY` on the embedding host (or `CROSS_ENCODER_API_KEY` for `/score`) |
| HTTP 400 `model "…" is not allowed on this host` | The active or probed profile's service model is neither the host's `EMBEDDING_MODEL` nor in its `EMBEDDING_ALLOWED_MODELS` |
| HTTP 400 `too many texts` / HTTP 413 | `SENTENCE_TRANSFORMERS_BATCH_SIZE` above the host's `EMBEDDING_MAX_TEXTS`, or a batch over `EMBEDDING_MAX_REQUEST_MB` |
| Vector search off, `meta.vector_disabled` = `dimension_mismatch` | The index `dense_vector` dims differ from `search.vector.dimensions`; finish or resume the switch (`ai:embeddings:status`) |
| Keyword-only search documents | Embeddings job failed; check Horizon; run `ai:embeddings:repair` |
| `Embeddings count mismatch` | API returned fewer vectors than texts |

---

## Related documentation

| Document | Topic |
|----------|--------|
| [sentence-transformers-api](https://github.com/getlaraplate/sentence-transformers-api) | The self-hosted embedding service (code, systemd unit, README) |
| [README.md](../README.md) | AI module env reference |
| [SEARCH_AND_TRANSLATION.md](SEARCH_AND_TRANSLATION.md) | Indexing flow and repair |
| [rag/DEPLOYMENT.md](rag/DEPLOYMENT.md) | RAG vector store |
| [Core EVENT_ORCHESTRATION.md](../../Core/docs/EVENT_ORCHESTRATION.md) | Search pre-processing |

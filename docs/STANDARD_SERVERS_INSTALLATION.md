# Servers for embeddings, reranking and transcription

> **Status: Infinity is verified by a run, TEI and speaches are written from their documentation.** Read on 2026-10-23: Text Embeddings Inference (TEI) v1.9.4, Infinity 0.0.78, speaches v0.9.0-rc.3; their OpenAPI documents are kept in `tests/Fixtures/StandardServers` and the clients are tested against them. Infinity was run on 2026-10-23 on a workstation (see its section). Still open, in the plan `2026-10-22-ai-standard-servers.md` of the application repository: TEI built from source on the reference LXC (build time and size, whether the two default models load, vector equality) and speaches on a large file; this guide changes when they are done.

Laraplate does not run models inside PHP. The AI module calls **standard HTTP servers** on separate hosts (VM, LXC, bare metal), speaking OpenAI-style APIs. Any server that speaks the same API works; this guide documents the ones the module is tested against. It replaces the guides of the former `sentence-transformers-api` and `whisper-api` services, which are archived.

## What talks to what

| Feature | API the module speaks | Documented server | Alternatives |
|---|---|---|---|
| Embeddings (search indexing, vector search, documentation RAG) | OpenAI `POST /v1/embeddings` | TEI | Infinity (no Docker, own venv), Ollama, LocalAI, a cloud API |
| Reranking (`deep` search) | `tei`: `POST /rerank {query, texts}`; `cohere`: `POST /rerank {model, query, documents}` | TEI with a reranker model | Infinity, vLLM (`cohere`) |
| Transcription (media analysis) | OpenAI `POST /v1/audio/transcriptions` | speaches | LocalAI, any OpenAI-style server |

```text
[Laravel + Horizon] --POST /v1/embeddings--> [TEI :8080, model e5]      (AI_EMBEDDINGS_URL)
                    --POST /v1/embeddings--> [TEI :8082, model MiniLM]  (AI_EMBEDDINGS_MINILM_URL)
                    --POST /rerank---------> [TEI :8081, reranker]      (AI_RERANK_URL)
                    --POST /v1/audio/transcriptions--> [speaches]       (AI_TRANSCRIPTION_URL)
```

Restrict inbound access on every server host to trusted clients (VPN, private network, firewall) and set an API key on both sides: a server started without one is open.

## Configuration

URLs, keys and timeouts are environment variables read by `config/ai.php` (`ai.servers`); the settings in Filament only choose among the servers the installer declared. Each URL is the **root** of the server, with no trailing path; the one exception is the reranker URL of an Infinity started with `--url-prefix`, which carries that prefix (see its section).

| Variable | Default | Purpose |
|---|---|---|
| `AI_EMBEDDINGS_URL` | unset | Default embeddings server. Unset: no profile that uses it is offered |
| `AI_EMBEDDINGS_API_KEY` | unset | Bearer key of that server |
| `AI_EMBEDDINGS_TIMEOUT` | `30` | Seconds an embeddings request may take; a CPU-bound server is slow |
| `AI_EMBEDDINGS_BATCH_SIZE` | `32` | Documents per request; the client halves it when a request fails or is slow. TEI refuses more than its `--max-client-batch-size` (32 by default) |
| `AI_EMBEDDINGS_MINILM_URL`, `AI_EMBEDDINGS_MINILM_API_KEY` | unset | The server of the profile `openai_compatible:all-MiniLM-L6-v2`; unset means the default server |
| `AI_RERANK_URL`, `AI_RERANK_API_KEY` | unset | Reranker. Unset: no reranking |
| `AI_RERANK_DIALECT` | `tei` | `tei` or `cohere` |
| `AI_RERANK_MODEL` | unset | Model to rank with; required for `cohere` |
| `AI_TRANSCRIPTION_URL`, `AI_TRANSCRIPTION_API_KEY` | unset | Transcription server. Unset: media index without a transcript |
| `AI_TRANSCRIPTION_TIMEOUT` | `120` | Seconds a transcription request may take |

A profile of `ai.features.embeddings.models` may name a server (`'server' => 'embeddings_minilm'`); a named server without a URL falls back to the default one. TEI serves **one model per process**, so a deployment that uses two embedding models runs two TEI processes and sets two URLs; Infinity, Ollama and LocalAI serve many models from one process and need only `AI_EMBEDDINGS_URL`. The profile (`openai_compatible:<model>`) names the model the server must run; the module sends it in every request and refuses an answer from another model.

The transcription models the dropdown offers are the list `ai.features.media_analysis.transcription.models` (default `Systran/faster-whisper-base`); add the ones your server serves, as it names them.

## Text Embeddings Inference (embeddings and reranking)

TEI has no pip package and no binary on its releases; without Docker it is built once from source (`libssl-dev` and `gcc` needed):

```bash
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh
git clone --branch v1.9.4 https://github.com/huggingface/text-embeddings-inference
cd text-embeddings-inference && cargo install --path router -F mkl
```

One process per model, for example under systemd (`ExecStart=`):

```bash
text-embeddings-router --model-id intfloat/multilingual-e5-small --port 8080 --api-key "$KEY" --max-client-batch-size 32
text-embeddings-router --model-id cross-encoder/mmarco-mMiniLMv2-L12-H384-v1 --port 8081 --api-key "$KEY" --max-client-batch-size 64
```

- **Do not pass `--default-prompt`.** Laraplate adds the `query: ` and `passage: ` prefixes itself (`PrefixingEmbeddingsProvider`); a server that adds them too embeds `query: query: ...`. The probe cannot detect it.
- `--max-client-batch-size` must be at least the embeddings batch size (32) and, for the reranker, 64, the most pairs one rerank call carries; `ai:servers:check` reads it from `GET /info` and says when it is lower.
- `GET /health` and `/metrics` stay public; every other route needs the key. The model must be one TEI supports: whether the two default models load and score correctly is part of the open verification.
- Rerank scores come back in [0, 1] (TEI applies a sigmoid unless `raw_scores`); the module never asks for raw scores.

## Infinity (no Docker, alternative)

**Verified on 2026-10-23** (Infinity 0.0.78, CPU, Python 3.12, a 14-core workstation, not the reference LXC): both default models load, vectors are identical to what `sentence-transformers` produces for the same prefixed text (cosine 0.99999999, largest difference 0.0), the reranker returns exactly the sigmoid of the `CrossEncoder` logits (difference 0.0, same order), and `ai:servers:check`, `ai:embeddings:probe` and the clients of the module pass against it.

Infinity v1 needs its own virtual environment: it requires Python below 3.14 (a host whose only Python is 3.14 cannot install it; Debian 12 and 13 ship 3.11 and 3.13), `numpy<2`, `sentence-transformers<4` and `transformers<=5.0`, so it cannot share `/opt/ai-env` with newer pins. Version 0.0.78 is a maintenance release of v1; Infinity V3 has a source-available, restricted licence. On a CPU-only host install the CPU build of torch first, or pip pulls the CUDA wheels:

```bash
python3.12 -m venv /opt/infinity-venv
/opt/infinity-venv/bin/pip install torch --index-url https://download.pytorch.org/whl/cpu
/opt/infinity-venv/bin/pip install 'infinity_emb[server,torch]==0.0.78'
/opt/infinity-venv/bin/infinity_emb v2 --model-id intfloat/multilingual-e5-small --model-id cross-encoder/mmarco-mMiniLMv2-L12-H384-v1 \
  --engine torch --device cpu --port 7997 --api-key "$KEY" --url-prefix /v1
```

The extras `server` and `torch` are what the module needs (the venv was 1.2 GB with them); `[all]` was not tried. The two models download from Hugging Face at the first start (about 470 MB each).

- `--url-prefix /v1` puts `/embeddings`, `/rerank`, `/models` and `/openapi.json` under `/v1`, where the embeddings client sends its requests; `/health` stays at the root, without the prefix. Point the reranker at the same prefix: `AI_RERANK_URL=http://host:7997/v1`, `AI_RERANK_DIALECT=cohere`, `AI_RERANK_MODEL=cross-encoder/mmarco-mMiniLMv2-L12-H384-v1`; `AI_EMBEDDINGS_URL=http://host:7997` is the root.
- One process serves both models; the profile's model is sent in each request. A model name that is not loaded answers `400` with a message listing the loaded ones; asking an embedding model to rerank, or the reverse, answers `400` too. A rerank request without `model` is refused (the `cohere` dialect always sends it).
- The key protects `/v1/embeddings`, `/v1/rerank` and `/v1/models`; `/health` and `/v1/openapi.json` answer without it. `/health` is a liveness answer only.
- Infinity does not echo `X-Request-Id`; the id is in the Laravel log and in the log of a reverse proxy that records request headers.
- The vectors have norm 1. Texts of 12 000 characters and empty texts are accepted (long ones are truncated to the model's limit); an empty batch is a `422`; batches of 200 texts are accepted. The `dimensions` request field truncates the vector for any model (256 gave 256 components) and is never sent by the module.
- Speed seen on that workstation: 64 pairs reranked in 0.7 s, 64 texts embedded in 0.5 s; Infinity's own warm-up measured 5.8 s for 32 texts of 512 tokens (5.5 texts per second at full length). The reference LXC has 2 vCPU: measure there before sizing batches and timeouts.

## Transcription (speaches or any OpenAI-style server)

The module posts the file and the model of the transcription choice (`features.media_analysis.transcription.model`, for example `openai_compatible:Systran/faster-whisper-base`) to `POST /v1/audio/transcriptions` with `response_format=json` and reads only `text`. Install and start speaches as its own documentation says (it is `uv`-based, Python 3.12, and faster-whisper runs on CPU), set its API key if it has one, and:

```env
AI_TRANSCRIPTION_URL=http://speaches-host:8000
AI_TRANSCRIPTION_API_KEY=...
```

The module does not limit the size or the duration of an upload and no limit was found in the settings of speaches: put a body-size limit on the reverse proxy in front of it, and size the timeout (`AI_TRANSCRIPTION_TIMEOUT`) for the longest file you accept. A transcription that fails (a `413` from the proxy, a busy server, a timeout) leaves the media indexed without a transcript and logs one warning with the request id.

## Checking a deployment

```bash
php artisan ai:servers:check          # probes every configured server; exit code 1 when one is misconfigured
php artisan ai:embeddings:probe openai_compatible:intfloat/multilingual-e5-small
```

`ai:servers:check` sends each embedding profile a probe through the server it names and checks the model, the vector length, the norm (a profile that declares `normalize` needs vectors of norm 1) and the batch limit; it asks the reranker to score two fixed pairs; transcription is listed but not probed, because it would need audio.

A failed call logs `Self-hosted AI service call failed` with `service` (`embeddings`, `rerank`, `transcription`), `endpoint` (no credentials), `status` and a `request_id`, which is also sent as the `X-Request-Id` header: where the server or its reverse proxy logs request headers, the same id leads to the line of that call.

## Switching the embedding model

`ai:embeddings:switch <profile>` checks that the server of the target profile answers, runs the profile's model, returns vectors of its dimensions and norm, then re-embeds and reindexes in the background. With TEI the target model needs its own process first: start it, set the URL of the profile's server (for example `AI_EMBEDDINGS_MINILM_URL`), run `php artisan ai:servers:check`, then switch. The same model served by another server gives the same vectors (up to numeric noise), so moving from the former service to TEI with the same model does not need re-embedding; the profile key changed from `sentence_transformers:` to `openai_compatible:`, so a development database is reseeded or switched with `ai:embeddings:switch`.

## From the former services

| Before | Now |
|---|---|
| `SENTENCE_TRANSFORMERS_URL`, `_API_KEY`, `_TIMEOUT`, `_BATCH_SIZE` | `AI_EMBEDDINGS_URL`, `_API_KEY`, `_TIMEOUT`, `_BATCH_SIZE` |
| `CROSS_ENCODER_URL`, `CROSS_ENCODER_API_KEY` | `AI_RERANK_URL`, `AI_RERANK_API_KEY`, plus `AI_RERANK_DIALECT` and, for `cohere`, `AI_RERANK_MODEL` |
| `WHISPER_URL`, `WHISPER_API_KEY`, `WHISPER_TIMEOUT` | `AI_TRANSCRIPTION_URL`, `AI_TRANSCRIPTION_API_KEY`, `AI_TRANSCRIPTION_TIMEOUT` |
| profile `sentence_transformers:<model>` | `openai_compatible:<model>` |
| transcription choice `whisper` | `openai_compatible:<model>` |
| `GET /health` with `api_version`, `/models`, `/ready` | none: `ai:servers:check` probes each server |

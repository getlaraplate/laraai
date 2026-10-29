# Servers for embeddings, reranking and transcription

> **Status: Infinity and the TEI Docker image were run (2026-10-23 and 2026-10-29); the rest is written from the servers' own documentation.** Read on 2026-10-23: Text Embeddings Inference (TEI) v1.9.4, Infinity 0.0.78, speaches v0.9.0-rc.3; their OpenAPI documents are kept in `tests/Fixtures/StandardServers` and the clients are tested against them. What is still open (TEI as a reranker, a successful TEI build from source, speaches, behaviour on a large file) is recorded in the Verification section of `docs/superpowers/specs/2026-10-22-ai-standard-servers-design.md` in the application repository; this guide changes when it is done.

Laraplate does not run models inside PHP. The AI module calls **standard HTTP servers** on separate hosts (VM, LXC, bare metal), speaking OpenAI-style APIs. Any server that speaks the same API works; this guide documents the ones the module is tested against. It replaces the guides of the former `sentence-transformers-api` and `whisper-api` services, which are archived.

## What talks to what

| Feature | API the module speaks | Documented server | Alternatives |
|---|---|---|---|
| Embeddings (search indexing, vector search, documentation RAG) | OpenAI `POST /v1/embeddings` | TEI | Infinity (no Docker, own venv), Ollama, LocalAI, a cloud API |
| Reranking (`deep` search) | `tei`: `POST /rerank {query, texts}`; `cohere`: `POST /rerank {model, query, documents}` | TEI with a reranker model | Infinity, vLLM (`cohere`) |
| Transcription (media analysis) | OpenAI `POST /v1/audio/transcriptions` | speaches | LocalAI, any OpenAI-style server |

```text
[Laravel + Horizon] --POST /v1/embeddings--> [TEI :8080, model e5]      (AI_EMBEDDINGS_URL)
                    --POST /rerank---------> [TEI :8081, reranker]      (AI_RERANK_URL)
                    --POST /v1/audio/transcriptions--> [speaches]       (AI_TRANSCRIPTION_URL)
```

Restrict inbound access on every server host to trusted clients (VPN, private network, firewall) and set an API key on both sides: a server started without one is open.

## Configuration

URLs, keys and timeouts are environment variables read by `config/ai.php` (`ai.servers`); the settings in Filament only choose among the servers the installer declared. Each URL is the **root** of the server, with no trailing path.

| Variable | Default | Purpose |
|---|---|---|
| `AI_EMBEDDINGS_URL` | unset | Default embeddings server. Unset: no profile that uses it is offered |
| `AI_EMBEDDINGS_API_KEY` | unset | Bearer key of that server |
| `AI_EMBEDDINGS_TIMEOUT` | `30` | Seconds an embeddings request may take; a CPU-bound server is slow |
| `AI_EMBEDDINGS_BATCH_SIZE` | `32` | Documents per request; the client halves it when a request fails or is slow. TEI refuses more than its `--max-client-batch-size` (32 by default) |
| `AI_RERANK_URL`, `AI_RERANK_API_KEY` | unset | Reranker. Unset: no reranking |
| `AI_RERANK_DIALECT` | `tei` | `tei` or `cohere` |
| `AI_RERANK_MODEL` | unset | Model to rank with; required for `cohere` |
| `AI_TRANSCRIPTION_URL`, `AI_TRANSCRIPTION_API_KEY` | unset | Transcription server. Unset: media index without a transcript |
| `AI_TRANSCRIPTION_TIMEOUT` | `120` | Seconds a transcription request may take |

The module ships one embedding profile, `openai_compatible:intfloat/multilingual-e5-small`, served by the default server. A profile of `ai.features.embeddings.models` may name another server of `ai.servers` (`'server' => 'embeddings_other'`, an entry with its own `url` and `api_key`); a named server without a URL falls back to the default one. TEI serves **one model per process**, so a deployment that adds a second embedding model runs a second TEI process and declares its server and profile in configuration; Infinity, Ollama and LocalAI serve many models from one process and need only `AI_EMBEDDINGS_URL`. The profile (`openai_compatible:<model>`) names the model the server must run; the module sends it in every request and refuses an answer from another model.

The transcription models the dropdown offers are the list `ai.features.media_analysis.transcription.models` (default `Systran/faster-whisper-base`); add the ones your server serves, as it names them.

## Text Embeddings Inference (embeddings and reranking)

TEI has no pip package and no binary on its releases. **Use the Docker image**: it carries Intel MKL and needs no build. One container per model, with a memory cap so that it cannot starve the other services of the host:

```bash
docker run -d --name tei-e5 --restart unless-stopped --memory 1500m --memory-swap 1500m \
  -p 8080:80 -v /opt/tei-data:/data \
  ghcr.io/huggingface/text-embeddings-inference:cpu-1.9.4 \
  --model-id intfloat/multilingual-e5-small --api-key "$KEY" --max-client-batch-size 32
```

`cpu-1.9.4` is the CPU image of the documented release. The container listens on port 80, the first start downloads the model (about 500 MB), and the e5 container used 858 MiB after its first request on a 4 GB host.

Without Docker TEI is built once from source (`libssl-dev` and `gcc` needed), and the build **needs a recent Intel oneMKL**: `-F mkl` links `libmkl_*`, and the package of Ubuntu 22.04 (`intel-mkl` 2020.4) is too old, the link fails with `undefined symbol: hgemm_`. Install a newer oneMKL and point `MKLROOT` at it before building (the build script of `intel-mkl-src` reads that variable); the minimum version was not measured, and a source build with the distribution's MKL was tried and does not link.

```bash
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh
git clone --branch v1.9.4 https://github.com/huggingface/text-embeddings-inference
cd text-embeddings-inference && cargo install --path router -F mkl
```

One process per model. With a binary built from source, for example under systemd (`ExecStart=`); the Docker image takes the same arguments after its name:

```bash
text-embeddings-router --model-id intfloat/multilingual-e5-small --port 8080 --api-key "$KEY" --max-client-batch-size 32
text-embeddings-router --model-id cross-encoder/mmarco-mMiniLMv2-L12-H384-v1 --port 8081 --api-key "$KEY" --max-client-batch-size 64
```

- **Do not pass `--default-prompt`.** Laraplate adds the `query: ` and `passage: ` prefixes itself (`PrefixingEmbeddingsProvider`); a server that adds them too embeds `query: query: ...`. The probe cannot detect it.
- `--max-client-batch-size` must be at least the embeddings batch size (32) and, for the reranker, 64, the most pairs one rerank call carries; `ai:servers:check` reads it from `GET /info` and says when it is lower.
- `GET /health` and `/metrics` stay public; every other route needs the key. The model must be one TEI supports: `intfloat/multilingual-e5-small` loads and passes `ai:servers:check` (Docker image, 2026-10-29); the reranker model was not run on TEI.
- Rerank scores come back in [0, 1] (TEI applies a sigmoid unless `raw_scores`); the module never asks for raw scores.

## Infinity (no Docker, alternative)

Infinity v1 needs its own virtual environment (Python below 3.14, `numpy<2`, `sentence-transformers<4`, `transformers<=5.0`): it cannot share `/opt/ai-env` with newer pins. Version 0.0.78 is a maintenance release of v1; Infinity V3 has a source-available, restricted licence.

```bash
python3.13 -m venv /opt/infinity-venv
/opt/infinity-venv/bin/pip install 'infinity_emb[all]==0.0.78'
/opt/infinity-venv/bin/infinity_emb v2 --model-id intfloat/multilingual-e5-small --model-id cross-encoder/mmarco-mMiniLMv2-L12-H384-v1 \
  --engine torch --device cpu --port 7997 --api-key "$KEY" --url-prefix /v1
```

- Infinity publishes `/embeddings` and `/rerank` under its `--url-prefix` (confirm the flag with `infinity_emb v2 --help`). Start it with `/v1`, which is where the embeddings client sends its requests, and point the reranker at the same prefix: `AI_RERANK_URL=http://host:7997/v1`, `AI_RERANK_DIALECT=cohere`, `AI_RERANK_MODEL=cross-encoder/mmarco-mMiniLMv2-L12-H384-v1`.
- One process serves several models; the profile's model is sent in each request. `GET /health` of Infinity is a liveness answer only.

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

`ai:embeddings:switch <profile>` checks that the server of the target profile answers, runs the profile's model, returns vectors of its dimensions and norm, then re-embeds and reindexes in the background. With TEI the target model needs its own process first: start it, set the URL of the profile's server, run `php artisan ai:servers:check`, then switch. The same model served by another server gives the same vectors (up to numeric noise), so moving from the former service to TEI with the same model does not need re-embedding; the profile key changed from `sentence_transformers:` to `openai_compatible:`, so a development database is reseeded or switched with `ai:embeddings:switch`.

## From the former services

| Before | Now |
|---|---|
| `SENTENCE_TRANSFORMERS_URL`, `_API_KEY`, `_TIMEOUT`, `_BATCH_SIZE` | `AI_EMBEDDINGS_URL`, `_API_KEY`, `_TIMEOUT`, `_BATCH_SIZE` |
| `CROSS_ENCODER_URL`, `CROSS_ENCODER_API_KEY` | `AI_RERANK_URL`, `AI_RERANK_API_KEY`, plus `AI_RERANK_DIALECT` and, for `cohere`, `AI_RERANK_MODEL` |
| `WHISPER_URL`, `WHISPER_API_KEY`, `WHISPER_TIMEOUT` | `AI_TRANSCRIPTION_URL`, `AI_TRANSCRIPTION_API_KEY`, `AI_TRANSCRIPTION_TIMEOUT` |
| profile `sentence_transformers:<model>` | `openai_compatible:<model>` |
| transcription choice `whisper` | `openai_compatible:<model>` |
| `GET /health` with `api_version`, `/models`, `/ready` | none: `ai:servers:check` probes each server |

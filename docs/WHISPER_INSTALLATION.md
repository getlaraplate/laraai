# Self-hosted Whisper for media transcription

Laraplate does not transcribe audio/video inside PHP. The AI module calls a
**self-hosted HTTP API** on a separate host (VM/LXC — e.g. a Proxmox guest),
exactly like the embedding service. This guide covers the Laraplate side and
points at the canonical service repo. Mirrors
[`SENTENCE_TRANSFORMERS_INSTALLATION.md`](SENTENCE_TRANSFORMERS_INSTALLATION.md).

**Canonical service:** <https://github.com/getlaraplate/whisper-api> (Flask
served by gunicorn +
[faster-whisper](https://github.com/SYSTRAN/faster-whisper)/CTranslate2). Its
README has the full install steps, every service variable and the concurrency
model; this file is the integration + sizing note.

---

## What uses the transcriber

Transcription is part of **media analysis**. Media analysis is switched on from
Filament > Settings (`features.media_analysis.enabled`), and the transcription
provider is the setting `features.media_analysis.transcription.model`, whose only
choice is `whisper`. The Whisper model itself is chosen on the Whisper host with
`WHISPER_MODEL`: Laraplate does not send one.

| Key (`ai.*`) | Purpose | Default |
|---------------------|---------|---------|
| `features.media_analysis.transcription.model` (setting) | Which transcription provider runs | `whisper` |
| `providers.whisper.url` | Base URL of the Whisper service | *(unset = transcription off)* |
| `providers.whisper.api_key` | Bearer token, the same value as the service's `WHISPER_API_KEY` | *(unset = no auth)* |
| `providers.whisper.timeout` | Per-request timeout (seconds) | `120` |

With `WHISPER_URL` unset the `WhisperTranscriber` is a no-op: audio/video media
still index (deterministic layer + any other analysis), just without a transcript.

---

## Architecture

```text
[Laravel + Horizon]  --POST /transcribe (multipart file, Bearer key)-->  [Whisper host: gunicorn + Flask + faster-whisper]
     .env: WHISPER_URL=http://HOST:8001
```

The Laravel app needs outbound HTTP to the Whisper host. Restrict inbound access
on that host (VPN/private network/firewall) and set `WHISPER_API_KEY` for a
shared-secret Bearer check.

---

## HTTP contract

Laraplate posts to `{base_url}/transcribe` (no trailing slash on the base URL).

```http
POST /transcribe
Authorization: Bearer <WHISPER_API_KEY>   # only when a key is configured
Content-Type: multipart/form-data

file=<the media bytes>        # form field name MUST be "file"
```

Response: `{ "text": "...", "language": "en", "duration": 12.3 }`. Only `text` is
used; any non-2xx response makes the transcriber return `null` (media still indexes).
The non-2xx answers of the service itself: `400` without a `file`, `401` without
a valid key, `413` for a file over its `WHISPER_MAX_UPLOAD_MB` (default 512 MB).

The service transcribes **one file at a time** by default (`WHISPER_NUM_WORKERS=1`)
and has no job queue: a second upload waits until the first is done, and the
wait counts against `WHISPER_TIMEOUT` (default 120 s). Raise the timeout for long
media, or for several media analysed at once, rather than the service's
parallelism on a CPU host. Details in the service README, section
[Concurrency](https://github.com/getlaraplate/whisper-api#concurrency).

---

## Install (Debian LXC on Proxmox, no Docker — how the `ai` host is set up)

The `ai` host already runs the embedding service on `:8000` from a shared
`/opt/ai-env`; Whisper reuses that venv and takes `:8001`. The unit runs gunicorn
as the dedicated unprivileged user `whisper-api` and caches the model in
`/var/cache/whisper-api`; create the user once:

```bash
apt install -y ffmpeg                                  # decodes audio/video
useradd --system --user-group --no-create-home --shell /usr/sbin/nologin whisper-api
git clone https://github.com/getlaraplate/whisper-api.git /opt/whisper-api
/opt/ai-env/bin/pip install -r /opt/whisper-api/requirements.txt
printf 'WHISPER_API_KEY=%s\n' "$(openssl rand -hex 24)" > /opt/whisper-api/.env
chmod 600 /opt/whisper-api/.env
cp /opt/whisper-api/whisper.service /etc/systemd/system/whisper.service
systemctl daemon-reload && systemctl enable --now whisper
curl -s http://127.0.0.1:8001/health          # {"status":"healthy","model":"base",...}
```

The secret lives only in `/opt/whisper-api/.env` (git-ignored, root-only: systemd
reads it before dropping privileges), never in the repo.
GPU: run with `WHISPER_DEVICE=cuda WHISPER_COMPUTE_TYPE=float16`.

A host upgraded from the old unit, which ran as `root`, downloads the model again
into `/var/cache/whisper-api` unless it is copied there first; the service README
has the commands.

---

## Model sizing

| `WHISPER_MODEL` | Notes |
|-----------------|-------|
| `tiny`/`base` | Fast, ~1 GB RAM. **`base` is the default** and the right pick on the current 4 GB / 2 vCPU test container (which also runs embeddings). |
| `small` | Better accuracy, ~1.5-2 GB — tight alongside embeddings; raise the LXC RAM first. |
| `medium`/`large-v3` | Won't fit on 4 GB; needs more RAM or a GPU/dedicated container. |

Change the model with `WHISPER_MODEL` (systemd unit or `/opt/whisper-api/.env`) and
`systemctl restart whisper`.

---

## Wire into Laraplate

`laraplate/.env`:

```dotenv
WHISPER_URL=http://ai:8001
WHISPER_API_KEY=<the key from /opt/whisper-api/.env>
```

Then turn on `features.media_analysis.enabled` in Filament > Settings.

Verify: analyze an audio/video media and confirm its `ai_media_analyses` row (by
`content_hash`) has a non-empty `transcript`. If the service is down or misconfigured,
transcription silently degrades to `null` and the rest of the analysis still runs.

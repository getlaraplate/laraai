---
module: ai
audience: user
cross_cutting_user: true
---
# Choosing the AI model of each feature: user guide

## Where

Filament > Settings, group `ai`. Every AI feature has its own setting:

| Setting | Feature |
|---|---|
| `features.chat.model` | Chat |
| `features.text_generation.model` | One-shot text generation (for example ownership suggestions) |
| `features.moderation.model` | AI moderation of content and comments |
| `features.search_orchestration.model` | Search planning |
| `features.translation.model` | Automatic translation |
| `features.faq.model` | FAQ answers from the documentation |
| `features.contextual_suggestions.model` | Contextual suggestions |
| `features.chat.summary.model` | Chat summaries and memory |
| `features.media_analysis.vision.model` | Image analysis (caption, OCR, idea, intent) |
| `features.media_analysis.transcription.model` | Audio and video transcription |

Media analysis itself is switched on with `features.media_analysis.enabled` (off by default).

## The value

A choice reads `provider:model`, for example `anthropic:claude-sonnet-5` or `ollama:llama3.2:3b`. One
provider has no model to choose and appears by name alone: `deepl` (translation). Transcription uses the
`openai_compatible` provider, with a model such as `openai_compatible:Systran/faster-whisper-base`.

Translation takes either `deepl` or an AI model. There is no fallback: if the chosen provider fails, the
translation is not saved and is retried later, instead of storing the original text.

## Refreshing the list

The drop-down lists the models the providers themselves offer. The list is refreshed:

- every night;
- on demand, with the play icon at the start of the setting's row. The refresh runs in the background
  ("Command queued"): the new list appears once the queue has processed it.

Only configured providers appear (an API key or a URL set by whoever runs the installation), and only
models able to do the feature's job: for chat, models that can chat and call tools; for image analysis,
models that accept images. When a provider does not say what a model can do, the model is listed anyway.

If a provider is down during a refresh, its models stay in the list and the refresh reports the error.
If a provider is no longer configured, its models leave the list.

## A model that disappears

If the model you chose is no longer offered, it stays selected and keeps being used; the grid and the
form flag it so you can pick another one. Nothing replaces it for you.

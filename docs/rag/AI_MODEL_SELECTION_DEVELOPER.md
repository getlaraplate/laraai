# AI model selection: developer and operator guide

## The pieces

| Piece | Where | Role |
|---|---|---|
| `AiModelFeature` | `app/Enums/AiModelFeature.php` | One case per feature: setting name, supported providers, required capabilities, default choice |
| `AiModelChoice` | `app/Ai/Providers/AiModelChoice.php` | Parses and formats `provider:model` (or `provider`), split on the **first** `:` because Ollama ids contain one |
| `ChatAgent::forFeature()` | `app/Ai/Agents/ChatAgent.php` | An agent on a feature's provider and model |
| `ModelLister` + implementations | `app/Ai/Providers/Models/` | Read a provider's model list |
| `ProviderConfiguration` | same | Whether a provider is configured, and its lister |
| `ModelCatalog` | same | Builds each feature's choices |
| `ai:models:refresh` | `app/Console/RefreshAiModelsCommand.php` | Writes the choices into the settings |

## Defaults live in code

A value managed by a setting has no config entry and no env variable. The seeder writes a setting's value
only when it creates the row, so an env variable would change nothing after the first seed and say
nothing about it. `AiModelChoice::forFeature($feature)` reads `ai.{setting name}`, which the database
overlay writes, and falls back to `AiModelFeature::defaultChoice()`:

| Feature | Default |
|---|---|
| chat, text generation, moderation, search orchestration, FAQ, contextual suggestions, chat summary | `ollama:llama3.2:3b` |
| translation | `deepl` |
| vision | `anthropic:claude-sonnet-5` |
| transcription | `openai_compatible:Systran/faster-whisper-base` |

`AIDatabaseSeeder::modelSettingDefinitions()` seeds one setting per feature with that default as value and
as only choice, through `commandManagedChoicesSettingsDefinition()` (a re-seed never overwrites refreshed
choices), with the action `ai:models:refresh --setting={name}`, queued (`action_queued = true`): a refresh
calls up to five providers in turn plus one Ollama request per installed model, which can outlast a web
request's time limit.

## Consumers

Every feature builds its agent with `ChatAgent::forFeature()`: `ChatService`, the text-generation
listener, `ModerationService`, `LlmSearchService` (unless constructed with an explicit provider),
`DocumentationAgent` (FAQ), `ContextualSuggestionService`, `MemoryService`. The guardrails feature and its
setting `features.guardrails.model` were removed on 2026-10-07 with `GuardrailsService`, which nothing called;
the in-app assistant is guarded by `AssistanceGuardrailPipeline` and its deterministic classifier, which use no model.
`MediaAnalysisModelRegistry` builds the vision and transcription profiles from their choices; the profile
key is the full choice and is stored as the analysis model version.

`ProviderFactory::make()` without a provider takes provider and model from the chat choice. With a
provider and no model it uses `OPENAI_MODEL`, `OLLAMA_MODEL`, `MISTRAL_MODEL`, or the constant
`claude-sonnet-4-20250514` for Anthropic.

## Providers and capabilities

A provider is configured when its API key (openai, anthropic, mistral; DeepL reads `core.deepl_api_key`)
its URL (ollama) or a server of `ai.servers` (`openai_compatible`: embeddings or transcription) is set. Ollama has no default URL: unset means not configured. `OLLAMA_API_URL`
is the server base (`http://host:11434`); chat, embeddings and the lister each append `/api`.

| Provider | Endpoint | Capabilities |
|---|---|---|
| OpenAI | `GET https://api.openai.com/v1/models`, Bearer | Not declared; ids of incompatible families are dropped (embedding, whisper, tts, audio, realtime, transcribe, dall-e, image, moderation, babbage, davinci, instruct), the rest is unknown |
| Anthropic | `GET https://api.anthropic.com/v1/models`, `x-api-key` + `anthropic-version: 2023-06-01`, paged with `after_id` | Chat and tools always; vision from `capabilities.image_input.supported`, assumed when `capabilities` is null |
| Mistral | `GET https://api.mistral.ai/v1/models`, Bearer | `capabilities.completion_chat`, `function_calling`, `vision`; archived models skipped |
| Ollama | `GET {url}/api/tags`, then `POST {url}/api/show` per model | `capabilities` (`completion`, `tools`, `vision`); unknown when missing or when `show` fails, except names containing `embed`, which are dropped |

DeepL has no lister: when configured, its entry is the provider name. The `openai_compatible` provider is listed from `ai.features.media_analysis.transcription.models` (`ConfiguredModelLister`, no request). Listers use a 3 s
connect timeout and a 10 s request timeout. A model with unknown capabilities satisfies every
requirement.

Required capabilities: chat needs chat and tools; vision needs vision; transcription needs none; every
other feature needs chat.

## `ModelCatalog` rules

- Each needed provider is called once per run.
- Not configured: its entries are dropped.
- Answering: its models with the required capabilities are listed (an empty answer lists none and is not
  a failure).
- Failing (connection error, HTTP error, an answer that is not a model list, or any other error): the entries it had in the current choices are kept, recognised
  by their `provider:` prefix, and the failure is reported.
- Choices are sorted.

## `ai:models:refresh`

```bash
php artisan ai:models:refresh
php artisan ai:models:refresh --setting=features.chat.model
```

- Without `--setting` it refreshes every model setting; with it, only that one. A name that is not a model
  setting fails and writes nothing.
- It prints each provider's outcome, each setting's number of choices, and a warning when the current value
  is no longer offered or no provider offers any model.
- Exit code 1 when a provider it called failed (the other providers' entries are still written).
- Scheduled daily at 03:00 on one server (`AIServiceProvider::registerCommandSchedules()`).

## Adding a feature

1. Add a case to `AiModelFeature` with its setting name, description, default choice, providers and
   capabilities.
2. Build the agent with `ChatAgent::forFeature(AiModelFeature::YourCase, $prompt)`.
3. Re-seed: the setting appears with its default and its refresh action.

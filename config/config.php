<?php

declare(strict_types=1);

return [
    // Rimappato automaticamente come ai.* quando il modulo è attivo
    // Se il modulo è disattivato, questa config non è disponibile

    // TODO: Future - User/Tenant-selectable AI provider
    // Implement a system where users or tenants can select their preferred AI provider.
    // This would involve:
    // 1. Storing provider preferences in user/tenant settings
    // 2. Creating a ProviderResolver service that checks user preferences
    // 3. Allowing per-conversation provider override
    // 4. Implementing provider capability detection (not all providers support tools, streaming, etc.)
    //
    // Example future config structure:
    // 'user_selectable_provider' => [
    //     'enabled' => env('AI_USER_SELECTABLE_PROVIDER', false),
    //     'allowed_providers' => ['ollama', 'openai', 'mistral', 'anthropic'],
    //     'default_for_new_users' => 'ollama', // Privacy-first default (local)
    // ],

    'features' => [
        'embeddings' => [
            // Optional per-module allowlist. Empty = every module (default).
            // When non-empty, only models whose owning module is listed are embedded,
            // e.g. ['cms']. Matched case-insensitively against the model's Modules\{Name}\ namespace.
            'modules' => [],

            // Records per chunk when an embedding model switch writes the search indexes
            // (`indexes` and `verify` phases): one IndexDocumentsChunkJob per model and key range
            // of this many records, on the `embeddings-index` queue. Not a setting. Below 1 or not a
            // number falls back to 250; above 2000 is capped.
            'index_chunk_size' => env('AI_EMBEDDINGS_INDEX_CHUNK_SIZE', 250),

            // Documentation source files per chunk when a switch rebuilds the Elasticsearch
            // documentation (RAG) indexes: one IndexDocumentsChunkJob per documentation profile and
            // range of this many files, on the `embeddings-index` queue. Below 1 or not a number
            // falls back to 20; above 200 is capped.
            'rag_chunk_size' => env('AI_EMBEDDINGS_RAG_CHUNK_SIZE', 20),

            // Seconds a chunked switch phase waits on a non-empty `embeddings-index` queue with no
            // chunk written before it fails naming the queue (a missing or dead worker). Default 900,
            // above the 760 s one chunk may take over its 3 tries. Below 1 or not a number: 900.
            'index_chunk_stall_seconds' => env('AI_EMBEDDINGS_INDEX_CHUNK_STALL_SECONDS', 900),

            // Seconds a writer of the switch state waits for the state lock before it fails (and its
            // job is retried). Below 1 or not a number: 10.
            'state_lock_wait_seconds' => env('AI_EMBEDDINGS_STATE_LOCK_WAIT_SECONDS', 10),

            // Minutes a job that the `embeddings` rate limiter keeps releasing may wait for its slot
            // before it dies: the time bound that replaces the number of tries (see
            // GenerateEmbeddingsJob::retryUntil()). A fixed value, not a setting.
            'retry_until_minutes' => 1440,

            // The model choice is the setting `features.embeddings.model` (overlaid as
            // `ai.features.embeddings.model`); the model that serves search is Core's managed
            // `search.vector.model`. EmbeddingModelRegistry falls back to the first configured profile below.
            // Profiles are keyed `provider:service_model`, split at the first colon. Each
            // declares the dimensions of its vectors; Core's `core.search.vector.*` follows.
            'models' => [
                'sentence_transformers:intfloat/multilingual-e5-small' => [
                    'dimensions' => 384,
                    'query_prefix' => 'query: ',
                    'passage_prefix' => 'passage: ',
                    'normalize' => true,
                ],
                'sentence_transformers:all-MiniLM-L6-v2' => [
                    'dimensions' => 384,
                    'query_prefix' => '',
                    'passage_prefix' => '',
                    'normalize' => true,
                ],
            ],
        ],
        // Seconds a search of the application content may take for one answer of the assistant,
        // clamped between 1 and 30 (ApplicationContentToolProvider).
        'application_content' => [
            'timeout_seconds' => 2,
        ],

        // Messages of a conversation after which MemoryService summarises it. The summary is enabled by
        // the setting `features.chat.summary.enabled`; MemoryService is not reached by the assistant today.
        'chat' => [
            'summary_threshold' => 20,
        ],

        // Media LLM analysis (M1-M21 in the media spec). The master switch is the
        // setting `features.media_analysis.enabled` and the models are the settings
        // `features.media_analysis.{vision,transcription}.model`; only the
        // per-module allowlist is config.
        'media_analysis' => [
            // Optional per-module allowlist. Empty = every module (default).
            // Matched by FeatureModuleGate against the model's Modules\{Name}\ namespace.
            'modules' => [],
        ],

        'translation' => [
            // Optional per-module allowlist. Empty = every module (default).
            // When non-empty, only models whose owning module is listed are translated,
            // e.g. ['cms']. Matched case-insensitively against the model's Modules\{Name}\ namespace.
            'modules' => [],
        ],
        // Optional one-shot text generation answering Core's
        // AiTextGenerationRequested event (e.g. SAO ownership-suggestion
        // phrasing). Opt-in: off unless explicitly enabled. Any failure or a
        // guard tripping leaves the request unfulfilled so the caller falls back.
        // Every value is the setting `features.text_generation.*` (AIDatabaseSeeder);
        // these are the defaults used until the settings are seeded.
        'text_generation' => [
            'enabled' => false,

            // Hard cap on the returned text; longer generations are truncated on
            // a word boundary rather than rejected.
            'max_output_chars' => 500,

            // Optional short-TTL cache keyed by (purpose, prompt hash). 0 = off.
            'cache_ttl_seconds' => 0,

            // Per-purpose rate limit; when exhausted the listener no-ops so a
            // burst cannot run up cost.
            'rate_limit' => [
                'max' => 60,
                'per_seconds' => 60,
            ],
        ],
        'faq' => [
            // Optional extra root for app-level custom docs. Default scan always includes `docs/rag` and active `Modules/*/docs/rag` (see `docs/README.md`).
            'documentation_path' => env('AI_FAQ_DOCS_PATH'),
            // The setting `features.faq.vector_store` (elasticsearch or filesystem); `memory` is for tests only.
            'vector_store' => 'elasticsearch',
            'vector_store_path' => env('AI_FAQ_VECTOR_STORE_PATH'), // null = storage_path('app/ai/faq-vectorstore.store'); the store writes exactly this file
            'elasticsearch' => [
                'developer_index' => env(
                    'AI_FAQ_DEVELOPER_ES_INDEX',
                    env('AI_FAQ_ES_INDEX', Str::slug(config('app.name')) . '_rag_docs'),
                ),
                'user_index' => env('AI_FAQ_USER_ES_INDEX', Str::slug(config('app.name')) . '_rag_user_docs'),
                // The vector length is the active embedding profile's `dimensions`
                // (ai.features.embeddings.models); a model switch rebuilds these indexes.
            ],
            // The setting `features.faq.policy_classification_version`.
            'policy_classification_version' => 'in-app-docs-v1',
            // The documentation search of the command palette (`GET /app/crud/search/{module}/documentation`):
            // every call costs an embedding, so each signed-in user has this many per minute.
            'search' => [
                'rate_per_minute' => 60,
            ],
            // Analytics of answered in-app documentation questions (docs/rag/query-analytics-privacy-review.md).
            // `enabled`, `query_text_mode` (raw or off) and `retention_days` are the settings
            // `features.faq.query_logging.*`; the index name is deployment.
            'query_logging' => [
                'enabled' => false,
                'query_text_mode' => 'raw',
                'retention_days' => 30,
                'index' => env('AI_FAQ_QUERY_LOG_INDEX', Str::slug(config('app.name')) . '_rag_queries'),
            ],
        ],
        'tools' => [
            // Default CRUD tools exposed to the in-app assistant, opt-in per entity.
            // Empty = no CRUD tools (default). Each key is "module.entity"; the value
            // lists the allowed operations. A tool is exposed only for an operation
            // the acting user is actually permitted to perform (otherwise it is not
            // offered at all). Every call is authorized and ACL-scoped by Core's
            // CrudService as the acting user. Moderation belongs to the model:
            // HasApprovals entities capture writes for approval on save unless the
            // writer holds the `approve` credit — the provider does not add its own
            // approval step. list/search accept structured `filters` and `sort`
            // (the CRUD request format), and every result echoes the executed
            // `request` (verb/module/entity/filters/sort/page/limit) so a client can
            // reapply the same filters to its tables. The `view` operation is
            // "configure mode": it returns only the request spec (apply=true) and
            // does NOT fetch data — the UI applies the filters and loads them itself.
            // Operations: view, list, detail, search, summarize, export, create,
            // update, delete, bulk_update, bulk_delete, plus the approval verbs
            // pending_approvals, approve, disapprove (gated by the `approve`
            // permission). `summarize` (gated by `select`) groups records and
            // returns per-group counts plus optional sum/avg/min/max metrics.
            // `export` (gated by `select`) returns an ACL-scoped, filtered
            // recordset as an inline base64 CSV or PDF file. `bulk_update`/
            // `bulk_delete` change many filter-matched records at once; they are
            // gated by `select` plus the write ability (`update`/`forceDelete`),
            // always preview first (confirm=false returns the match count and a
            // sample without changing anything), and refuse to apply above a
            // hard cap of 200 records.
            'crud' => [
                'entities' => [
                    // 'cms.content' => ['list', 'detail', 'search', 'create', 'update', 'delete'],
                ],
                // Entities whose model has no approvals, and on which the assistant may nonetheless
                // write. Empty = none (default). Listing one says "a write is applied directly, no
                // vote": the person still confirms it in the conversation, nobody else reviews it.
                // Entities with approvals need no entry here. `approve` and `disapprove` are never
                // offered to the assistant.
                'unmoderated_writes' => [
                    // 'cms.tag',
                ],
                // How long a write the assistant proposed can still be confirmed, in minutes.
                'proposal_ttl_minutes' => 30,
            ],
        ],
        // Limits and policy version of the in-app assistance guardrails (AssistanceGuardrailPipeline,
        // AssistantPolicyCatalog). They are mandatory and have no switch.
        'guardrails' => [
            'in_app_policy_version' => 'in-app-v1',
            'in_app_max_input_length' => 4000,
            'in_app_max_output_length' => 8000,
        ],
        'search_orchestration' => [
            // Whether `deep` and `balanced` searches may use the AI overlay at all. Read per request by
            // AiSearchStrategyResolver; when false they are served as `fast`.
            'enabled' => env('AI_SEARCH_ORCHESTRATION_ENABLED', true),
            // Seconds one LLM call of a `deep` search may take (intent parsing, planning) before the
            // search falls back to its rule-based answer. Neuron's own default is 60.
            'timeout' => (float) env('AI_SEARCH_LLM_TIMEOUT', 10),
        ],
        'moderation' => [
            // The setting `features.moderation.queue`, read when the job is dispatched.
            'queue' => 'default',
        ],
    ],

    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            // Optional: base URL of an OpenAI-compatible server (e.g. http://localhost:8080/v1). Chat only.
            'base_url' => env('OPENAI_BASE_URL'),
            'model' => env('OPENAI_MODEL'),
        ],

        'ollama' => [
            'api_url' => env('OLLAMA_API_URL'),
            'model' => env('OLLAMA_MODEL', 'llama3.2:3b'),
        ],

        'voyageai' => [
            'api_key' => env('VOYAGEAI_API_KEY'),
            'model' => env('VOYAGEAI_MODEL', 'voyage-3-lite'),
        ],

        'mistral' => [
            'api_key' => env('MISTRAL_API_KEY'),
            'model' => env('MISTRAL_MODEL', 'mistral-large-latest'),
        ],

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
        ],

        'sentence_transformers' => [
            'url' => env('SENTENCE_TRANSFORMERS_URL'),
            'api_key' => env('SENTENCE_TRANSFORMERS_API_KEY'),
            // Per-request HTTP timeout (seconds) and documents per /embed batch.
            // A CPU-bound embedding service is slow (~0.4s per long chunk), so keep
            // the batch small enough that one batch completes within the timeout.
            'timeout' => (int) env('SENTENCE_TRANSFORMERS_TIMEOUT', 30),
            'batch_size' => (int) env('SENTENCE_TRANSFORMERS_BATCH_SIZE', 32),
        ],

        // Self-hosted Whisper transcription service (Task 8). Unset URL = the
        // transcriber is a no-op (media index without a transcript). See
        // Modules/AI/docs/WHISPER_INSTALLATION.md to stand up the service.
        'whisper' => [
            'url' => env('WHISPER_URL'),
            'api_key' => env('WHISPER_API_KEY'),
            'timeout' => (int) env('WHISPER_TIMEOUT', 120),
        ],

        // Base URL of the service exposing POST /score (the client adds the path). It falls back to the
        // embedding service, which can serve /score too, and so does its key. No built-in address.
        'cross_encoder' => [
            'url' => env('CROSS_ENCODER_URL', env('SENTENCE_TRANSFORMERS_URL')),
            'api_key' => env('CROSS_ENCODER_API_KEY', env('SENTENCE_TRANSFORMERS_API_KEY')),
        ],
    ],
];

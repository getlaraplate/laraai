# RAG query analytics: privacy review

Decision record for the documentation-RAG query log (`docs/superpowers/specs/2026-09-16-rag-query-analytics-design.md`). Approved by the owner on 2026-10-08. The logger is built on this field set and nothing else; a change to any item below needs a new review.

## Field set

One document per answered documentation query, in its own Elasticsearch index (`{app}_rag_queries`), never mixed with document vectors and never read by the running application:

| Field | Content |
|---|---|
| `id`, `logged_at` | identity and timestamp |
| `user_ref` | HMAC-SHA256 of the user id, never the raw id |
| `tenant` | tenant scope |
| `profile` | assistant profile (in-app or developer) |
| `locale` | request locale |
| `query` | the question as typed, or absent (see below) |
| `retrieved_count`, `citation_count` | candidates found and grounded citations |
| `answered` | grounded answer or abstention |
| `latency_ms` | retrieval latency |
| `index` | the RAG index that served the query |
| `judge_rounds`, `judge_candidates`, `judge_kept` | integers: rounds of the relevance judge, documents it read, documents it kept; present only when the judge ran |
| `judge_fallback` | boolean: the judge failed or kept nothing and the unjudged documents were used; only when the judge ran |
| `judge_ms` | float: time of the judge calls in milliseconds; only when the judge ran |
| `judge_trace` | one `round=N candidates=N kept=1,3 ms=N` entry per round (numbers only, no text); only when the judge ran |
| `judge_queries` | the query of each judge round, one per line: the question and the reformulations the model wrote. Stored only with the query text mode `raw`, absent with `off`; only when the judge ran |
| `tokens_in_{role}`, `tokens_out_{role}` | integers, no text: tokens the model of a role (`answer`, `judge`, `intent`, `embedding`) read and wrote for the question. Only for a role whose provider reported usage, otherwise the field is absent |

Never stored: the answer text, document bodies, the raw user id, any secret.

The `judge_*` fields exist only when the optional relevance judge (`features.rag.judge.enabled`, off by default) ran, and the query-log index must be recreated (`ai:create-rag-query-index --force`) to hold them. `judge_queries` is the only one that carries text, and model-written text at that: the reformulations of the question that the judge model proposed. It is treated exactly like `query`, so it follows the query text mode below and is not written with `off`. The token fields are counters and hold no text.

## Query text: raw, or off

The question is stored as typed (`raw`) by default, because the purpose of the log is to see what the documentation does not answer, and a hash cannot be read. A hash of a short question is also easy to reverse by trying likely questions, so it would protect little. The `hashed` mode of the spec is dropped. `off` stores only the structured signal (counts, latency, locale, profile).

With `raw`, `judge_queries` is stored too, including the reformulations written by the model, which can repeat words of the question. A raw question may contain personal data a user typed. The short retention window and the per-user erasure below are what bound it.

## Settings, not environment variables

The switch, the query text mode and the retention window are seeded settings, edited in Filament:

| Setting | Default |
|---|---|
| `features.faq.query_logging.enabled` | `false` |
| `features.faq.query_logging.query_text_mode` | `raw` (`raw` or `off`) |
| `features.faq.query_logging.retention_days` | `30` |

The index name follows the other RAG indexes and stays in `config.php`.

## User reference and key

`user_ref = hash_hmac('sha256', (string) $userId, <key>)`, with `APP_KEY` as the key.

`APP_KEY` is not rotated on a schedule, only when it is compromised. A rotation moves the old key to `APP_PREVIOUS_KEYS` (the Laravel mechanism that keeps encrypted data readable). Documents written before a rotation carry a hash of the old key, so they no longer correlate with later ones; this is accepted.

## Retention

A daily job deletes the documents whose `logged_at` is older than `retention_days`.

## Who is logged

Only an identified user speaking for themselves. Guest and impersonated principals are never logged, on the same eligibility rule the in-app assistant applies.

## Erasure

An erasure request for a user deletes every document whose `user_ref` matches the HMAC of their id computed with `APP_KEY` and with each key in `APP_PREVIOUS_KEYS`, so documents written before a key rotation are erased too. Anything left over expires within `retention_days`.

# AI Headline Summaries Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the headline preview excerpt with a cached AI-generated summary when available, configurable from a dedicated AI preferences tab.

**Architecture:** Store non-personalized summaries on `ttrss_entries`, keyed to the article `content_hash`, and generate them during feed update for new or changed articles. Headline rendering only reads cached DB fields and falls back to the current 250-character content preview when no valid AI summary exists.

**Tech Stack:** PHP 8.5 in the tt-rss Docker preview, PostgreSQL schema migrations, Dojo-based prefs UI, Guzzle for OpenAI chat-completions-style HTTP calls, PHPUnit for focused unit tests.

---

## Behavioral Contract

- AI summaries are opt-in via a new AI preferences tab.
- AI endpoint default: `https://api.openai.com/v1/chat/completions`.
- AI model default: `gpt-4o-mini`.
- API key is editable in the AI tab and stored in `ttrss_user_prefs2` as a user preference. It is personal data: never log it, never export it, never commit it in config files.
- Summary generation happens during feed update for new articles or articles whose `content_hash` changes.
- Existing generated summaries are reused when `ai_summary_content_hash` equals the current `content_hash`; they are not regenerated on render or on unchanged feed updates.
- LLM failures must not break feed updates or headline rendering.
- The middle headline preview shows `ai_summary` when present and current; otherwise it shows the existing fallback preview.
- If `SHOW_CONTENT_PREVIEW` is disabled, no preview is shown, including AI summaries.

## File Map

- `classes/Prefs.php`: add AI preference constants and defaults.
- `classes/Pref_Prefs.php`: render and save a dedicated AI preferences pane.
- `prefs.php`: add top-level AI tab.
- `classes/Config.php`: bump schema version to `152`.
- `sql/pgsql/schema.sql`: add AI summary columns to fresh installs.
- `sql/pgsql/migrations/152.sql`: add AI summary columns to existing installs.
- `classes/Feeds.php`: select AI summary columns and prefer valid cached summaries.
- `classes/LLMClient.php`: OpenAI chat-completions-style client with Guzzle injection.
- `classes/AISummary.php`: prompt construction, content normalization, summary generation, and persistence helpers.
- `classes/RSSUtils.php`: call AI summary generation during article insert/update after final content is known.
- `tests/LLMClientTest.php`: mocked Guzzle tests for request/response/error behavior.
- `tests/AISummaryTest.php`: pure tests for prompt input truncation and cached-summary selection logic where practical.

## Task 1: Schema And Headline Read Path

**Files:**
- Modify: `classes/Config.php`
- Modify: `sql/pgsql/schema.sql`
- Create: `sql/pgsql/migrations/152.sql`
- Modify: `classes/Feeds.php`

- [ ] Add `ai_summary text`, `ai_summary_content_hash varchar(250)`, and `ai_summary_generated_at timestamp` to `ttrss_entries` in `schema.sql`.
- [ ] Create migration `152.sql` with the same three `alter table ttrss_entries add column ...` statements.
- [ ] Bump `Config::SCHEMA_VERSION` from `151` to `152`.
- [ ] Update headline queries in `Feeds::_get_headlines()` so fetched rows include `ai_summary`, `ai_summary_content_hash`, and `ai_summary_generated_at`.
- [ ] Update preview construction in `_format_headlines_list()`:
  - If `SHOW_CONTENT_PREVIEW` is false, keep `content_preview = ""`.
  - Else if `ai_summary` is non-empty and `ai_summary_content_hash === content_hash`, set `content_preview = "&mdash; " . Sanitizer::sanitize($line["ai_summary"])`.
  - Else keep current `truncate_string(strip_tags($line["content"]), 250)` fallback.
- [ ] Verify migration syntax by running `docker compose -f docker-compose.dev.yml exec app /usr/bin/php85 /var/www/html/tt-rss/update.php --update-schema=force-yes` as user `app` if the dev DB is running, or document why not.

## Task 2: AI Preferences Tab

**Files:**
- Modify: `classes/Prefs.php`
- Modify: `classes/Pref_Prefs.php`
- Modify: `prefs.php`

- [ ] Add preference constants:
  - `AI_SUMMARIES_ENABLED`
  - `AI_ENDPOINT`
  - `AI_MODEL`
  - `AI_API_KEY`
  - `AI_SUMMARY_MAX_CHARS`
- [ ] Add defaults:
  - `AI_SUMMARIES_ENABLED => [false, Config::T_BOOL]`
  - `AI_ENDPOINT => ["https://api.openai.com/v1/chat/completions", Config::T_STRING]`
  - `AI_MODEL => ["gpt-4o-mini", Config::T_STRING]`
  - `AI_API_KEY => ["", Config::T_STRING]`
  - `AI_SUMMARY_MAX_CHARS => [180, Config::T_INT]`
- [ ] Add these to `Prefs::_PROFILE_BLACKLIST` so they are account-level, not per profile.
- [ ] Add `index_ai` to `Pref_Prefs::csrf_ignore()`.
- [ ] Add `Pref_Prefs::index_ai()` with a Dojo form posting to `Pref_Prefs/saveconfig`.
- [ ] Render a checkbox for enable, text input for endpoint, text input for model, password input for API key, and numeric input for max chars.
- [ ] Include `boolean_prefs=AI_SUMMARIES_ENABLED` in the AI form.
- [ ] Add a top-level AI tab in `prefs.php` loading `backend.php?op=Pref_Prefs&method=index_ai`.
- [ ] Ensure no API key is printed anywhere except as the password field value for the logged-in user.

## Task 3: LLM Client

**Files:**
- Create: `classes/LLMClient.php`
- Create: `tests/LLMClientTest.php`

- [ ] Implement constructor accepting `GuzzleHttp\ClientInterface`.
- [ ] Implement `summarize(string $endpoint, string $model, string $apiKey, string $prompt, int $timeoutSeconds = 30): ?string`.
- [ ] POST JSON to the configured endpoint using OpenAI chat-completions format:
  - `model`
  - `messages`: system prompt plus user prompt
  - `temperature`: `0.2`
- [ ] Send `Authorization: Bearer <apiKey>` and `Content-Type: application/json`.
- [ ] Set `http_errors => false`, bounded connect/read timeout, and no logging of secret fields.
- [ ] Return trimmed `choices[0].message.content` on 2xx valid JSON.
- [ ] Return `null` on non-2xx, transport exception, invalid JSON, missing content, or empty content.
- [ ] Unit-test success, non-2xx, invalid JSON, empty choices, and request payload/header shape using Guzzle mock handlers.

## Task 4: Summary Service And Feed Update Integration

**Files:**
- Create: `classes/AISummary.php`
- Modify: `classes/RSSUtils.php`
- Create or modify tests as practical.

- [ ] Implement `AISummary::is_configured(int $owner_uid): bool`.
- [ ] Implement `AISummary::build_prompt(string $title, string $content, int $maxChars): string`:
  - strip HTML
  - normalize whitespace
  - limit prompt article text to a bounded size, e.g. 6000 UTF-8 chars
  - request concise summary in the article language when possible
  - require plain text only
- [ ] Implement `AISummary::generate_for_entry(int $entryId, int $ownerUid, string $title, string $content, string $contentHash): void`.
- [ ] `generate_for_entry()` must:
  - return immediately if AI is disabled or endpoint/model/API key missing
  - skip if DB already has a non-empty `ai_summary` with matching `ai_summary_content_hash`
  - call `LLMClient`
  - truncate returned summary to `AI_SUMMARY_MAX_CHARS`
  - update `ai_summary`, `ai_summary_content_hash`, `ai_summary_generated_at`
  - catch failures and never throw into feed update flow
- [ ] In `RSSUtils::update_rss_feed()`, call `AISummary::generate_for_entry()` after a new entry is inserted and after an existing entry is updated because content changed.
- [ ] Do not call AI for unchanged-hash fast path.
- [ ] Do not call AI from `Feeds::_format_headlines_list()` or hooks during render.

## Task 5: End-To-End Verification

**Files:**
- No new files expected.

- [ ] Run focused PHPUnit tests for `LLMClient` and any `AISummary` pure tests.
- [ ] Validate compose config: `docker compose -f docker-compose.dev.yml config`.
- [ ] Apply schema migration in the dev container.
- [ ] Open the AI prefs tab in the running preview and verify fields render.
- [ ] Save a dummy endpoint/model/key locally and verify no files changed.
- [ ] With AI disabled or dummy key, force a feed update and verify feed update still succeeds and previews fallback.
- [ ] With a mocked or reachable OpenAI-style endpoint if available, verify `ai_summary` is written once and not regenerated when `content_hash` is unchanged.
- [ ] Before any commit/push, run the pre-push privacy checks from `AGENTS.md`.

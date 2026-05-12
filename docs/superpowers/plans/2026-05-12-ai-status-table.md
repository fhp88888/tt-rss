# AI Summary Status Table Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show article-level AI summary progress in the AI preferences tab.

**Architecture:** Add a small account-scoped status helper to `AISummary` that counts current user's article rows by summary state. Render a readonly table in `Pref_Prefs::index_ai()` immediately below the enable checkbox and above endpoint settings.

**Tech Stack:** PHP 8.5, tt-rss prefs helpers, PostgreSQL aggregate queries.

---

### Task 1: AI Summary Status Helper

**Files:**
- Modify: `classes/AISummary.php`

- [ ] Add `AISummary::get_status(int $owner_uid): array` returning:
  - `total_articles`
  - `processed_articles`
  - `unprocessed_articles`
  - `queued_articles`

- [ ] Count rows through `ttrss_user_entries` scoped to the owner.

- [ ] Treat processed as `ai_summary` non-empty and `ai_summary_content_hash = content_hash`.

- [ ] Treat unprocessed as total minus processed.

- [ ] Treat queued as unprocessed because the runtime queue is in-memory during feed updates and no persistent queue table exists.

- [ ] Verification: PHP lint passes for `classes/AISummary.php`.

### Task 2: AI Tab Status UI

**Files:**
- Modify: `classes/Pref_Prefs.php`

- [ ] Call `AISummary::get_status($owner_uid)` in `index_ai()`.

- [ ] Render a compact readonly table between the enable checkbox fieldset and the endpoint fieldset.

- [ ] Display rows:
  - `Total articles`
  - `Processed`
  - `Unprocessed`
  - `Queued`

- [ ] Use existing translation wrapper `__()` for labels.

- [ ] Verification: PHP lint passes for `classes/Pref_Prefs.php`.

### Task 3: Integration Verification

**Files:**
- No code ownership; orchestrator only.

- [ ] Verify prefs page returns HTTP 200.

- [ ] Run a focused runtime check comparing `AISummary::get_status(1)` to direct SQL counts.

- [ ] Run `git diff --check`.

- [ ] Run privacy scan on changed files before commit.

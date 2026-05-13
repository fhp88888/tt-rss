# RSS Gallery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an Actions menu RSS Gallery dialog backed by a static `title,url` CSV, with right-side top-eight feed preview and explicit subscription.

**Architecture:** Put CSV parsing and feed XML preview formatting in a small `RssGallery` service class that is easy to unit test. Expose it through a protected `Rss_Gallery` backend handler, and keep the frontend in `CommonDialogs` plus a small CSS file loaded from `index.php`.

**Tech Stack:** PHP handlers and helpers, existing `UrlHelper`, `FeedParser`, `TimeHelper`, Dojo/dijit `fox.SingleUseDialog`, existing `xhr.json`, npm ESLint and stylelint.

---

## File Structure

- Create `classes/RssGallery.php`: pure helper methods for CSV loading and XML preview parsing.
- Create `classes/Rss_Gallery.php`: protected backend handler with `list` and `preview`.
- Create `data/rss-gallery.csv`: static curated CSV using `title,url` columns.
- Create `themes/rss-gallery.css`: dialog layout and preview grid styles.
- Modify `index.php`: load CSS and add Actions menu item.
- Modify `js/App.js`: dispatch `qmcRssGallery`.
- Modify `js/CommonDialogs.js`: render gallery dialog, load list, preview selected feed, subscribe selected feed.
- Create `tests/RssGalleryTest.php`: unit coverage for CSV parsing and XML preview formatting.

## Task 1: Backend Service and Unit Tests

**Files:**
- Create: `tests/RssGalleryTest.php`
- Create: `classes/RssGallery.php`

- [ ] **Step 1: Write failing CSV and preview tests**

Create `tests/RssGalleryTest.php` with tests for valid CSV rows, skipped invalid rows, and top-eight preview parsing from RSS XML.

- [ ] **Step 2: Run the new test and verify RED**

Run: `vendor/bin/phpunit tests/RssGalleryTest.php`

Expected: FAIL because `RssGallery` does not exist.

- [ ] **Step 3: Implement `classes/RssGallery.php`**

Add:

- `public static function readCsv(string $path): array`
- `public static function previewFromXml(string $xml, string $url, int $limit = 8): array`

Use `fgetcsv()` for CSV parsing, `UrlHelper::validate()` for URLs, `FeedParser` for RSS/Atom, and `TimeHelper::make_local_datetime()` for display date.

- [ ] **Step 4: Run the new test and verify GREEN**

Run: `vendor/bin/phpunit tests/RssGalleryTest.php`

Expected: PASS.

## Task 2: Protected Handler

**Files:**
- Create: `classes/Rss_Gallery.php`

- [ ] **Step 1: Add handler smoke coverage if practical**

If handler tests can instantiate protected handlers without DB/session setup, add a narrow test that `csrf_ignore()` allows `list` and `preview`. If that requires app session setup, skip handler unit tests and verify through Docker preview.

- [ ] **Step 2: Implement `Rss_Gallery`**

Add a protected handler extending `Handler_Protected`:

- `csrf_ignore()` returns true for `list` and `preview`.
- `list()` reads `data/rss-gallery.csv` and prints JSON `{feeds: [...]}`.
- `preview()` validates requested `url`, checks 10 minute `cache/rss-gallery/` JSON cache, fetches with `UrlHelper::fetch()`, parses through `RssGallery::previewFromXml()`, and prints JSON.
- Return structured errors as `{error: "message"}`.

- [ ] **Step 3: Run backend unit test**

Run: `vendor/bin/phpunit tests/RssGalleryTest.php`

Expected: PASS.

## Task 3: Frontend Dialog

**Files:**
- Modify: `index.php`
- Modify: `js/App.js`
- Modify: `js/CommonDialogs.js`
- Create: `themes/rss-gallery.css`

- [ ] **Step 1: Wire Actions menu**

Add `RSS Gallery` near the feed actions in `index.php`, and add `qmcRssGallery` dispatch in `js/App.js`.

- [ ] **Step 2: Implement `CommonDialogs.rssGallery()`**

Use `fox.SingleUseDialog` with:

- Left search input and feed list.
- Right preview header, Subscribe button, and `.rss-gallery-preview-grid`.
- `loadFeeds()` calling `{op: "Rss_Gallery", method: "list"}`.
- `previewFeed(url)` calling `{op: "Rss_Gallery", method: "preview", url}`.
- `subscribeSelected()` posting to existing `{op: "Feeds", method: "add", feed: url}` and reloading feed tree on success.

- [ ] **Step 3: Add CSS**

Style the dialog as two panes. The preview grid uses `grid-template-columns: repeat(2, minmax(0, 1fr))` and fixed card min-height so title/date loading does not shift the layout.

- [ ] **Step 4: Run JS and CSS lint**

Run: `npm run lint:js`

Expected: exit 0 or report existing unrelated errors.

Run: `npm run lint:css`

Expected: exit 0 or report existing unrelated errors.

## Task 4: Data and Preview Verification

**Files:**
- Create: `data/rss-gallery.csv`

- [ ] **Step 1: Add initial CSV data**

Add a curated subset from `amazingcoderpro/rss-recomanded` in CSV format:

```csv
title,url
知乎每日精选,https://www.zhihu.com/rss
阮一峰的网络日志,https://www.ruanyifeng.com/blog/atom.xml
少数派,https://sspai.com/feed
美团技术团队,https://rsshub.app/meituan/tech/home
V2EX,https://v2ex.com/index.xml
```

Include enough rows to make the gallery useful, without trying to mirror the entire README in the first implementation.

- [ ] **Step 2: Validate compose config**

Run: `docker compose -f docker-compose.dev.yml config`

Expected: exit 0.

- [ ] **Step 3: Start preview stack**

Run: `docker compose -f docker-compose.dev.yml up -d`

Expected: app stack starts.

- [ ] **Step 4: Fetch the app**

Run: `curl -s http://127.0.0.1:8280/tt-rss/`

Expected: HTML login/app response.

- [ ] **Step 5: Manually verify in browser if available**

Open `http://127.0.0.1:8280/tt-rss/`, log in with `admin/password`, open Actions > RSS Gallery, select one feed, confirm top-eight preview renders in 2x4 layout, and confirm Subscribe gives the existing subscription result.

## Self-Review

- Spec coverage: Actions entry, CSV data source, large dialog, left list, right top-eight preview, explicit subscribe, protected handler, existing parser/fetch reuse, 10 minute cache, structured errors, and verification are covered.
- Placeholder scan: no TBD/TODO placeholders.
- Type consistency: service class is `RssGallery`; handler class is `Rss_Gallery`; frontend action is `qmcRssGallery`.

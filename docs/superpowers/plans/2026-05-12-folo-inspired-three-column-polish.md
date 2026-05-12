# Folo Inspired Three Column Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make tt-rss's second and third columns feel closer to Folo's clean timeline and readable article layout without copying Folo source code.

**Architecture:** Keep tt-rss's existing Dojo/PHP/LESS structure. Update headline/article markup only where needed, and put the visual language in `themes/light/*.less` so compiled themes inherit it through the existing gulp pipeline.

**Tech Stack:** JavaScript, LESS, gulp-generated CSS, Docker preview.

---

### Task 1: Headline List Polish

**Files:**
- Modify: `js/Headlines.js`
- Modify: `themes/light/tt-rss.less`
- Modify: `themes/light/cdm.less`

- [ ] Add stable CSS hooks around headline meta/title/preview while preserving existing click handlers and hidden row checkbox state.
- [ ] Style normal headline rows as compact timeline items with source icon, muted meta, strong title, softer preview, hover/active states, and an unread dot.
- [ ] Style combined headline headers with the same visual system.

### Task 2: Article Reader Polish

**Files:**
- Modify: `js/Article.js`
- Modify: `themes/light/tt-rss.less`

- [ ] Add article header hooks for title and metadata.
- [ ] Make the article column use a centered readable width, stronger title typography, muted meta row, and better body spacing.
- [ ] Keep tags, action buttons, enclosures, and comments functional.

### Task 3: Verification

**Commands:**
- `npm run lint:js`
- `node --check js/Headlines.js && node --check js/Article.js`
- `npx gulp less`
- `docker compose -f docker-compose.dev.yml up -d`
- `curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8280/tt-rss/`

**Expected:** JS checks pass, LESS compiles, preview responds with HTTP 200.

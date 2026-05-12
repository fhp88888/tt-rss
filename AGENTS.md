# Repository Guidelines

## Project Context

This repository is a customized Tiny Tiny RSS fork. Treat `main` as the active development branch for this workspace unless the user asks for a separate branch.

The current development preview SOP is:

1. Make feature changes in the host checkout.
2. Run the app through the bind-mounted Docker preview stack.
3. Let the user preview the running app.
4. When the user confirms, commit and push.

Do not install PHP, Composer, PostgreSQL, or other runtime services on the host unless the user explicitly asks. The preferred low-pollution setup keeps runtime services inside Docker and edits source files on the host.

## Dev Preview

Use the prebuilt-image dev stack:

```bash
docker compose -f docker-compose.dev.yml up -d
```

Preview URL:

```text
http://127.0.0.1:8280/tt-rss/
```

Default dev login:

```text
user: admin
pass: password
```

Stop the stack:

```bash
docker compose -f docker-compose.dev.yml down
```

The app and web containers bind-mount the repository, so PHP, JS, CSS, and template changes should be visible after browser refresh without rebuilding Docker images. Rebuilds should only be considered for Dockerfile, system package, or PHP extension changes.

## Commands

Validate the dev compose file:

```bash
docker compose -f docker-compose.dev.yml config
```

Check the preview response:

```bash
curl -s http://127.0.0.1:8280/tt-rss/
```

Available npm scripts:

```bash
npm run lint:js
npm run lint:css
```

PHP tests are configured by `phpunit.xml`; run them inside an environment with the project PHP dependencies available.

## Coding Notes

- Prefer existing tt-rss patterns and helpers over introducing new abstractions.
- Keep changes narrowly scoped to the requested feature or bug.
- Avoid modifying vendored code under `vendor/` and bundled libraries under `lib/` unless the user explicitly asks.
- For UI text changes, search all browser entry points, not only the visible page.
- Keep local dev credentials and preview-only settings in dev-specific files.

## Verification Before Completion

Before saying work is complete, run the relevant verification:

- For preview or UI changes, verify the running dev stack and fetch the affected page with `curl` or browser tooling.
- For compose changes, run `docker compose -f docker-compose.dev.yml config`.
- For JS changes, run `npm run lint:js` when dependencies are available.
- For CSS changes, run `npm run lint:css` when dependencies are available.
- For backend PHP changes, run the narrowest applicable PHP test or a container-based runtime check.

State clearly if a verification command could not be run and why.

## Commit And Push

Do not commit automatically after every change. Commit and push when the user asks or confirms the preview.

When committing:

- Check `git status --short --branch` first.
- Stage only files relevant to the completed work.
- Use concise commit messages.
- Push to `origin/main` only when the user asks for push or explicitly approves it.

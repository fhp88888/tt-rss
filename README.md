Customized Tiny Tiny RSS Fork
=============================

This repository is a customized Tiny Tiny RSS fork. It keeps the upstream tt-rss
reader as the base, while adding local workflow, deployment, UI, and RSS Gallery
changes for this project.

The main branch is the active development branch for this checkout.

## What is included

This fork currently includes:

* A Docker-first development preview stack.
* A deploy stack that bind-mounts this checkout into prebuilt tt-rss runtime
  images.
* Headline list article thumbnails.
* RSS Gallery feed discovery data and UI.
* Local deployment helpers that keep runtime data under `data/`.

## Local development preview

Use Docker for the runtime. Do not install PHP, Composer, PostgreSQL, nginx, or
other tt-rss runtime services on the host unless you intentionally want a custom
host setup.

Start the preview stack:

```bash
docker compose -f docker-compose.dev.yml up -d
```

Open:

```text
http://127.0.0.1:8280/tt-rss/
```

Default development login:

```text
user: admin
pass: password
```

Stop the preview stack:

```bash
docker compose -f docker-compose.dev.yml down
```

The dev stack bind-mounts the repository into the app and web containers, so
PHP, JavaScript, CSS, theme, template, and data file changes are visible after a
browser refresh. Rebuild images only for Dockerfile, system package, or PHP
extension changes.

Useful checks:

```bash
docker compose -f docker-compose.dev.yml config
curl -s http://127.0.0.1:8280/tt-rss/
npm run lint:js
npm run lint:css
```

PHP tests are configured by `phpunit.xml`; run them inside an environment with
the project PHP dependencies available.

## Development workflow

The normal workflow for this project is:

1. Make changes in this host checkout.
2. Run or refresh the Docker preview stack.
3. Verify the changed page or behavior.
4. Commit after the preview is accepted.
5. Push to `origin/main` when ready.

Before committing or pushing, check that local data is not staged:

```bash
git status --short --branch
git diff --stat
git diff --cached --stat
```

Do not commit OPML exports, feed backups, `.env`, credentials, database dumps,
cache files, logs, session data, or OS/editor metadata.

## RSS Gallery

RSS Gallery feed data lives in:

```text
data/rss-gallery.csv
```

The CSV format is:

```csv
title,url
Example Feed,https://example.com/feed.xml
```

Keep the file in Git when feed recommendations change. Invalid or incomplete
rows are skipped by the RSS Gallery reader, but committed rows should still have
a useful title and a valid feed URL.

Related files:

* `classes/RssGallery.php`
* `classes/Rss_Gallery.php`
* `tests/RssGalleryTest.php`
* `themes/rss-gallery.css`

## Deployment

The deploy stack is defined by `docker-compose.yml` and managed by `deploy.sh`.
Docker provides PostgreSQL, PHP-FPM, nginx, and the updater process. The source
tree stays on the host and is bind-mounted into the containers.

First deployment:

```bash
cp .env-dist .env
# edit .env, especially TTRSS_SELF_URL_PATH and HTTP_PORT
./deploy.sh up
```

Common deploy commands:

```bash
./deploy.sh up
./deploy.sh restart
./deploy.sh down
```

Runtime data is kept under `data/`:

* `data/postgres` stores PostgreSQL data.
* `data/cache` and `data/lock` store tt-rss runtime files.
* `data/plugins.local`, `data/templates.local`, and `data/themes.local` store
  local customizations.

`deploy.sh` creates runtime directories and assigns them to `OWNER_UID` and
`OWNER_GID`, both defaulting to `1000`. It also grants the app group write
access to the checkout root so the container can generate the ignored
root-level `config.php` required by the upstream image startup script.

The deploy compose hides the host `data/` directory inside app containers so the
upstream startup script cannot recursively change ownership of PostgreSQL files.
The tracked `data/rss-gallery.csv` file is mounted back into that hidden
container path so RSS Gallery can load the committed feed list.

## Updating

Update deployed application code:

```bash
./deploy.sh update
```

That command prepares runtime directories, runs `git pull --ff-only`, and then
runs `docker compose up -d` so changed service definitions, volume mounts, and
environment settings are applied as well as code changes.

PHP, JavaScript, CSS, template, and CSV changes do not require image rebuilds
because the checkout is bind-mounted from the host.

Pull newer runtime images only when you want updated image contents or when an
upstream/runtime change requires new system packages or PHP extensions:

```bash
docker compose pull
./deploy.sh up
```

## Upstream Tiny Tiny RSS

Tiny Tiny RSS is a free, flexible, open-source, web-based news feed
(RSS/Atom/other) reader and aggregator.

The original tt-rss project at `tt-rss.org` was retired on 2025-11-01. Current
upstream continuation work lives at:

* https://github.com/tt-rss/tt-rss
* https://tt-rss.org/

This repository is a customized fork of that codebase. For general upstream
installation and project background, see the upstream documentation:

* https://tt-rss.org/docs/Installation-Guide.html

## License

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.

Copyright (c) 2005 Andrew Dolgov (unless explicitly stated otherwise).

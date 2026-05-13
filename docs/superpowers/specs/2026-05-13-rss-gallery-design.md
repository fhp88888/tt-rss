# RSS Gallery Design

## Goal

Add an RSS Gallery entry to the main Actions menu. The gallery lets a logged-in user browse a curated list of recommended RSS feeds, preview the latest items from one feed, and subscribe deliberately from the same interface.

## Data Source

The curated list is stored in a repository CSV file with two columns:

```csv
title,url
```

The first version uses a static local CSV derived from `amazingcoderpro/rss-recomanded`. Runtime code does not fetch or parse the GitHub README, because that would make the UI depend on network availability and README formatting.

The CSV parser must use PHP CSV APIs, not string splitting. Invalid rows are skipped when they lack a title or a valid URL.

## UI

The existing Actions dropdown in `index.php` gets a new `RSS Gallery` menu item. Selecting it dispatches through `App.onActionSelected()` and opens a large `fox.SingleUseDialog`.

The dialog has two panes:

- Left pane: searchable list of feeds from the CSV.
- Right pane: selected feed preview.

Clicking a feed previews it immediately. The preview shows up to eight latest parsed items in a fixed 2x4 layout. Each item displays title and published time. A failed or slow feed shows a clear error state without closing the dialog.

Subscribing is explicit. The gallery includes a Subscribe button for the selected feed and reuses the existing subscription behavior so feed categories, duplicate-feed handling, and refresh behavior stay consistent with the current app.

## Backend

Add a protected handler named `Rss_Gallery` with methods:

- `list`: read the CSV and return feed rows.
- `preview`: validate the requested URL, fetch it with `UrlHelper::fetch()`, parse it with `FeedParser`, and return feed metadata plus top eight items.

The preview response contains only display-safe fields: feed title, item title, item URL, item timestamp, and formatted published time. It does not write feed or article records to the database.

Preview requests use existing tt-rss fetch and parser classes:

- `UrlHelper::validate()`
- `UrlHelper::fetch()`
- `FeedParser`
- `FeedItem::get_title()`
- `FeedItem::get_date()`
- `TimeHelper::make_local_datetime()`

## Caching

To avoid repeated network fetches while the user explores the gallery, preview responses use a short local cache keyed by feed URL. The first version uses a 10 minute TTL. Cache failures fall back to normal fetching.

## Error Handling

The frontend handles:

- Empty CSV.
- Invalid feed URL.
- Download failure.
- Unsupported or malformed feed XML.
- Feed with no items.

The backend returns structured JSON errors instead of HTML.

## Verification

Relevant checks before calling the work complete:

- `docker compose -f docker-compose.dev.yml config`
- Start or verify the Docker preview stack.
- Fetch `http://127.0.0.1:8280/tt-rss/`.
- For JS changes, run `npm run lint:js` if dependencies are available.
- For CSS changes, run `npm run lint:css` if dependencies are available.
- Exercise the gallery manually in the preview UI, including one successful feed and one failure state.

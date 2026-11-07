# WP AI Calendar

Use **Google Calendar as the single source of truth** for your events. WP AI Calendar syncs it into WordPress, serves a fast JSON API for your website and digital signage, and exposes an **MCP server** so AI agents (Claude, etc.) can manage events for you.

Built by [Kusal Darshana](https://kusaldarshana.com) for a meditation center's website and lobby TV, and open-sourced for anyone who wants a calendar that doesn't fight them.

## Why

Calendar plugins want to own your data *and* your display, and recurring events are where they break. Google Calendar already handles recurrence, exceptions, sharing and mobile editing well. This plugin only adds what's missing:

- **Sync + cache**: a scheduled sync caches events, so visitors never hit Google and an outage only means slightly stale data.
- **JSON API**: `GET /wp-json/wpaical/v1/events?start_date=&end_date=&per_page=`.
- **Program registry**: map an event title to a page on your site and a category (Google Calendar has no field for this). One-off overrides with `page:` / `category:` lines in an event's description.
- **MCP server** at `/wp-json/wpaical/mcp` (Streamable HTTP): list, create, update, delete events and series; cancel one occurrence; manage the registry. Authenticate with a WordPress Application Password of an Editor+ user.

## Install

1. Download the latest zip from [Releases](https://github.com/thekusaldarshana/wp-ai-calendar/releases) (or zip this repo's contents into a `wp-ai-calendar/` folder).
2. WordPress admin → **Plugins → Add New → Upload Plugin** → activate.
3. Set **Settings → General → Timezone** to a city (not a UTC offset).

## Google setup

1. [Google Cloud Console](https://console.cloud.google.com/): create a project, enable the **Google Calendar API**.
2. Create a **service account** and download its **JSON key**.
3. In Google Calendar, create a calendar and share it with the service account's email with **Make changes to events**. Optionally make the calendar public for read access. Copy the **Calendar ID** (Settings → Integrate calendar).
4. In WordPress: **AI Calendar** (sidebar) → enter the Calendar ID, upload the key (or define `WPAICAL_SERVICE_ACCOUNT_JSON` in `wp-config.php`, which is safer), save, then **Sync now**.

## Using the MCP server

Add it to an MCP client like any HTTP server, with a Basic auth header:

```json
{
  "type": "http",
  "url": "https://example.com/wp-json/wpaical/mcp",
  "headers": { "Authorization": "Basic <base64 of username:application-password>" }
}
```

Tools: `list_events`, `list_series`, `get_event`, `create_event`, `update_event`, `delete_event`, `cancel_occurrence`, `list_programs`, `set_program`, `delete_program`, `sync_now`, `status`.

Recurring series use standard iCalendar lines, e.g. `["RRULE:FREQ=WEEKLY;BYDAY=SU"]`, `["RRULE:FREQ=MONTHLY;BYDAY=1SA,3SA"]`, or explicit dates with `RDATE`.

## Bulk import

`tools/import_events.py` imports a JSON file of Google Calendar event resources (idempotent). Run it with `python3 -I`:

```
python3 -I tools/import_events.py CALENDAR_ID --events events.json --key service-account.json [--dry-run]
```

## Security

The service-account key can edit your calendar: prefer the `wp-config.php` constant over the database, and never commit the key. The MCP endpoint requires an authenticated Editor+ user and should only be used over HTTPS.

## Status

Early (v0.1.0). Works in production on one site; expect rough edges. Issues and PRs welcome.

## License

GPL-2.0-or-later. Copyright (C) 2026 Kusal Darshana.

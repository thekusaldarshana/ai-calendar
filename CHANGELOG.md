# Changelog

All notable changes to this project are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow [Semantic Versioning](https://semver.org/).

## [0.1.4] - 2026-11-08
Security and reliability release following an independent review.
### Security
- Private/confidential Google events are never published through the public API.
- Event ids are validated before building Google API URLs (no dot-segment traversal).
- The OAuth token endpoint is fixed to Google's; `token_uri` from an uploaded key is ignored.
- MCP: argument types, real dates and line breaks are validated; failures return JSON-RPC errors instead of PHP fatals; batches are capped at 20; changing the program registry requires `manage_options`; the endpoint capability is filterable (`wpaical_mcp_capability`).
- Event titles and locations are sanitised before they are cached and served.
### Fixed
- Quick edit of a whole series failed for timed events.
- A non-JSON 2xx reply from Google emptied the cache; now an error. A rejected cached token is refreshed and retried once.
- The 5-minute cron schedule is registered at load, so activation schedules the sync.
- `UTC` site timezone no longer blocks timed writes; invalid dates (Feb 30) are rejected instead of rolling over.
- REST `end_date` (date only) now means end of that day; offset datetimes are converted to site time.
- Subdirectory installs no longer double the path prefix in program links; `cancel_occurrence` only cancels occurrences that start on the given date.
- Registry parsing is memoised per request.
### Packaging
- `tools/` and `README.md` are no longer in the release zip. Expanded readme (tags, FAQ, installation, external-services disclosure, upgrade notice).

## [0.1.3] - 2026-11-08
### Added
- `timezone` field in the `/wpaical/v1/events` response (the site's IANA timezone), so displays can compute "now" in site time.

## [0.1.2] - 2026-11-08
### Added
- "Open Google Calendar" button and per-event "Edit in Google" links on the dashboard.
- Quick add form for one-off events.
- Quick edit for upcoming events (date, start/end time, category; one date or whole series). Dashboard actions share the MCP's validation and Google calls.

## [0.1.1] - 2026-11-08
### Added
- Programs admin table: page search (published pages), category dropdown with "+ New category", per-row match count against the calendar, add/remove rows.
- Agent Skill `skills/wp-ai-calendar/SKILL.md` for AI agents using the MCP server.
### Fixed
- MCP `get_event` and `delete_event` now return "event_id is required." instead of calling Google with an empty id.

## [0.1.0] - 2026-11-07
### Added
- Google Calendar sync (service account) with a cached events window.
- Public JSON API: `GET /wp-json/wpaical/v1/events`.
- MCP server at `/wp-json/wpaical/mcp` (Streamable HTTP) with 12 tools for events, series, programs and sync.
- Program registry mapping event titles to pages and categories.
- Admin dashboard (sidebar "AI Calendar"), settings, and a generic idempotent importer in `tools/`.

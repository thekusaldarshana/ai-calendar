# Changelog

All notable changes to this project are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow [Semantic Versioning](https://semver.org/).

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

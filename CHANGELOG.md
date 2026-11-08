# Changelog

All notable changes to this project are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow [Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-11-07
### Added
- Google Calendar sync (service account) with a cached events window.
- Public JSON API: `GET /wp-json/wpaical/v1/events`.
- MCP server at `/wp-json/wpaical/mcp` (Streamable HTTP) with 12 tools for events, series, programs and sync.
- Program registry mapping event titles to pages and categories.
- Admin dashboard (sidebar "AI Calendar"), settings, and a generic idempotent importer in `tools/`.

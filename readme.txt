=== WP AI Calendar ===
Contributors: kusaldarshana
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.1.1
Tested up to: 6.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Use Google Calendar as the single source of truth for your events. WP AI Calendar syncs it into WordPress, serves a fast JSON API for your site and any digital signage, and exposes an MCP server so AI agents can manage events.

== Description ==

* Edit events in Google Calendar (recurrence, exceptions, mobile apps: all handled by Google).
* A scheduled sync caches events, so visitors never hit Google and an outage only means slightly stale data.
* Public JSON API: `/wp-json/wpaical/v1/events?start_date=&end_date=&per_page=`.
* Program registry: map an event title to a page on your site and a category (Google Calendar has no field for this). One-off overrides via `page:` / `category:` lines in an event description.
* MCP server at `/wp-json/wpaical/mcp` (Streamable HTTP, JSON): list/create/update/delete events and series, cancel a single occurrence, manage the registry. Authenticate with a WordPress Application Password of an Editor+ user.

== Setup ==

1. Google Cloud: create a project, enable the Google Calendar API, create a service account, download its JSON key.
2. Google Calendar: create a calendar, share it with the service account's email with "Make changes to events", copy the Calendar ID.
3. AI Calendar (sidebar) -> Settings: enter the Calendar ID, upload the JSON key (or define `WPAICAL_SERVICE_ACCOUNT_JSON` in wp-config.php), optionally set categories and programs, then Sync now.
4. Set Settings -> General -> Timezone to a city (not a UTC offset).

== Changelog ==

= 0.1.1 =
* Admin: Programs are now a table with a page search, a category dropdown that can create new categories, and a count of matching calendar events.
* MCP: get_event and delete_event return a clear error when event_id is missing.
* Added a ready-made Agent Skill (see the GitHub repository) that teaches AI agents how to use the MCP tools safely.

= 0.1.0 =
* Initial release.

== Security ==
The service-account key can edit your calendar. Prefer the wp-config.php constant over storing it in the database. The MCP endpoint requires an authenticated Editor+ user; use HTTPS.

== External services ==
This plugin connects to the Google Calendar API (https://developers.google.com/calendar) and Google's OAuth token endpoint (https://oauth2.googleapis.com/token) to read and, when you use the create/update/delete tools, write events in the calendar you configure. Data sent: the service-account credentials you provide (to obtain a token) and event data. It only runs after you configure a calendar. Google Terms: https://policies.google.com/terms and Privacy Policy: https://policies.google.com/privacy

=== WP AI Calendar ===
Contributors: kusaldarshana
Tags: calendar, events, google calendar, rest api, mcp
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Use Google Calendar as your events source of truth: synced to WordPress, with a JSON API for sites and signage and an MCP server for AI agents.

== Description ==

Edit your events in Google Calendar, where recurrence, exceptions and mobile apps already work well. WP AI Calendar syncs them into WordPress and publishes them wherever you need them.

* **Google Calendar is the source of truth.** Repeating events, one-off events, single-date exceptions: all managed in Google.
* **Cached, fast, resilient.** A scheduled sync stores events locally, so visitors never wait on Google, and a Google outage only means slightly stale data.
* **Public JSON API** for your website or digital signage: `/wp-json/wpaical/v1/events?start_date=&end_date=&per_page=`. The response includes the site timezone.
* **Program registry.** Map an event title to a page on your site and a category (Google Calendar has no field for this). One-off overrides with `page:` / `category:` lines in an event's description.
* **Dashboard.** Sync status, upcoming events, quick add, quick edit (date, time, category), and a one-click link to open the calendar in Google.
* **MCP server** at `/wp-json/wpaical/mcp` (Model Context Protocol, Streamable HTTP, JSON). AI agents can list, create, update, delete and cancel events using a WordPress Application Password, the same way they use other WordPress MCP servers.

A ready-made Agent Skill that teaches an AI agent to use the MCP tools safely is available in the GitHub repository.

Requirements: PHP 7.4+ with the OpenSSL extension (used to sign Google service-account requests), a Google Cloud service account, and a WordPress timezone set to a named zone (for example America/New_York).

== Installation ==

1. Install and activate the plugin.
2. Google Cloud: create a project, enable the Google Calendar API, create a service account and download its JSON key.
3. Google Calendar: create a calendar, share it with the service account's email address with "Make changes to events", and copy the Calendar ID (Calendar settings, "Integrate calendar").
4. In WordPress open AI Calendar (sidebar), enter the Calendar ID, upload the JSON key (or define `WPAICAL_SERVICE_ACCOUNT_JSON` in wp-config.php, which is safer), save, then click Sync now.
5. Set Settings, General, Timezone to a city (not a UTC offset).

== Frequently Asked Questions ==

= Where do I edit events? =
In Google Calendar. The dashboard also has Quick add and Quick edit for simple changes, and an "Edit in Google" link on each event.

= Who can see my events? =
Anyone: the JSON endpoint is public by design, so your website and displays can read it without logging in. It serves the events in the calendar you configured, except events marked Private or Confidential in Google Calendar, which are never published. Do not put anything sensitive in a calendar you connect to this plugin.

= Is my Google key safe? =
The key can edit your calendar. By default it is stored in the WordPress database (never displayed again). For better security define `WPAICAL_SERVICE_ACCOUNT_JSON` in wp-config.php instead, and use a service account that only has access to this one calendar.

= Who can use the MCP endpoint? =
Any user with the Editor role or higher, authenticating with an Application Password (use HTTPS). Editors can create, change and delete events. Changing the program registry requires an administrator. Use the `wpaical_mcp_capability` filter to require a different capability.

= Does the plugin send my data to an AI service? =
No. The plugin itself never contacts an AI provider. If you connect an AI client (for example Claude or ChatGPT) to the MCP endpoint, event data goes to that client's provider under your own agreement with them.

= How often does it sync? =
Every 5 minutes, and immediately after any change made through the plugin.

== Changelog ==

= 0.1.4 =
* Security: private and confidential Google events are never published; event ids are validated before building Google API URLs; the OAuth token endpoint is fixed to Google's (the `token_uri` field in an uploaded key is no longer used); MCP input is validated (types, dates, line breaks) and bad input returns an error instead of a fatal; batch size is capped; changing the program registry through MCP requires an administrator.
* Fix: Quick edit of a whole recurring series failed for timed events.
* Fix: a non-JSON reply from Google (captive portal, proxy page) could empty the cache; it is now treated as an error. A rejected cached token is refreshed automatically.
* Fix: the 5-minute schedule is now registered at load, so activation schedules the sync.
* Fix: sites using the "UTC" timezone could not create timed events; invalid calendar dates (such as Feb 30) are rejected instead of shifting.
* Fix: the events API treats a date-only end_date as the end of that day, and converts datetimes that carry an offset to site time.
* Fix: page links no longer get a doubled prefix on subdirectory installs; cancelling one occurrence no longer risks cancelling an overlapping neighbour.
* Docs: expanded external-services disclosure, FAQ, and installation steps.

= 0.1.3 =
* API: the events response now includes the site timezone, so displays (TVs, widgets) can compare "now" in the site's time instead of the viewer's.

= 0.1.2 =
* Dashboard: "Open Google Calendar" button and an "Edit in Google" link on every upcoming event.
* Dashboard: Quick add form for one-off events (title, date, time, location, category, all-day).
* Dashboard: Quick edit for upcoming events: change date, start/end time and category for one date or a whole series.

= 0.1.1 =
* Admin: Programs are now a table with a page search, a category dropdown that can create new categories, and a count of matching calendar events.
* MCP: get_event and delete_event return a clear error when event_id is missing.
* Added a ready-made Agent Skill (see the GitHub repository) that teaches AI agents how to use the MCP tools safely.

= 0.1.0 =
* Initial release.

== Upgrade Notice ==

= 0.1.4 =
Security and reliability release. Private Google events are no longer published, and several input checks were added.

== External services ==

This plugin connects to Google services to read, and (when you use the quick add, quick edit or MCP create/update/delete features) write events in the one Google Calendar you configure.

1. **Google OAuth token endpoint** (https://oauth2.googleapis.com/token). Used to obtain a short-lived access token for your service account. Sent: a request signed locally with your service-account private key (the key itself is never transmitted) that names the service account and the Calendar API scope. The token is cached for about an hour. It is requested when a sync or a write needs one.
2. **Google Calendar API** (https://www.googleapis.com/calendar/v3). Used to list events (a 7-days-back to 365-days-ahead window), and to create, update or delete events and recurring series when you or an authorised agent do so. Sent: your Calendar ID, the access token, and the event data you are writing. Received: the events of that calendar, which are cached in your WordPress database.

When it runs: automatically every 5 minutes (WP-Cron) once a Calendar ID and key are saved, and immediately after a change made through the plugin. Nothing is sent before you configure a calendar, and the plugin sends no usage or telemetry data to anyone.

The cached events are served publicly through the plugin's own JSON endpoint on your site (see the FAQ). Events marked Private or Confidential in Google Calendar are excluded.

Google APIs Terms of Service: https://developers.google.com/terms and Google Privacy Policy: https://policies.google.com/privacy

---
name: ai-calendar
description: Manage a website's events through the AI Calendar MCP server (Google Calendar is the source of truth). Use when asked what events are scheduled, or to add, change, move, cancel or delete an event or recurring series, or to link an event to a program page or category.
version: 0.1.4
license: GPL-2.0-or-later
---

# Managing events with AI Calendar

The site's events live in **Google Calendar**. The AI Calendar plugin caches them and serves them to the website and any display (a TV, a widget) from one JSON endpoint. You reach it through the plugin's **MCP server** (`/wp-json/wpaical/mcp`), so every change you make shows up everywhere. Never edit a page's calendar HTML or a separate event plugin for this.

## Tools

| Tool | Use for |
| --- | --- |
| `status` | Check the connection, last sync, timezone. Call first if anything looks wrong. |
| `list_events` | Dated occurrences between two dates (`start_date`, `end_date`, `YYYY-MM-DD`). Default: next 30 days. |
| `list_series` | Recurring series definitions (rules), not expanded. |
| `get_event` | One event, series or occurrence by `event_id`. |
| `create_event` | New one-off event or recurring series. |
| `update_event` | Change fields. Series id = every occurrence; occurrence id = that date only. |
| `cancel_occurrence` | Skip one date of a series (`series_id` + `date`). |
| `delete_event` | Delete an event or a whole series. Cannot be undone from here. |
| `list_programs` / `set_program` / `delete_program` | The registry that maps an event title to a program page and category. Changing it needs an administrator account. |
| `sync_now` | Refresh the cache. Write tools already do this. |

## Rules

1. **Read before you write.** Use `list_events` / `list_series` to find the real event and its id. Never guess an id or a title.
2. **Confirm destructive or wide changes.** Deleting, cancelling, or editing a whole series affects every future date. State exactly what will change (title, dates, how many occurrences) and get the requester's explicit yes first, unless your instructions say it is already approved.
3. **Series vs occurrence.** `list_events` returns occurrence ids (like `abc123_20261108T140000Z`); their `series_id` is the series. Change one date with the occurrence id, all dates with the series id. To skip one date, prefer `cancel_occurrence`.
4. **Times are in the site timezone** (shown by `status`), written `YYYY-MM-DD HH:MM`. For all-day events use `YYYY-MM-DD`, and `end` is the **last day, inclusive**. When changing a time, pass both `start` and `end`.
5. **Title matters.** A title that matches a registry entry automatically gets that program page and category. Use the exact existing title for a new session of a known program (check `list_programs`).
6. **Do not put `page:`, `category:` or `image:` lines in `description`.** Use the `page`, `category` and `image` fields; the plugin manages those lines itself. `image` is an optional https URL shown on the event's card in the site's upcoming-events widget (`[wpaical_upcoming]`).
7. **Verify after every write.** Re-read with `get_event` or `list_events` and report what is now true, not what you intended.
8. **Never reveal credentials**, the service-account key, or Application Passwords in any output.

## Recurrence cheatsheet (`recurrence` is a list of iCalendar lines)

- Every Sunday: `["RRULE:FREQ=WEEKLY;BYDAY=SU"]`
- First and third Saturday: `["RRULE:FREQ=MONTHLY;BYDAY=1SA,3SA"]`
- Every Sunday except the 3rd: `["RRULE:FREQ=MONTHLY;BYDAY=1SU,2SU,4SU,5SU"]`
- Skip one date in a rule: add `"EXDATE;TZID=<site timezone>:20261107T090000"` (the date's start time)
- Irregular dates (e.g. moon days): `["RDATE;TZID=<site timezone>:20261124T060000,20261223T060000"]`
- Stop after N times: add `;COUNT=N` to the RRULE; or `;UNTIL=20271231T000000Z`.

A one-off event omits `recurrence`.

## Common tasks

- **"What's on this week?"** `list_events` with the week's dates. Summarise title, day, time, place.
- **Add an event.** Check `list_events` for a duplicate, then `create_event` (title, start, end, optional location, page, category), then verify.
- **Move or rename one session.** Find the occurrence id in `list_events`, `update_event` with that id.
- **Cancel one session.** `cancel_occurrence` (or `delete_event` with the occurrence id), verify it is gone.
- **Link a program page.** `set_program` with the exact event title, the page path (`/page-slug/`), and a category.

## If a tool errors

Read the message. `event_id is required` / `date must be YYYY-MM-DD` mean a missing or malformed argument. HTTP 404 from Google usually means the id is wrong or already deleted. A credentials or calendar error from `status` is a site-configuration problem: report it, do not retry in a loop.

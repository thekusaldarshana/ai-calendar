# Security Policy

## Reporting a vulnerability

Please **do not** open a public issue. Use GitHub's private reporting: **Security → Report a vulnerability** on this repository.

You can expect an acknowledgement within a few days. This is a small volunteer project, so there is no formal SLA, but security reports are prioritised.

## Scope notes

- The Google service-account key grants write access to your calendar. Prefer defining `WPAICAL_SERVICE_ACCOUNT_JSON` in `wp-config.php` over storing it in the database.
- The MCP endpoint requires an Editor-or-higher WordPress user via Application Passwords. Use a dedicated low-privilege (Editor) user for agents and revoke passwords you no longer use.
- Only the latest released version receives fixes.

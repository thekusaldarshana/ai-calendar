# Contributing

Thanks for helping! Bug reports, ideas and pull requests are welcome.

## Ground rules
- The plugin must stay generic: **no site-specific values** (names, URLs, IDs) in code.
- Follow WordPress practices: sanitize input, escape output, verify nonces and capabilities. Prefix everything with `wpaical` / `WPAICal_`.
- PHP 7.4+ compatible.
- Keep the version consistent in the plugin header, `WPAICAL_VERSION` and `readme.txt` (CI checks this).

## Workflow
1. Fork, branch from `main`.
2. Make a focused change; run `php -l` on touched files.
3. Add an entry to `CHANGELOG.md`.
4. Open a PR describing what and why.

## Releasing (maintainer)
Bump the version in the three places, update the changelog, commit, then `git tag vX.Y.Z && git push --tags`. The Release workflow builds the zip and attaches it.

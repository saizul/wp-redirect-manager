# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `wp redirects 404 list` (search, `--orderby=last_seen|first_seen|hits|url`, `--order`,
  `--limit` with 0 for all, `--fields`, `--format`) and `wp redirects 404 clear` (asks for
  confirmation unless `--yes`).

## [0.1.0] - 2026-11-19

### Added

- Redirects with status 301, 302, 307 and 308, and 410 Gone (theme 404
  template with a real 410 status).
- Exact, prefix (rest of the path carried over, whole segments only) and
  regex matching with `$0`-`$99` and `${n}` capture groups; matching order
  exact, regex, prefix.
- Per-rule query string handling: ignore, exact (any parameter order) or
  pass on to the target.
- Case-insensitive and trailing-slash-insensitive matching (settings).
- Lookup index cached in one autoloaded option, with an indexed database
  fallback above 2,000 exact rules (`redirect_manager_cache_limit`).
- Hit counter and last hit per redirect.
- Tools > Redirects screen: add/edit form, searchable and sortable list with
  bulk enable, disable, delete and reset hits.
- 404 monitor: one row per URL with hits, last referrer and user agent,
  first and last seen; "Create redirect" link; retention days, row cap and
  ignore list with a daily clean-up.
- Automatic 301 when a published post, page or public custom post type
  changes its URL, with chain flattening and clean-up when the URL changes
  back.
- CSV import (optional header, aliases, delimiter detection, dry run,
  update or skip existing, per-line errors) and export (with hits, formula
  injection protection).
- Loop and chain detection on save, enable and import, plus a runtime
  self-redirect guard.
- WP-CLI: `wp redirects add|list|import|export|delete|test`.
- Hooks: `redirect_manager_match`, `redirect_manager_redirect`,
  `redirect_manager_gone`, `redirect_manager_saved`,
  `redirect_manager_log_404`, `redirect_manager_auto_redirect`,
  `redirect_manager_capability`, `redirect_manager_max_hops`,
  `redirect_manager_cache_limit`, `redirect_manager_local_hosts`.
- Translation template, multisite-aware uninstall.
- PHPUnit tests on WordPress stubs, phpcs (WordPress Coding Standards), CI on
  PHP 7.4 to 8.4, POT check and a release workflow that builds the plugin zip.

[Unreleased]: https://github.com/saizul/wp-redirect-manager/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/saizul/wp-redirect-manager/releases/tag/v0.1.0

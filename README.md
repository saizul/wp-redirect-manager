# Redirect Manager

[![CI](https://github.com/saizul/wp-redirect-manager/actions/workflows/ci.yml/badge.svg)](https://github.com/saizul/wp-redirect-manager/actions/workflows/ci.yml)
[![Release zip](https://github.com/saizul/wp-redirect-manager/actions/workflows/release-zip.yml/badge.svg)](https://github.com/saizul/wp-redirect-manager/actions/workflows/release-zip.yml)
[![Latest release](https://img.shields.io/github/v/release/saizul/wp-redirect-manager?sort=semver)](https://github.com/saizul/wp-redirect-manager/releases)
![PHP](https://img.shields.io/badge/PHP-7.4%20to%208.4-777bb4)
![WordPress](https://img.shields.io/badge/WordPress-6.2%2B-21759b)
[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)
[![Good first issues](https://img.shields.io/github/issues/saizul/wp-redirect-manager/good%20first%20issue?label=good%20first%20issues)](https://github.com/saizul/wp-redirect-manager/labels/good%20first%20issue)

A **lightweight redirect manager for WordPress**: 301/302/307/308 redirects
and 410 Gone, exact/prefix/regex matching, a 404 monitor with one-click
"create redirect", automatic redirects when a post URL changes, CSV
import/export, loop detection and WP-CLI. One custom table, one cached
option, zero extra queries on a normal page view.

Plugin slug: `wp-redirect-manager` - display name: *Redirect Manager*.

- No tracking, no remote requests, no upsells, no JavaScript build.
- Redirects are resolved from one autoloaded option: exact matches are a
  hash lookup, not a database query.
- A redirect that would loop (`/a -> /b -> /a`) cannot be saved.

## Contents

- [Features](#features)
- [Quick start](#quick-start)
- [How matching works](#how-matching-works)
- [Query strings](#query-strings)
- [404 monitor](#404-monitor)
- [Automatic redirects for changed URLs](#automatic-redirects-for-changed-urls)
- [CSV import and export](#csv-import-and-export)
- [WP-CLI](#wp-cli)
- [Hooks for developers](#hooks-for-developers)
- [Performance](#performance)
- [FAQ](#faq)
- [Development and testing](#development-and-testing)
- [Roadmap](#roadmap)
- [Contributing](#contributing)
- [Security and license](#security-and-license)

## Features

- **Statuses**: 301, 302, 307, 308 and 410 Gone (shown with your theme's 404
  template and a real `410` status).
- **Matching**: exact path, path prefix (the rest of the path is carried
  over) and regular expressions with `$1`, `${2}` capture groups.
- **Query strings**: per rule, ignore them, require an exact match (any
  parameter order) or pass them on to the target.
- **Options**: case-insensitive matching and trailing-slash-insensitive
  matching (both on by default).
- **Admin list** under **Tools > Redirects**: search, sort, pagination, bulk
  enable/disable/delete/reset hits, row actions, notes.
- **Hit counter and last hit** per redirect (can be switched off).
- **404 monitor**: one row per URL with hit count, last referrer, user agent,
  first and last seen; "Create redirect" prefills the form and removes the
  entry; retention in days, row cap, ignore list.
- **Automatic 301** when a published post, page or public custom post type
  changes its slug or parent. Older automatic redirects of the same post are
  re-pointed, so there are no chains.
- **CSV import/export** with a documented column order, optional header row,
  dry run, update-or-skip of existing rules and per-line error report.
- **WP-CLI**: `wp redirects add | list | import | export | delete | test`.
- **Loop and chain detection** on save, on enable and on import, plus a
  runtime guard against redirecting a URL to itself.
- Nonces and capability checks everywhere, sanitised input, escaped output,
  translation ready, multisite-aware uninstall.

## Quick start

Requirements: WordPress 6.2 or newer (tested up to 7.1), PHP 7.4 or newer,
MySQL 5.7+ or MariaDB 10.3+.

1. Download `wp-redirect-manager.zip` from the
   [latest release](https://github.com/saizul/wp-redirect-manager/releases)
   and install it from **Plugins > Add New > Upload Plugin**, or clone this
   repository into `wp-content/plugins/wp-redirect-manager`.
2. Activate **Redirect Manager**. The tables are created on activation.
3. Go to **Tools > Redirects**, enter `/old-page` as the source and
   `/new-page` as the target, and click **Add redirect**.
4. Check it:

```sh
curl -I https://example.org/old-page
# HTTP/1.1 301 Moved Permanently
# Location: /new-page
# X-Redirect-By: WP Redirect Manager
```

Or from the command line:

```sh
wp redirects add /old-page /new-page
wp redirects test /old-page
# 301 /new-page (redirect 1, exact match)
```

## How matching works

Sources are paths from the domain root, as you see them in the address bar
(`/blog/old-post/`, not the full URL; a pasted full URL is reduced to its
path). Rules are tried in this order:

1. **Exact** rules. A rule that also matches the query string wins over one
   that ignores it.
2. **Regex** rules, in ID order (oldest first).
3. **Prefix** rules, longest prefix first.

| Match | Source | Request | Target | Result |
| --- | --- | --- | --- | --- |
| exact | `/about-us` | `/About-Us/` | `/about` | `/about` |
| prefix | `/old-blog` | `/old-blog/2024/post/` | `/blog` | `/blog/2024/post/` |
| prefix | `/shop` | `/shopping` | `/store` | no match (whole segments only) |
| prefix | `/` | `/anything` | `https://new.example` | `https://new.example/anything` |
| regex | `^/(\d{4})/(\d{2})/(.+)$` | `/2024/05/hello` | `/news/$3?y=$1` | `/news/hello?y=2024` |
| regex | `^/tag/(.*)$` | `/tag/php` | `/topic/${1}` | `/topic/php` |

Regex notes:

- Patterns are PCRE without delimiters; `#` is escaped for you. They are
  **not anchored automatically**: use `^` and `$`.
- They run against the decoded path (`/café`, not `/caf%C3%A9`). With the
  "query must match" mode, against `path?query`.
- With case-insensitive matching on, the `i` flag is added.
- `$0` is the whole match, `$1`...`$99` (or `${1}`) the groups. Captured
  values are URL-encoded again; missing groups become empty.
- Invalid patterns are rejected on save.

Targets are a path on this site (`/new`) or a full `http(s)://` URL. Any
other scheme (`javascript:`, `data:`) and protocol-relative `//host` URLs
are rejected.

## Query strings

| Mode | `/page` rule, request `/page?utm=x` |
| --- | --- |
| Ignore (default) | matches, target gets no query string |
| Pass | matches, `?utm=x` is added to the target (target parameters win) |
| Exact | does not match: the request query must equal the source query, in any order |

Entering a source such as `/index.php?p=12` switches the rule to "exact" for
you. Automatic redirects for changed post URLs use "pass", so campaign
parameters survive.

## 404 monitor

Every request that ends in a 404 is stored once per URL with a counter, the
last referrer and user agent, and the first and last time it was seen. No IP
addresses are stored.

- **Tools > Redirects > 404 log**: search, sort by hits, bulk delete, clear.
- **Create redirect** opens the form with the path prefilled; after saving,
  the entry is removed from the log.
- **Retention**: a daily cron job deletes entries older than N days (30 by
  default) and keeps at most N URLs (10,000 by default).
- **Ignore list**: URLs containing any of the lines are not logged
  (default: `/wp-content/`, `/favicon.ico`, `/apple-touch-icon`).
- Switch the log off in the settings, or per request with the
  `redirect_manager_log_404` filter.

## Automatic redirects for changed URLs

When a **published** post, page or public custom post type is saved with a
different permalink (slug, parent page or date change), a 301 from the old
path to the new one is added, with the note "Added automatically when post N
changed its URL".

- Change the URL again and the older automatic redirect is re-pointed to the
  newest URL (no chains).
- Change it back and the redirect that would now point away from the live
  URL is removed.
- A manual redirect for the old path is never overwritten.
- Switch it off in the settings or with `redirect_manager_auto_redirect`.

## CSV import and export

Column order (import and export):

```
source,target,status,match_type,query,enabled,note
```

Export appends `hits,last_hit`; import ignores them. Example:

```csv
source,target,status,match_type,query,enabled,note
/old-page,/new-page,301,exact,ignore,1,Renamed in 2026
/old-blog,/blog,301,prefix,pass,1,
"^/(\d{4})/(\d{2})/(.+)$",/news/$3,308,regex,ignore,1,Date archives
/discontinued-product,,410,exact,ignore,1,
```

- The header row is **optional**. Without it, columns are read in the order
  above and only `source` (and `target`, unless the status is 410) are
  required: a two-column `old,new` file works.
- With a header, columns can be in any order. Common names are understood:
  `from`/`old_url`/`source_url`, `to`/`destination`/`new_url`/`target_url`,
  `code`/`http_code`/`status_code`, `match`, `regex` (1/0), `comment`/`title`.
- Defaults: status `301`, match `exact`, query `ignore`, enabled `1`.
- Comma, semicolon and tab delimiters are detected; a UTF-8 BOM, blank lines
  and lines starting with `#` are skipped; quoted fields may contain
  delimiters and newlines (RFC 4180, backslash is not an escape character).
- A row with the same match type and source as an existing redirect
  **updates** it (or is skipped with "Keep existing redirects unchanged" /
  `--skip-existing`). Each row is validated and loop-checked; failures are
  reported with their line number and do not stop the import.
- **Dry run** validates everything and saves nothing.
- Export protects spreadsheet users: cells starting with `=`, `+`, `-` or `@`
  get a leading `'`, which import removes again.

A ready-to-edit file is in [`examples/redirects.csv`](examples/redirects.csv).

## WP-CLI

```sh
# Add (defaults: --status=301 --match=exact --query=ignore)
wp redirects add /old-page /new-page
wp redirects add /old-blog /blog --match=prefix --query=pass
wp redirects add '^/(\d{4})/(\d{2})/(.+)$' '/news/$3' --match=regex --status=308
wp redirects add /discontinued --status=410 --note="Product removed"
id=$(wp redirects add /a /b --porcelain)

# List (table, csv, json, yaml, count, ids)
wp redirects list
wp redirects list --search=blog --format=json
wp redirects list --match=regex --fields=id,source,target,hits

# Import and export
wp redirects import redirects.csv --dry-run
wp redirects import redirects.csv --skip-existing
cat redirects.csv | wp redirects import -
wp redirects export backup.csv
wp redirects export > backup.csv

# Which rule handles a URL?
wp redirects test '/old-blog/2024/post?utm_source=mail'

# Delete
wp redirects delete 12 13
```

Errors (invalid input, loops) exit with status 1 and a message, so the
commands are safe to use in deploy scripts.

## Hooks for developers

| Hook | Type | Arguments | Use it to |
| --- | --- | --- | --- |
| `redirect_manager_match` | filter | `MatchResult\|null $match`, `string $uri` | Change or cancel the redirect for a request. |
| `redirect_manager_redirect` | action | `MatchResult $match`, `string $uri` | Log redirects, send extra headers. |
| `redirect_manager_gone` | action | `MatchResult $match` | Runs before the 404 template is shown with status 410. |
| `redirect_manager_saved` | action | `Rule $rule` | React to a saved redirect (cache purge, audit log). |
| `redirect_manager_log_404` | filter | `bool $log`, `string $uri` | Skip logging some 404s. |
| `redirect_manager_auto_redirect` | filter | `bool $add`, `WP_Post $post`, `string $old`, `string $new` | Skip automatic redirects for some posts. |
| `redirect_manager_capability` | filter | `string $capability` | Who manages redirects (`manage_options`). |
| `redirect_manager_max_hops` | filter | `int $hops` | Longest allowed redirect chain (10). |
| `redirect_manager_cache_limit` | filter | `int $limit` | Exact rules kept in the autoloaded index (2000). |
| `redirect_manager_local_hosts` | filter | `string[] $hosts` | Extra hosts that count as this site for loop detection. |

Example (in a must-use plugin):

```php
// Never redirect requests from a health checker.
add_filter( 'redirect_manager_match', function ( $match, $uri ) {
	$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	return false !== strpos( $agent, 'HealthCheck' ) ? null : $match;
}, 10, 2 );

// Purge a CDN path when a redirect changes.
add_action( 'redirect_manager_saved', function ( $rule ) {
	do_action( 'my_cdn_purge', $rule->source );
} );
```

More in [`examples/`](examples/).

## Performance

- Redirects run on `template_redirect` priority 1, before core's canonical
  and old-slug redirects.
- All enabled rules are compiled into one autoloaded option
  (`wp_redirect_manager_index`), rebuilt on every change. Exact rules are a
  hash-map lookup; only regex and prefix rules are looped over.
- Above 2,000 exact rules (filter `redirect_manager_cache_limit`), exact
  rules are left out of the option and looked up with one indexed query per
  request (`source_key` column).
- Counting a hit is one `UPDATE ... SET hits = hits + 1` per redirect
  response; switch it off in the settings if you do not need the numbers.
- A 404 costs one `INSERT ... ON DUPLICATE KEY UPDATE`.

## FAQ

**Does it work with page caches and CDNs?**
Redirects are sent by PHP, so a page cache that serves a cached page for the
old URL skips them. Purge the cache for the source URL after adding a
redirect. Many caches also cache 301 responses, which is fine.

**Why is my redirect not used?**
Run `wp redirects test /your/path`. Check that the rule is enabled, that an
exact or regex rule is not matched first, and whether "Query string must
match" is set. Paths are relative to the domain root: on a site in a
subfolder, include the folder (`/blog/old-post`).

**Does WordPress not redirect changed slugs already?**
Core redirects old post slugs (`_wp_old_slug`), but not pages, changed
parents or custom permalinks, and you cannot see or edit those redirects.
Redirect Manager adds visible, editable 301s for all of them.

**Can I redirect to another domain?**
Yes, use a full URL as the target. A prefix rule with source `/` moves a
whole site: `/` -> `https://new.example` sends `/a/b` to
`https://new.example/a/b`.

**What does 410 do?**
It tells search engines the page is gone for good, so they drop it faster
than with a 404. Visitors see your theme's 404 page.

**Is the 404 log GDPR friendly?**
It stores URLs, referrers and user agents, no IP addresses, and deletes
entries after the retention period. Switch it off if you do not need it.

**Does it work on multisite?**
Yes. Each site has its own tables and settings; the tables are created on
the first request after activation, and uninstall cleans up every site.

**Can I migrate from another plugin?**
Export a CSV there and import it here. Columns are matched by header name
(see [CSV import and export](#csv-import-and-export)); try a dry run first.

## Development and testing

```sh
composer install
composer test           # PHPUnit on light WordPress stubs, no database needed
composer phpcs          # WordPress Coding Standards and PHP 7.4+ compatibility
composer lint           # php -l on every file
composer make-pot       # regenerate languages/wp-redirect-manager.pot
```

Project layout:

```
wp-redirect-manager.php       Plugin header, autoloader, activation hooks
uninstall.php                 Drops the tables and options on every site
src/Plugin.php                Wiring, cron, capability, local hosts
src/Rule.php                  One redirect (value object)
src/Path.php                  Path, query string and URL helpers
src/Index.php                 Compiled lookup index (cached in an option)
src/Matcher.php               Matching engine (exact, regex, prefix, captures)
src/LoopDetector.php          Loop and chain detection
src/Sanitizer.php             Rule input validation
src/Csv.php                   CSV format (parse and build)
src/Redirects.php             Service: save, import, export, slug changes
src/Frontend.php              template_redirect: redirects, 410, 404 log
src/SlugWatcher.php           post_updated: automatic redirects
src/Settings.php              The settings option
src/Schema.php                Custom tables (dbDelta)
src/Store/                    Storage contracts and database implementations
src/Admin/                    Tools > Redirects screen and list tables
src/Cli/Command.php           wp redirects
tests/                        PHPUnit tests, WordPress and WP-CLI stubs
bin/make-pot.php              POT generator
examples/                     Sample CSV and developer snippets
```

CI runs on PHP 7.4, 8.0, 8.1, 8.2, 8.3 and 8.4: `composer validate`,
`php -l`, phpcs, PHPUnit and the POT check. Publishing a GitHub release
builds `wp-redirect-manager.zip`, smoke-tests it and attaches it to the
release.

The unit tests run against stubs and in-memory stores: they cover the
matching engine, loop detection, CSV parsing, sanitising, the service, the
front end, the admin handlers and WP-CLI. The SQL in `src/Store/` is not
exercised by CI; test on a staging site before relying on it.

## Roadmap

Ideas, roughly in order. Items marked **good first issue** are small and
well scoped; see [CONTRIBUTING.md](CONTRIBUTING.md#good-first-issues).

- "Test a URL" box on the Redirects tab (uses `Redirects::resolve()`) - **good first issue**
- Filter the list by status, match type and enabled state (dropdowns) - **good first issue**
- `wp redirects 404 list|clear` subcommands - **good first issue**
- Export the 404 log as CSV - **good first issue**
- Screen option for rows per page - **good first issue**
- `--format=json` for `wp redirects test` - **good first issue**
- Redirect groups/tags and per-group enable switch
- Date-based expiry for temporary redirects
- Integration tests against a real WordPress and MySQL (wp-env) in CI
- WordPress.org release with screenshots

## Contributing

Contributions of every size are welcome: bug reports, tests, docs,
translations and code. Start with [CONTRIBUTING.md](CONTRIBUTING.md), which
has the setup, the coding standards and a list of beginner tasks, and please
follow the [Code of Conduct](CODE_OF_CONDUCT.md).

## Security and license

- [SECURITY.md](SECURITY.md): please report vulnerabilities privately.
- [CHANGELOG.md](CHANGELOG.md).

Redirect Manager is free software under the [GPL-2.0-or-later](LICENSE).
Copyright (c) 2026 Saizul Amin ([saizul.com](https://saizul.com)).

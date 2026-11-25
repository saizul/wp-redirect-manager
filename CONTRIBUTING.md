# Contributing

Thanks for helping improve Redirect Manager. Bug reports, fixes, tests,
documentation and translations are all welcome, and first-time contributors
are especially welcome: the matching engine is a few small, pure classes and
every part has tests.

By taking part you agree to follow the [Code of Conduct](CODE_OF_CONDUCT.md).

## Ways to help

- **Pick a beginner task** from the list below or from issues labelled
  [`good first issue`](https://github.com/saizul/wp-redirect-manager/labels/good%20first%20issue).
  Comment on the issue first so two people do not work on the same thing.
- **Report a bug** with the "Bug report" template. Include the rule (source,
  target, match type, query mode), the requested URL and the output of
  `wp redirects test <url>` or `curl -I <url>`.
- **Share a CSV from another redirect tool** (with private URLs removed) that
  does not import cleanly. Those files become test cases.
- **Translate** the plugin: `languages/wp-redirect-manager.pot` is the
  template.

Security problems go to the private channel in [SECURITY.md](SECURITY.md),
not to public issues.

## Good first issues

Each task is small, has a clear "done" and touches one or two files. Tests go
in `tests/Unit/`.

1. **"Test a URL" box on the Redirects tab.** Add a small GET form to
   `AdminPage::render_redirects()` that runs `Redirects::resolve()` and shows
   the status, target and rule ID (escaped). Test the output in
   `tests/Unit/AdminTest.php`.
2. **Filter dropdowns for the redirects list.** `DbRuleStore::query()`
   already accepts `status`, `match_type` and `enabled`; add the dropdowns
   with `RedirectsTable::extra_tablenav()` and keep the values in the
   pagination links.
3. **Export the 404 log as CSV.** A button on the 404 tab (nonce +
   capability, like `handle_export()`), columns `url,hits,referrer,user_agent,first_seen,last_seen`.
   Reuse `Csv::cell()` against formula injection.
4. **Screen option for rows per page.** Register `redirect_manager_per_page`
   with `add_screen_option()` on the `load-` hook and save it with the
   `set_screen_option_redirect_manager_per_page` filter.
5. **`--format=json` for `wp redirects test`.** Print `status`, `url`,
   `rule_id` and `match_type` as JSON; keep the current text output as the
   default.
6. **Warn about regex sources without `^`.** In `Sanitizer::rule()` nothing
   changes, but `AdminPage` shows a notice after saving a regex rule whose
   pattern does not start with `^`, because unanchored patterns match more
   than people expect.
7. **Translate the plugin into your language** with Poedit or Loco Translate
   from `languages/wp-redirect-manager.pot`, and open a PR with the `.po`
   file.

## Development setup

```bash
git clone https://github.com/saizul/wp-redirect-manager.git
cd wp-redirect-manager
composer install
composer test           # PHPUnit on WordPress stubs and in-memory stores
composer phpcs          # WordPress Coding Standards + PHPCompatibilityWP
composer phpcbf         # fix what phpcs can fix automatically
composer lint           # php -l
composer make-pot       # regenerate the translations template
```

PHP 7.4 or newer is required. Dependencies are resolved for PHP 7.4
(`config.platform`), so the same lock file works across the CI matrix. There
is no JavaScript build.

To try the plugin in a real site, symlink or copy the folder into
`wp-content/plugins/` of a local WordPress install (wp-env, LocalWP, DDEV or
similar) and activate it. `wp redirects test /some/path` and
`curl -I http://localhost:8888/some/path` show what happens.

## Where things go

- `src/Matcher.php`, `src/Index.php`, `src/Path.php` - **all** matching
  logic. Pure functions of rules and a URI; keep them free of WordPress
  calls so they stay easy to test (`tests/Unit/MatcherTest.php`,
  `PathTest.php`).
- `src/LoopDetector.php` - loop and chain detection (`LoopDetectorTest.php`).
- `src/Sanitizer.php` - every rule input passes through here
  (`SanitizerTest.php`).
- `src/Csv.php` - the CSV format (`CsvTest.php`). If you change columns,
  update the README section and `examples/redirects.csv`.
- `src/Redirects.php` - the service used by the admin, the CLI and the slug
  watcher: save, enable, import, export, slug changes, cached index.
- `src/Store/` - storage contracts and the `$wpdb` implementations. Tests use
  `tests/Support/Memory*Store.php`; keep both in sync.
- `src/Frontend.php`, `src/SlugWatcher.php` - WordPress hooks.
- `src/Admin/`, `src/Cli/` - admin screen and WP-CLI.
- `tests/Stubs/` - light WordPress and WP-CLI stubs. Add a stub when you use
  a new WordPress function; keep it minimal and record calls in `WpStubs` so
  tests can assert them.

## Coding standards

- WordPress Coding Standards (`composer phpcs` must pass) and PHP 7.4
  compatibility: no union types, no `match`, no named arguments, no
  constructor promotion, no `str_contains()`.
- Escape all output, sanitise all input, check capabilities and nonces.
- Every SQL query uses `$wpdb->prepare()` with `%i` for table names.
- No remote requests, no tracking, no upsells, no new runtime dependencies.
- Translatable strings use the `wp-redirect-manager` text domain.
- Keep pull requests focused. Update `CHANGELOG.md` under "Unreleased".

## Pull requests

1. Fork the repository and create a branch (`feature/test-url-box`,
   `fix/prefix-trailing-slash`).
2. Make your change with tests.
3. Run `composer test`, `composer phpcs` and, if strings changed,
   `composer make-pot`, and commit the regenerated file.
4. Open the pull request and fill in the checklist.

CI must pass before a pull request is merged.

## Releases

Maintainers bump the version in `wp-redirect-manager.php` (header and
`WP_REDIRECT_MANAGER_VERSION`), `src/Plugin.php` (`VERSION`), `readme.txt`
(`Stable tag`) and `CHANGELOG.md`, then publish a GitHub release tagged
`vX.Y.Z`. The release workflow checks that the versions match, builds
`wp-redirect-manager.zip` and attaches it to the release.

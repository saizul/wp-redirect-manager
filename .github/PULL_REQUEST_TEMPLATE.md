## What does this change?

<!-- One or two sentences. Link the issue: "Closes #123". -->

## Type

- [ ] Bug fix
- [ ] Matching engine, loop detection or CSV format
- [ ] Admin screen, 404 monitor, WP-CLI or hooks
- [ ] Documentation or translations
- [ ] Tests or tooling

## Checklist

- [ ] Matching logic stays in `src/Matcher.php` / `src/Index.php` / `src/Path.php` and has tests.
- [ ] New rule fields are sanitised in `src/Sanitizer.php` and tested.
- [ ] SQL uses `$wpdb->prepare()`; output is escaped; forms check a capability and a nonce.
- [ ] The in-memory stores in `tests/Support/` match any change to `src/Store/`.
- [ ] No remote requests, no tracking, no new runtime dependencies.
- [ ] `composer test` and `composer phpcs` pass.
- [ ] `composer make-pot` run and `languages/wp-redirect-manager.pot` committed (if strings changed).
- [ ] `CHANGELOG.md` updated under "Unreleased".

## How did you test it on a real site?

<!-- WordPress and PHP versions, permalink structure, curl -I output or wp redirects test output. -->

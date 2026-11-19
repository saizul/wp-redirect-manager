# Security policy

## Supported versions

| Version | Supported |
| ------- | --------- |
| 0.1.x   | yes       |

## Reporting a vulnerability

Please do **not** open a public issue. Use GitHub's private vulnerability
reporting ("Security" tab, "Report a vulnerability") on
<https://github.com/saizul/wp-redirect-manager>.

Include the affected version, a description and steps to reproduce. You can
expect an acknowledgement within 7 days. Please give us reasonable time to
release a fix before disclosing the issue.

An open redirect that anyone can trigger without creating a rule, a way to
inject headers through a source or target, or a way to save a redirect loop
are security bugs too; report them privately.

## How the plugin protects your site

- **Admin**: Tools > Redirects requires `manage_options` (filterable with
  `redirect_manager_capability`). Every state-changing request checks the
  capability and a nonce: the add/edit form, each row action (one nonce per
  action and row), list-table bulk actions, import, export and "clear log".
  The settings tab uses the Settings API.
- **Input**: every rule goes through one sanitiser. Statuses are limited to
  301, 302, 307, 308 and 410, match types and query modes to fixed lists.
  Control characters (CR/LF) are removed from sources and targets, so no
  header can be injected. Targets must be a path starting with `/` or an
  `http(s)://` URL; `javascript:`, `data:`, `//host` and `/\host` targets are
  rejected. Regex sources must compile. Notes are plain text.
- **Redirects** are sent with `wp_redirect()`, which sanitises the Location
  header. Targets are set by administrators only; visitors cannot influence
  them except through capture groups and passed query strings, whose values
  are URL-encoded again.
- **Loops**: saving, enabling and importing refuse rules that loop or build
  chains longer than 10 hops; at runtime a request is never redirected to
  itself.
- **SQL**: every query uses `$wpdb->prepare()`; table names use `%i`; sort
  columns are allowlisted.
- **Output**: everything on the admin screens is escaped with `esc_html()`,
  `esc_attr()`, `esc_url()` or `esc_textarea()`. The CSV export prefixes
  cells starting with `=`, `+`, `-` or `@` to stop formula injection in
  spreadsheets.
- **Uploads**: CSV imports must be a real upload (`is_uploaded_file()`) of at
  most 5 MB; the content is parsed, never executed or stored as a file.
- **404 log**: no IP addresses; URLs, referrers and user agents are
  sanitised, truncated and escaped on output; old entries are deleted daily.
- **No remote requests**, no tracking, no external assets.
- **Uninstall** drops both tables and deletes the options and the cron event
  on every site of a network.

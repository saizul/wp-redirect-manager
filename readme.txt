=== Redirect Manager ===
Contributors: saizul
Tags: redirect, 301, 404, redirection, seo
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight 301/302/307/308/410 redirects with exact, prefix and regex matching, a 404 monitor, CSV import/export, loop detection and WP-CLI.

== Description ==

Redirect Manager keeps old URLs working without slowing your site down. Redirects are compiled into one cached option, so exact matches are a lookup, not a database query. No tracking, no external services, no upsells.

**Redirects**

* 301, 302, 307 and 308 redirects, and 410 Gone.
* Exact path, path prefix (the rest of the path is carried over) and regular expressions with $1, $2 capture groups.
* Query strings: ignore them, require an exact match, or pass them on to the target.
* Case-insensitive and trailing-slash-insensitive matching.
* Hit counter and last hit for every redirect.
* Loop detection: a redirect that would loop, or a chain longer than 10 hops, cannot be saved.

**404 monitor**

* Logs 404s once per URL with a counter, the last referrer and user agent. No IP addresses.
* One click "Create redirect" from any entry.
* Retention in days, a maximum number of URLs and an ignore list.

**Automatic redirects**

* When a published post, page or public custom post type changes its URL, a 301 from the old URL is added. Older automatic redirects of the same post are updated, so there are no chains.

**Import, export and WP-CLI**

* CSV import and export with a documented column order: source,target,status,match_type,query,enabled,note. Header row optional, dry run, per-line error report.
* wp redirects add, list, import, export, delete and test, plus wp redirects 404 list and clear.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/ or install the zip from Plugins > Add New > Upload Plugin.
2. Activate Redirect Manager.
3. Go to Tools > Redirects and add your first redirect.

== Frequently Asked Questions ==

= Why is my redirect not used? =

Run wp redirects test /your/path, or check that the rule is enabled and that the source is a path from the domain root. A page cache may still serve the old page; purge it.

= Can I redirect to another domain? =

Yes. Use a full https:// URL as the target. A prefix rule with source / moves a whole site.

= What does 410 do? =

It tells search engines a page is gone for good. Visitors see your theme's 404 page.

= Does it store personal data? =

The 404 log stores URLs, referrers and user agents, no IP addresses, and deletes them after the retention period. It can be switched off.

== Changelog ==

= 0.1.0 =
* First release.

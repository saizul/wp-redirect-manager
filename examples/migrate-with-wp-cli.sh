#!/usr/bin/env sh
# Example: move redirects from staging to production with WP-CLI.
set -eu

# On staging: export everything (hits and last hit are included, and ignored on import).
wp --path=/var/www/staging redirects export /tmp/redirects.csv

# On production: check the file first, then import. Existing rules with the
# same source and match type are updated; use --skip-existing to keep them.
wp --path=/var/www/production redirects import /tmp/redirects.csv --dry-run
wp --path=/var/www/production redirects import /tmp/redirects.csv

# Spot-check a few URLs.
wp --path=/var/www/production redirects test /old-page
wp --path=/var/www/production redirects test '/old-blog/2024/post?utm_source=mail'

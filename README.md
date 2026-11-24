# Hermaily for WordPress

WordPress plugin that sends every `wp_mail()` message through the [Hermaily](https://hermaily.com) HTTPS API
instead of PHP's `mail()` or SMTP. Hosts often block ports 587 and 465; 443 never.

- Settings → Hermaily: API key (or `define( 'HERMAILY_API_KEY', 'hm_…' );` in `wp-config.php`), default sender,
  force sender (the original sender becomes Reply-To), test email.
- Log tab: date, recipients, subject and the real status from `GET /v1/messages/{id}`, with its events. Bodies are
  never stored; retention is configurable (0 turns the log off).
- When the API offers `GET /v1/domain`, saving a key proposes a sender on its domain and the page warns if the
  domain is unverified or the sender is outside it. A 404 there is handled as "unknown".

The user-facing description, FAQ and the External services section are in [`readme.txt`](readme.txt).
API contract: https://hermaily.com/openapi.yaml.

## Development

```sh
composer install
bin/install-wp-tests.sh wp_tests wp wp12345 localhost latest   # once
vendor/bin/phpunit                    # single site
WP_MULTISITE=1 vendor/bin/phpunit     # multisite
vendor/bin/phpcs                      # WordPress Coding Standards + PHP 7.4 compatibility
./build-zip.sh                        # dist/hermaily-<version>.zip, honouring .distignore
```

The tests fake the Hermaily API through `pre_http_request` (`tests/class-hermaily-testcase.php`); no test
reaches the network. Run Plugin Check against the built zip, not the repository:

```sh
unzip -q dist/hermaily-1.0.0.zip -d /path/to/wordpress/wp-content/plugins/
wp plugin check hermaily
```

Translations: `languages/hermaily.pot` (regenerate with
`wp i18n make-pot . languages/hermaily.pot --exclude=vendor,tests,libraries,dist`) and the Spanish catalogue
`languages/hermaily-es_ES.po`. WordPress.org loads translations from translate.wordpress.org, so import the `.po`
there once the plugin is approved; the plugin does not call `load_plugin_textdomain()` (Plugin Check rejects it).

`libraries/dfx-plugin-showcase/` is the shared "More plugins" tab, copied with that repository's `bin/sync.sh`.

## Layout

| File | What it does |
|---|---|
| `hermaily.php` | Header, hooks, activation |
| `includes/class-hermaily-client.php` | HTTP client: `send()`, `message()`, `domain()`; errors as `WP_Error` `hermaily_<code>` |
| `includes/class-hermaily-mailer.php` | `pre_wp_mail` handler; builds the `/v1/send` body the way `wp_mail()` reads headers and filters |
| `includes/class-hermaily-log.php` | `{prefix}hermaily_log` table, daily purge, status refresh |
| `includes/class-hermaily-settings.php` | Settings page, test email, log views |
| `uninstall.php` | Deletes options and the log table |

## Release

Bump `Version` in `hermaily.php`, `HERMAILY_VERSION`, `Stable tag` and the changelog in `readme.txt`, then publish a
GitHub release: `.github/workflows/deploy.yml` deploys to WordPress.org SVN (slug `hermaily`) once the plugin is
approved and the `SVN_USERNAME`/`SVN_PASSWORD` secrets exist.

License: GPLv3 or later.

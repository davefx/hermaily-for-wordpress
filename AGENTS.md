# AGENTS.md

Read `README.md` first: layout, commands and release.

- TDD is mandatory: write the failing test first (`tests/`, fake API in `tests/class-hermaily-testcase.php`).
- Before finishing: `vendor/bin/phpunit`, `WP_MULTISITE=1 vendor/bin/phpunit`, `vendor/bin/phpcs`, and Plugin Check
  on the built zip with no errors.
- User-facing strings in English with text domain `hermaily`; update `languages/hermaily.pot` and the Spanish `.po`
  (and `.mo`) when they change.
- Never store message bodies, never print the saved API key back into a page, never send anything to Hermaily
  except what `readme.txt` → External services lists. If that changes, update that section.
- Prefix everything `hermaily`/`Hermaily_`. Core `wp_mail*` hooks are fired on purpose and carry phpcs:ignore notes.
- Commits and pushes only when David asks.

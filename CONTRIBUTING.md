# Contributing

Thanks for your interest in improving Waygate.

## Setup

```bash
git clone https://github.com/imagewize/waygate.git
cd waygate
composer install
```

## Checks

CI runs these on every pull request; run them before you push:

```bash
find . -name "*.php" -not -path "*/vendor/*" -exec php -l {} \;   # syntax
vendor/bin/phpcs                                                  # WordPress Coding Standards
vendor/bin/phpunit --configuration phpunit.xml                    # unit tests
```

The unit tests run without WordPress: `tests/bootstrap.php` stubs the WordPress functions and
classes the plugin uses. Keep logic in static methods that take plain arrays, so it stays testable
that way. Plugin Check also runs in CI.

## Layout

- `waygate.php` — plugin header, constants, autoloader, `plugins_loaded` init
- `includes/class-pattern-lab.php` — pattern discovery and page assembly
- `includes/class-ai-integration.php` — prompts, provider fallback, text rewriting
- `includes/class-abilities-api.php`, `class-rest-api.php`, `class-cli.php` — the integrations
- `includes/class-admin.php` — Tools → Waygate and the editor meta box
- `assets/js/abilities.js` — the client-side `waygate/insert-pattern` ability
- `tests/Unit/` — PHPUnit tests

## Commits and pull requests

- One logical change per commit, with a conventional prefix: `feat:`, `fix:`, `docs:`,
  `refactor:`, `test:`, `chore:`.
- Open pull requests against `main`. Describe what changed and how you tested it.
- Add user-facing changes to the `[Unreleased]` section of `CHANGELOG.md`.

## Releases

A release bumps the version in `waygate.php` (header and `WAYGATE_VERSION`), `readme.txt`
(`Stable tag`, changelog, upgrade notice) and `CHANGELOG.md`. Publishing a GitHub release tagged
`v<version>` builds the zip, leaving out everything in `.distignore`.

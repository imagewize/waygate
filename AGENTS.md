# AGENTS.md

Guidance for coding agents working in this repository. See `CLAUDE.md` for detailed architecture notes.

## What this is

Waygate is a standalone WordPress plugin (`imagewize/waygate`), an AI-powered pattern page builder for the Elayne block theme family. PHP 8.3+, WordPress 7.0+ (Abilities API and WP AI Client).

## Commands

- Tests: `vendor/bin/phpunit --configuration phpunit.xml`
- Coding standards (CI ruleset, `includes/` + `waygate.php`): `vendor/bin/phpcs`
- Syntax check (matches CI): `find . -name "*.php" -not -path "*/vendor/*" -exec php -l {} \;`

## Testing unreleased changes: sync, don't release

Waygate is installed and tested on **demo.imagewize.com** (host `demo.imagewize.test`, Bedrock root
`~/code/imagewize.com/demo`) as a pinned Composer dependency from Packagist — not symlinked to this
working copy. Do not cut a release to test local changes; sync instead, with
[wp-ops](https://github.com/imagewize/wp-ops) (`wp-ops` is on PATH):

```bash
export SITE_ROOT=~/code/imagewize.com/demo/web/app
wp-ops rsync-package-to-site --dry-run plugin waygate ~/code/waygate   # preview first
wp-ops rsync-package-to-site plugin waygate ~/code/waygate
```

- `SITE_ROOT` is the Bedrock content directory (the one holding `plugins/` and `themes/`).
- The sync is dist-faithful (`--delete --delete-excluded`, honouring the composer archive excludes),
  so what you test is what ships. A `composer update imagewize/waygate` on the demo site restores
  the released code.
- The AI form lives on **Tools → Waygate** on any of the demo site's subsites (multisite: `/`,
  `/spa/`, `/legal/`, etc.).
- Set `MISTRAL_API_KEY` in the demo site's `.env` for AI generation.

## Debugging on the demo site

Run commands inside the Lima-based VM from the repo root (not the `trellis/` directory, which the
CLI reads as an uninitialized project and reports "VM is not running" while it actually runs):

```bash
cd ~/code/imagewize.com && trellis vm shell --workdir /srv/www/demo.imagewize.com/current -- <command>
```

Logs are readable with the current VM user — no sudo needed:

- WordPress `debug.log`: `/srv/www/demo.imagewize.com/logs/debug.log` (path comes from
  `WP_DEBUG_LOG` in the demo site's `.env`). Filter with `grep -E "18-Nov-2026.09:2"` style
  patterns — the trellis CLI strips quotes, so avoid spaces in arguments.
- The demo vhost's nginx `access.log` and `error.log` sit next to it in
  `/srv/www/demo.imagewize.com/logs/` (each Trellis site logs there, not in `/var/log/nginx`).
- `/var/log/nginx/error.log` and `/var/log/php8.4-fpm.log` are root-only; the site-local logs are
  the ones worth reading.

Known gotcha: a blank wp-admin page with `net::ERR_INCOMPLETE_CHUNKED_ENCODING` in the browser and
a short `200` response in `access.log` means nginx's `fastcgi_read_timeout` (60 seconds by default)
cut a long-running request — look for `upstream timed out` in the site's `error.log`. This is what
killed AI page generation before the stepwise flow (`waygate/v1/generate/*`) landed.

## Docs and commits

- `docs/` holds public reference only; roadmaps and plans live in the private `imagewize.com` repo,
  never here.
- Conventional commit prefixes (`feat:`, `fix:`, `docs:`, `test:`, `chore:`), atomic commits.
- `CHANGELOG.md` gets an entry under `## [Unreleased]` for every user-visible change, in the
  Keep-a-Changelog style of the existing entries.

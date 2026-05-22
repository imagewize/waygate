# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Waygate is a standalone WordPress plugin that provides AI-powered pattern page builder capabilities for the Elayne block theme. It integrates with the WordPress Abilities API (WP 7.0+) and WordPress AI Client to generate pages from natural-language descriptions.

- **Package:** `imagewize/waygate` on Packagist
- **Namespace:** `Imagewize\Waygate`
- **Requires:** PHP 8.0+, WordPress 6.5+

## Commands

**Install dependencies:**
```bash
composer install
```

**PHP syntax check (matches CI):**
```bash
find . -name "*.php" -not -path "*/vendor/*" -exec php -l {} \;
```

**Run PHP CodeSniffer:**
```bash
vendor/bin/phpcs --standard=WordPress includes/ waygate.php
```

**Run PHPUnit:**
```bash
vendor/bin/phpunit --configuration phpunit.xml
```

## Architecture

All classes live in `includes/` with PSR-4 autoloading (`Imagewize\Waygate` → `includes/`). Each class uses a static `init()` method hooked to `plugins_loaded` from `waygate.php`.

### Class Responsibilities

**`PatternLab`** (`includes/class-pattern-lab.php`) — The data layer. Queries WordPress's registered block patterns to extract those belonging to the active Elayne theme, returning structured metadata (slug, title, description, categories, keywords). Also handles composing `wp:pattern` blocks into a page and calling `wp_insert_post`.

**`AbilitiesApi`** (`includes/class-abilities-api.php`) — Registers two WordPress Abilities (WP 7.0+ feature):
- `elayne/list-patterns` — lists available patterns, optionally filtered by category; requires `edit_posts`
- `elayne/create-page` — creates a draft page from an ordered pattern slug list; requires `publish_pages`

Each ability has a JSON input/output schema for capability validation.

**`AiIntegration`** (`includes/class-ai-integration.php`) — Orchestrates AI page generation. Registers a Mistral provider with the WP AI Client registry, then in `generate_page()` builds a prompt from all available patterns, sends it to the AI (with fallback chain: Mistral → Claude → OpenAI → Gemini), parses the JSON response, and delegates to `PatternLab::create_page()`. Enforces layout constraints: 3–7 patterns, hero first, CTA last, no consecutive grid patterns.

**`Admin`** (`includes/class-admin.php`) — WordPress admin UI at **Tools → Waygate**. Renders status indicators (WP AI Client, Abilities API, Mistral provider, Elayne patterns), the AI generation form, and a searchable pattern catalog. Handles POST form submission with nonce verification and displays success/error notices.

### Initialization Order

```
plugins_loaded → PatternLab::init()
             → AiIntegration::init()
             → AbilitiesApi::init()
             → Admin::init()
```

`PatternLab` must init first since the other classes depend on its pattern data.

### External Dependencies (optional)

- **WordPress AI Client** — configured via Settings → Connectors; required for AI generation
- **Mistral provider** (`saarnilauri/ai-provider-for-mistral`) — set `MISTRAL_API_KEY` in the site `.env`
- **Elayne theme** — provides the block patterns that this plugin orchestrates

## Commits

Use atomic commits: each commit represents one logical change and must build/pass on its own. Group files that belong to the same logical change into one commit; split files that represent different changes into separate commits. Typical split for a patch:

- `fix: switch autoloader from psr-4 to classmap` — the code change
- `docs: update CHANGELOG for v0.3.0` — changelog entry
- `docs: add CLAUDE.md` — documentation

Never bundle unrelated changes into a single commit. Use conventional commit prefixes: `feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `chore:`.

Never mention Claude, Claude Code, or Claude AI in commit messages.

## Plugin Constants

- `WAYGATE_VERSION` — current plugin version
- `WAYGATE_PLUGIN_DIR` — absolute path to plugin directory
- `WAYGATE_PLUGIN_URL` — URL to plugin directory

## Version Bump Checklist

When releasing a new version, update the version string in **three places**:

1. `waygate.php` line 8 — `* Version:` plugin header
2. `waygate.php` line 19 — `define( 'WAYGATE_VERSION', ... )` constant
3. `CHANGELOG.md` — add a new `## [x.y.z] - YYYY-MM-DD` section at the top

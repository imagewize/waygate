# Changelog

All notable changes to Waygate will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.6.0] - 2026-05-24

### Added
- Six built-in prompt templates (Homepage, About, Services, Contact, Landing Page, Portfolio) in `AI_Integration::get_prompt_templates()`; filterable via the `waygate_prompt_templates` hook so third-party themes/plugins can add or remove templates
- **Quick Template** dropdown in the admin AI generation form — selecting a template pre-fills the description textarea; a confirmation dialog fires when the textarea already has content
- `[placeholder]` substitution note added to the description field hint
- PHPUnit tests for `get_prompt_templates()` and the `waygate_prompt_templates` filter
- `phpcs.xml` PHP CodeSniffer configuration committed so `vendor/bin/phpcs` works without arguments

### Changed
- Renamed plugin classes to WordPress underscore convention: `Pattern_Lab`, `Abilities_API`, `AI_Integration` (previously `PatternLab`, `AbilitiesApi`, `AiIntegration`) to comply with WordPress coding standards and allow WordPress.org submission
- Removed `WordPress.Files.FileName.InvalidClassFileName` PHPCS exclusion from `phpcs.xml` — the renamed classes now pass the sniff cleanly
- All PHP files now pass WPCS coding standards (resolved via `phpcs.xml` ruleset)

## [0.5.0] - 2026-05-24

### Added
- AI reasoning and selected pattern slugs are now persisted as post meta (`_waygate_reasoning`, `_waygate_patterns`, `_waygate_generated_at`) on every Waygate-generated page
- "Waygate" meta box on the page editor sidebar — always shows the AI reasoning sentence; shows generation timestamp and ordered pattern slug list when `WP_ENV=development`
- Success notice in Tools → Waygate now shows the ordered pattern slugs when `WP_ENV=development`

### Documentation
- `README.md` updated with AI reasoning and developer debug info features; version badge bumped to 0.5.0

## [0.4.0] - 2026-05-24

### Added
- Feature detection: `AiIntegration::is_text_generation_supported()` hides the AI generation form and updates the status badge when no configured provider supports text generation
- Ability annotations: `readonly` on `elayne/list-patterns` and `idempotent` on `elayne/create-page` for clearer REST API semantics
- Generic pattern prefix support via `waygate_pattern_prefixes` filter — plugin now works with any block theme, not just Elayne
- Category filter dropdown in the admin pattern catalog, with server-side filtering and a clear link
- PHPUnit 11 test infrastructure (`phpunit.xml`, `tests/bootstrap.php`) with 10 unit tests covering `PatternLab` prefix filtering and `AiIntegration` feature detection
- PHPUnit test step added to CI workflow

### Changed
- Minimum PHP requirement raised to **8.3+** (aligns with WordPress recommended version)
- Minimum WordPress requirement raised to **7.0+** (required for AI Client and Abilities API)
- Admin pattern catalog heading updated to show filtered count vs. total
- Category display in pattern table strips namespace prefix generically (works with any theme namespace)
- Error message for missing valid patterns is no longer Elayne-specific
- `composer.lock` now tracked for reproducible installs; platform pinned to PHP 8.3

## [0.3.2] - 2026-05-24

### Added
- `docs/ROADMAP.md` with phased improvement plan covering WP 7.0 AI Client and Abilities API features

### Changed
- Exclude `docs/`, `tests/`, `.github/`, and non-README markdown from Composer archive so they are not shipped to end users via Packagist

## [0.3.1] - 2026-05-22

### Fixed
- Prefix `WP_Block_Patterns_Registry` with a global namespace backslash (`\`) so PHP resolves it to the WordPress global class instead of `Imagewize\Waygate\WP_Block_Patterns_Registry`, which caused a fatal error when loading the Tools → Waygate admin page

## [0.3.0] - 2026-05-22

### Fixed
- Switch Composer autoloading from `psr-4` to `classmap` to resolve class-skipping warnings caused by WordPress-style `class-hyphenated-name.php` filenames not complying with PSR-4 filename requirements

### Added
- `CLAUDE.md` with project guidance for Claude Code

## [0.2.0] - 2026-05-22

### Added
- Standalone plugin structure (extracted from Elayne demo MU plugin)
- `PatternLab` class for pattern listing and page creation
- `AiIntegration` class for Mistral provider registration and AI page generation
- `AbilitiesApi` class for WordPress 7.0 Abilities API registration
- `Admin` class for WP admin interface (Tools → Waygate)
- `composer.json` for Packagist distribution
- GitHub Actions CI workflow

### Changed
- Namespace moved from global functions to `Imagewize\Waygate`
- Admin page slug changed from `elayne-ai-test` to `waygate`
- Nonce action renamed from `elayne_ai_generate` to `waygate_generate`

## [0.1.0] - 2026-05-01

### Added
- Initial version as MU plugin inside Elayne demo site
- Pattern listing via `elayne_ai_get_patterns()`
- Page creation via `elayne_ai_create_page()`
- AI page generation using WP AI Client (WordPress 7.0+)
- Mistral provider registration
- Abilities API integration (`elayne/list-patterns`, `elayne/create-page`)
- Admin interface for pattern discovery and AI generation

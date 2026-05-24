# Changelog

All notable changes to Waygate will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

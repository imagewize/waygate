=== Waygate ===
Contributors: imagewize
Tags: patterns, page-builder, ai, block-theme, gutenberg
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 0.9.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered pattern page builder for block themes. Describe a page in plain language; Waygate assembles it from registered block patterns.

== Description ==

Waygate lets you assemble WordPress pages from block patterns — manually or via a natural-language AI prompt powered by the WordPress AI Client (WordPress 7.0+). It works with any block theme; Elayne (https://github.com/imagewize/elayne) is the primary supported theme.

= What you get =

* **Pattern catalog** — Browse registered block patterns with slug, title, and categories; filter by category
* **AI page generation** — Describe the page you want; the AI picks patterns and creates a draft
* **Themed text personalization** — A second AI call rewrites all headings, paragraphs, and button labels inside the selected patterns to match your topic; uncheck the option for a faster single-call result with original placeholder text
* **AI reasoning** — The AI's one-sentence explanation of its pattern choices is shown after generation and persisted as post meta on the created page
* **Developer debug info** — When `WP_ENV=development`, the page editor sidebar and the generation notice also show the ordered pattern slugs and generation timestamp
* **Prompt templates** — Six built-in page templates (Homepage, About, Services, Contact, Landing Page, Portfolio) pre-fill the AI prompt; extend via the `waygate_prompt_templates` filter
* **Feature detection** — AI form is hidden automatically when no provider supports text generation
* **Abilities API** — Exposes `elayne/list-patterns` and `elayne/create-page` server abilities plus a `waygate/insert-pattern` client-side ability for the block editor (WordPress 7.0+)
* **REST API** — `GET /wp-json/waygate/v1/patterns` and `POST /wp-json/waygate/v1/pages` for headless and external tool integration
* **Multi-provider** — Works with Mistral, Claude, OpenAI, or Gemini via the WordPress AI Client
* **Any block theme** — Default prefix is `elayne/`; extend via the `waygate_pattern_prefixes` filter

= Requirements =

* WordPress 7.0 or higher
* PHP 8.3 or higher
* A block theme (Elayne recommended)
* WordPress AI Client, configured with a provider, for AI generation features

= AI Providers =

AI page generation requires a running provider configured through WordPress 7.0's built-in Connectors system (or an environment variable on Bedrock-style hosting): Mistral, Claude (Anthropic), OpenAI, or Gemini. See the plugin's README on GitHub for full setup instructions.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/waygate` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to Tools → Waygate to browse the pattern catalog and, once an AI provider is configured, generate pages from a description.

== Frequently Asked Questions ==

= Does this plugin work with any theme? =

Yes, in the sense that it works with any block theme that registers its own block patterns. Waygate discovers patterns registered under a configurable prefix (`elayne/` by default, filterable via `waygate_pattern_prefixes`) and assembles pages from them. Elayne is the primary supported theme and ships the pattern library Waygate is designed around.

= Do I need an AI provider to use this plugin? =

No. The pattern catalog and manual page assembly work without one. AI-driven generation from a natural-language description requires a configured WordPress AI Client provider (Mistral, Claude, OpenAI, or Gemini).

= What happens if no AI provider supports text generation? =

The AI generation form is hidden automatically and the admin screen falls back to the pattern catalog.

= Can I use this outside the WordPress admin? =

Yes. Waygate exposes REST endpoints (`GET /wp-json/waygate/v1/patterns`, `POST /wp-json/waygate/v1/pages`) and WordPress Abilities (`elayne/list-patterns`, `elayne/create-page`) for headless and external tool integration.

== Screenshots ==

1. Tools → Waygate admin screen with the pattern catalog and AI generation form

== Changelog ==

= 0.9.0 =
* Added: Themed text personalization — a second AI call rewrites all visible text (headings, paragraphs, button labels) in each selected pattern to match the prompt's topic, with a checkbox to opt out for faster, original-placeholder generation
* Added: `_waygate_personalized` post meta and a result notice showing whether text was personalized
* Security: AI-rewritten block content is passed through `wp_kses_post()` before insertion

= 0.8.0 =
* Added: Client-side ability `waygate/insert-pattern`, registered via `@wordpress/abilities`, inserts a pattern block at the current cursor position in the block editor

= 0.7.0 =
* Added: REST API endpoints under `waygate/v1` — `GET /patterns` and `POST /pages` (drafts only) — with per-user rate limiting

= 0.6.0 =
* Added: Six built-in prompt templates with a Quick Template dropdown in the admin form
* Changed: Plugin classes renamed to WordPress underscore convention; all files pass WPCS

= 0.5.0 =
* Added: AI reasoning and selected pattern slugs persisted as post meta; "Waygate" meta box in the page editor sidebar

Older entries are trimmed to keep this section within the 5000-character
limit WordPress.org enforces. The complete history is in CHANGELOG.md:
https://github.com/imagewize/waygate/blob/main/CHANGELOG.md

== Upgrade Notice ==

= 0.9.0 =
Adds optional AI text personalization for generated pages. No breaking changes.

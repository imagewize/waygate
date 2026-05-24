# Waygate

**AI-Powered Pattern Page Builder for Block Themes**

Waygate lets you assemble WordPress pages from block patterns — manually or via a natural-language AI prompt powered by the WordPress AI Client (WordPress 7.0+). Works with any block theme; [Elayne](https://github.com/imagewize/elayne) is the primary supported theme.

> **Beta** — v0.4.0. Use on staging/development sites; not yet recommended for production.

---

## Features

- **Pattern catalog** — Browse registered block patterns with slug, title, and categories; filter by category
- **AI page generation** — Describe the page you want; the AI picks patterns and creates a draft
- **Feature detection** — AI form is hidden automatically when no provider supports text generation
- **Abilities API** — Exposes `elayne/list-patterns` and `elayne/create-page` abilities for WP 7.0+
- **Multi-provider** — Works with Mistral, Claude, OpenAI, or Gemini via WP AI Client
- **Any block theme** — Default prefix is `elayne/`; extend via the `waygate_pattern_prefixes` filter

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.3+ |
| WordPress | 6.5+ |
| Block theme | Any; Elayne recommended |
| WordPress AI Client | 7.0+ (optional, for AI features) |

---

## Installation

### Option A — Composer (recommended for Bedrock)

```bash
composer require imagewize/waygate
```

### Option B — Manual

1. Download the latest release zip from [GitHub Releases](https://github.com/imagewize/waygate/releases)
2. Upload and activate via **Plugins → Add New → Upload Plugin**

---

## Configuration

### AI features (optional)

AI page generation requires a running AI provider. Waygate supports:

**Mistral** (via Composer):

```bash
composer require saarnilauri/ai-provider-for-mistral
```

Then add `MISTRAL_API_KEY=your_key` to your site `.env`.

**Claude / OpenAI / Gemini**: Install the relevant provider plugin via **Settings → Connectors** in WordPress 7.0+.

---

## Usage

1. Go to **Tools → Waygate** in the WordPress admin
2. Browse registered patterns in the catalog; use the category dropdown to filter
3. Optionally type a page description and click **Generate Page** to create an AI-assembled draft
4. Open the draft in the block editor, adjust as needed, and publish

---

## Abilities API (WordPress 7.0+)

When WordPress 7.0's Abilities API is available, Waygate registers two abilities:

| Ability | Description |
|---|---|
| `elayne/list-patterns` | Returns patterns, optionally filtered by category |
| `elayne/create-page` | Creates a draft page from an ordered list of pattern slugs |

---

## Development

```bash
git clone https://github.com/imagewize/waygate.git
cd waygate
composer install
vendor/bin/phpunit --configuration phpunit.xml
```

---

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).

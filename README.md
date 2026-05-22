# Waygate

**AI-Powered Pattern Page Builder for the Elayne Block Theme**

Waygate lets you assemble WordPress pages from [Elayne](https://github.com/imagewize/elayne) block patterns — manually or via a natural-language AI prompt powered by the WordPress AI Client (WordPress 7.0+).

> **Beta** — v0.2.0. Use on staging/development sites; not yet recommended for production.

---

## Features

- **Pattern catalog** — Browse all registered Elayne patterns with slug, title, and categories
- **AI page generation** — Describe the page you want; the AI picks patterns and creates a draft
- **Abilities API** — Exposes `elayne/list-patterns` and `elayne/create-page` abilities for WP 7.0+
- **Multi-provider** — Works with Mistral, Claude, OpenAI, or Gemini via WP AI Client

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.0+ |
| WordPress | 6.5+ |
| Elayne theme | Recommended |
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
2. Browse registered Elayne patterns in the catalog
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
```

---

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).

# Waygate

**AI-Powered Pattern Page Builder for Block Themes**

Waygate lets you assemble WordPress pages from block patterns — manually or via a natural-language AI prompt powered by the WordPress AI Client (WordPress 7.0+). Works with any block theme; [Elayne](https://github.com/imagewize/elayne) is the primary supported theme.

> **Beta** — v0.8.0. Use on staging/development sites; not yet recommended for production.

---

## Features

- **Pattern catalog** — Browse registered block patterns with slug, title, and categories; filter by category
- **AI page generation** — Describe the page you want; the AI picks patterns and creates a draft
- **AI reasoning** — The AI's one-sentence explanation of its pattern choices is shown after generation and persisted as post meta on the created page
- **Developer debug info** — When `WP_ENV=development`, the page editor sidebar and the generation notice also show the ordered pattern slugs and generation timestamp
- **Prompt templates** — Six built-in page templates (Homepage, About, Services, Contact, Landing Page, Portfolio) pre-fill the AI prompt; extend via the `waygate_prompt_templates` filter
- **Feature detection** — AI form is hidden automatically when no provider supports text generation
- **Abilities API** — Exposes `elayne/list-patterns` and `elayne/create-page` server abilities plus a `waygate/insert-pattern` client-side ability for the block editor (WP 7.0+)
- **REST API** — `GET /wp-json/waygate/v1/patterns` and `POST /wp-json/waygate/v1/pages` for headless and external tool integration
- **Multi-provider** — Works with Mistral, Claude, OpenAI, or Gemini via WP AI Client
- **Any block theme** — Default prefix is `elayne/`; extend via the `waygate_pattern_prefixes` filter

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.3+ |
| WordPress | 7.0+ |
| Block theme | Any; Elayne recommended |
| WordPress AI Client | 7.0+ (required for AI features) |

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

AI page generation requires a running AI provider. WordPress 7.0+ supports Mistral, Claude (Anthropic), OpenAI, and Gemini via its built-in Connectors system.

**Installing provider plugins** (Bedrock / Composer):

```bash
composer require wp-plugin/ai-provider-for-mistral
composer require wp-plugin/ai-provider-for-anthropic
# OpenAI and Gemini can be installed from Settings → Connectors in the WP admin
```

**Configuring API keys** — every provider supports two methods; environment variable takes priority over the database:

| Provider | Env var | Admin UI |
|---|---|---|
| Mistral | `MISTRAL_API_KEY` in `.env` | Settings → Connectors |
| Claude (Anthropic) | `ANTHROPIC_API_KEY` in `.env` | Settings → Connectors |
| OpenAI | `OPENAI_API_KEY` in `.env` | Settings → Connectors |
| Google (Gemini) | `GOOGLE_API_KEY` in `.env` | Settings → Connectors |

On Bedrock, add the key to your site `.env` and it is picked up automatically. On standard WordPress installs, enter the key directly in **Settings → Connectors** and it is stored in the database.

**Mistral via Composer library** (non-Bedrock, without the WP plugin):

```bash
composer require saarnilauri/ai-provider-for-mistral
```

Waygate registers this provider manually since the library distribution excludes `plugin.php`. Set `MISTRAL_API_KEY` as a server environment variable.

---

## Usage

1. Go to **Tools → Waygate** in the WordPress admin
2. Browse registered patterns in the catalog; use the category dropdown to filter
3. Optionally type a page description and click **Generate Page** to create an AI-assembled draft
4. Open the draft in the block editor, adjust as needed, and publish

---

## Abilities API (WordPress 7.0+)

When WordPress 7.0's Abilities API is available, Waygate registers three abilities:

| Ability | Type | Description |
|---|---|---|
| `elayne/list-patterns` | Server | Returns patterns, optionally filtered by category |
| `elayne/create-page` | Server | Creates a draft page from an ordered list of pattern slugs |
| `waygate/insert-pattern` | Client (editor) | Inserts a pattern block at the current cursor position in the block editor |

The client-side ability is registered via `@wordpress/abilities` and is available whenever the block editor is open. Pass a `slug` parameter (e.g. `"elayne/hero-centered"`) to insert any registered pattern.

---

## REST API

Waygate exposes two REST endpoints under `/wp-json/waygate/v1/`:

| Method | Endpoint | Permission | Description |
|---|---|---|---|
| `GET` | `/patterns` | `edit_posts` | List all registered patterns; optional `?category=hero` filter |
| `POST` | `/pages` | `publish_pages` | Create a **draft** page from pattern slugs (max 10 req/min per user) |

**Example — list patterns filtered by category:**

```bash
curl -u admin:password https://example.com/wp-json/waygate/v1/patterns?category=hero
```

**Example — create a page:**

```bash
curl -u admin:password -X POST https://example.com/wp-json/waygate/v1/pages \
  -H "Content-Type: application/json" \
  -d '{"title":"My Page","patterns":["elayne/hero","elayne/features","elayne/cta"],"status":"draft"}'
```

Response:

```json
{ "page_id": 42, "edit_url": "https://example.com/wp-admin/post.php?post=42&action=edit", "view_url": "https://example.com/?page_id=42" }
```

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

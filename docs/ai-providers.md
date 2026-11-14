# AI providers

AI page generation needs a provider that supports text generation, connected through the
WordPress 7.0 AI Client. Without one, the generation form is hidden and the pattern catalog,
WP-CLI, abilities and REST endpoints keep working.

Waygate tries Mistral first, then Claude, OpenAI and Gemini.

## Installing a provider

Bedrock / Composer:

```bash
composer require wp-plugin/ai-provider-for-mistral
composer require wp-plugin/ai-provider-for-anthropic
```

OpenAI and Gemini can be installed from **Settings → Connectors**.

## API keys

Every provider reads its key from an environment variable first, then from the database:

| Provider | Environment variable | Admin |
|---|---|---|
| Mistral | `MISTRAL_API_KEY` | Settings → Connectors |
| Claude (Anthropic) | `ANTHROPIC_API_KEY` | Settings → Connectors |
| OpenAI | `OPENAI_API_KEY` | Settings → Connectors |
| Google (Gemini) | `GOOGLE_API_KEY` | Settings → Connectors |

On Bedrock, put the key in the site's `.env`. It is then never stored in the database, which is
the better choice for production. On a standard install, a key entered under Settings →
Connectors is stored in the database.

## Mistral as a Composer library

Without the WordPress plugin:

```bash
composer require saarnilauri/ai-provider-for-mistral
```

The library distribution has no `plugin.php`, so Waygate registers the provider itself. Set
`MISTRAL_API_KEY` as a server environment variable.

Tools → Waygate shows whether the AI Client, the Abilities API and Mistral are available.

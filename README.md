# Waygate

[![CI](https://github.com/imagewize/waygate/actions/workflows/ci.yml/badge.svg)](https://github.com/imagewize/waygate/actions/workflows/ci.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/imagewize/waygate.svg)](https://packagist.org/packages/imagewize/waygate)
[![Latest Stable Version](https://img.shields.io/packagist/v/imagewize/waygate.svg)](https://packagist.org/packages/imagewize/waygate)
[![License](https://img.shields.io/packagist/l/imagewize/waygate.svg)](https://packagist.org/packages/imagewize/waygate)

**Describe a page. Get a draft built from your theme's own patterns.**

## What it is

Waygate turns a sentence like *"a homepage for a nail salon with services, prices, reviews and a booking call to action"* into a draft page in the block editor. The AI only picks and orders block patterns your theme already ships, so the result looks like the rest of your site and is ordinary block markup you can edit like any other page.

- **Your design, not the AI's.** Pages are assembled from registered patterns. Nothing is drawn from scratch.
- **On-topic copy.** An optional second pass rewrites headings, paragraphs and button labels for your subject.
- **Stays in its lane.** Pick a vertical (legal, spa, salon …) so a law firm never gets a spa hero.
- **Any provider.** Mistral, Claude, OpenAI or Gemini, through the WordPress 7.0 AI Client. Keys can live in `.env`, not the database.
- **No AI needed.** `wp waygate build` assembles pages from a JSON map, for repeatable demo and staging sites.
- **Built for agents too.** Abilities API and REST endpoints let external tools list patterns and create pages.
- **Elayne and Aludra.** Made for the [Elayne](https://github.com/imagewize/elayne) theme; works with any block theme, and can mix in [Aludra](https://github.com/imagewize/aludra) sections.

## Getting started

1. Install with Composer, `composer require imagewize/waygate`, or upload the zip from [Releases](https://github.com/imagewize/waygate/releases).
2. Connect an AI provider under **Settings → Connectors** (see [AI providers](docs/ai-providers.md)).
3. Open **Tools → Waygate**, describe the page and click **Generate Page**. Review the draft and publish.

Requires WordPress 7.0+ and PHP 8.3+.

## Documentation

- [Generating pages](docs/generating-pages.md): the Tools → Waygate screen, templates, verticals, text rewriting and Aludra patterns
- [AI providers](docs/ai-providers.md): installing a provider and where to put its API key
- [WP-CLI](docs/wp-cli.md): build pages from a pattern map without AI
- [Developer API](docs/api.md): abilities, REST endpoints, filters and post meta
- [Changelog](CHANGELOG.md)
- [Contributing](CONTRIBUTING.md)

## License

GPL-2.0-or-later. See [LICENSE.md](LICENSE.md).

## Support

Issues and feature requests: https://github.com/imagewize/waygate/issues

Built by [Imagewize](https://github.com/imagewize).

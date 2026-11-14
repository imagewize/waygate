# Developer API

## Abilities (WordPress 7.0+)

| Ability | Runs | Permission | What it does |
|---|---|---|---|
| `waygate/list-patterns` | Server | `edit_posts` | Lists patterns; optional `category` filter, e.g. `hero` |
| `waygate/get-pattern` | Server | `edit_posts` | One pattern's markup plus the exact `texts` and `links` that `create-page` can replace (`slug`) |
| `waygate/create-page` | Server | `publish_pages` | Creates a page from an ordered list of patterns (`title`, `patterns`, `status`); returns `warnings` |
| `waygate/insert-pattern` | Block editor | — | Inserts a pattern (`slug`) at the cursor |

The server abilities are in the `waygate` category and exposed over REST at
`/wp-json/wp-abilities/v1/abilities/waygate/<name>/run`. Run them from PHP or WP-CLI with
`wp_get_ability( 'waygate/create-page' )->execute( $input )`.

### Replacing text and links

Each item in `patterns` is a slug, or a section with `texts` and `links`: lists of
`{find, replace}` pairs. Take the find strings verbatim from `waygate/get-pattern`.

```json
{
  "title": "Salon Rosa",
  "patterns": [
    {
      "pattern": "elayne/salon-hero",
      "texts": [{ "find": "Your beauty, our passion", "replace": "Colour and cuts in Utrecht" }],
      "links": [{ "find": "/contact", "replace": "/book" }]
    },
    "elayne/salon-services"
  ]
}
```

- A find matches a whole text run (the text between two tags) or a whole text attribute, never
  part of one, so the block still validates in the editor. A heading with inline emphasis has one
  text per run.
- Replacements are plain text or URLs. Text is escaped, URLs are sanitized; neither can add markup.
- Structural attributes (class names, layout, colours, IDs) are never replaced.
- A find that matches nothing is reported in `warnings` and the page is still created.
- Once any section has pairs, every pattern on the page is inlined instead of stored as a
  `wp:pattern` reference. A page made only of Aludra patterns goes to `aludra/compose-page`
  with the pairs passed on.

## REST endpoints

| Method | Endpoint | Permission | What it does |
|---|---|---|---|
| `GET` | `/wp-json/waygate/v1/patterns` | `edit_posts` | Lists patterns; optional `?category=hero` |
| `POST` | `/wp-json/waygate/v1/pages` | `publish_pages` | Creates a **draft** page from pattern slugs; 10 requests per minute per user |

```bash
curl -u "user:application password" -X POST https://example.com/wp-json/waygate/v1/pages \
  -H "Content-Type: application/json" \
  -d '{"title":"My Page","patterns":["elayne/hero","elayne/features","elayne/cta"]}'
```

```json
{ "page_id": 42, "edit_url": "https://example.com/wp-admin/post.php?post=42&action=edit", "view_url": "https://example.com/?page_id=42" }
```

## Filters

| Filter | Default | Use |
|---|---|---|
| `waygate_pattern_prefixes` | `['elayne/']`, plus `aludra/` when Aludra patterns are on | Pattern slug prefixes Waygate offers; add your theme's |
| `waygate_verticals` | `food-beverage`, `legal`, `plumbing`, `salon`, `spa` | Vertical slugs for the Vertical select |
| `waygate_structural_categories` | header, footer and page categories | Categories never offered as page sections |
| `waygate_prompt_templates` | six templates | Quick templates on Tools → Waygate |
| `waygate_rate_limit` | `10` | Page creations per user per minute over REST |

Using Waygate with another theme:

```php
add_filter( 'waygate_pattern_prefixes', fn( $prefixes ) => array_merge( $prefixes, array( 'mytheme/' ) ) );
```

## Post meta on generated pages

| Key | Value |
|---|---|
| `_waygate_reasoning` | The AI's one-sentence reason |
| `_waygate_patterns` | JSON list of the chosen slugs |
| `_waygate_generated_at` | Generation time |
| `_waygate_personalized` | `1` when the text was rewritten |

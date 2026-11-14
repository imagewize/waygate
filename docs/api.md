# Developer API

## Abilities (WordPress 7.0+)

| Ability | Runs | Permission | What it does |
|---|---|---|---|
| `waygate/list-patterns` | Server | `edit_posts` | Lists patterns; optional `category` filter, e.g. `hero` |
| `waygate/create-page` | Server | `publish_pages` | Creates a page from an ordered list of pattern slugs (`title`, `patterns`, `status`) |
| `waygate/insert-pattern` | Block editor | — | Inserts a pattern (`slug`) at the cursor |

The server abilities are in the `waygate` category and exposed over REST at
`/wp-json/wp-abilities/v1/abilities/waygate/<name>/run`. Run them from PHP or WP-CLI with
`wp_get_ability( 'waygate/create-page' )->execute( $input )`.

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

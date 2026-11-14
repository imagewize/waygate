# WP-CLI

Build pages from patterns without AI, for example to rebuild a demo site after a theme change.

## `wp waygate build <map.json>`

```bash
wp waygate build legal.json --dry-run
wp waygate build legal.json
```

The map lists pages and their patterns in order:

```json
{
  "pages": [
    { "id": 5, "patterns": ["elayne/legal-hero-form", "elayne/legal-departments", "elayne/legal-cta-strip"] },
    { "slug": "about", "title": "About", "patterns": ["elayne/page-legal-about"] }
  ]
}
```

- A page is matched by `id` or `slug`. A slug with no matching page creates a draft.
- `title`, `template` and `status` (`draft` or `publish`) are optional.
- Content is stored as a fresh block-editor insert would store it: each pattern is inlined and
  nested `wp:pattern` references are expanded. `--references` stores `wp:pattern` blocks instead.
- An unknown slug fails that page, and the command exits non-zero.

## `wp waygate list`

```bash
wp waygate list --vertical=legal --sections
wp waygate list --format=ids
```

`--sections` leaves out headers, footers, templates and whole-page patterns. `--format` accepts
`table`, `json`, `csv` and `ids`.

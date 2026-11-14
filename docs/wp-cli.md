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

A pattern can also carry `texts` and `links`, as `{find, replace}` pairs (see
[Replacing text and links](api.md#replacing-text-and-links)):

```json
{
  "pages": [
    {
      "slug": "home",
      "patterns": [
        {
          "pattern": "elayne/salon-hero",
          "texts": [{ "find": "Your beauty, our passion", "replace": "Colour and cuts in Utrecht" }],
          "links": [{ "find": "/contact", "replace": "/book" }]
        },
        "elayne/salon-services"
      ]
    }
  ]
}
```

A find that matches nothing prints a warning and the page is still built. `--references` fails a
page whose patterns carry pairs, since a reference cannot hold them.

## `wp waygate get <slug>`

```bash
wp waygate get elayne/legal-cta-strip
wp waygate get elayne/legal-cta-strip --stub > cta.json
wp waygate get elayne/legal-cta-strip --format=json --content
```

Shows a pattern's replaceable texts and links: the exact find strings for `texts` and `links`
pairs. `--stub` prints a build-map section with every text and link paired with itself; edit the
`replace` values and drop the pairs you keep. `--format=json` prints the pattern's data, with its
block markup when `--content` is given.

## `wp waygate list`

```bash
wp waygate list --vertical=legal --sections
wp waygate list --format=ids
```

`--sections` leaves out headers, footers, templates and whole-page patterns. `--format` accepts
`table`, `json`, `csv` and `ids`.

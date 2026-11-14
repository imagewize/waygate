# Generating pages

Everything happens on **Tools → Waygate** (requires `manage_options`; creating pages requires
`publish_pages`).

## Steps

1. Optionally pick a **Quick template** (Homepage, About, Services, Contact, Landing Page,
   Portfolio). It fills in the description; replace any `[placeholder]` text with your specifics.
2. Write the **Page description**: industry, page type and the sections you want.
3. Pick a **Vertical**, or leave **All patterns**.
4. Leave **Rewrite pattern text to match my description** on for on-topic copy, or turn it off for
   a faster, single-call result that keeps the patterns' sample text.
5. Click **Generate Page**. Waygate creates a draft and links to it.
6. Open the draft in the block editor, adjust and publish.

Below the form, the **pattern catalog** lists every pattern Waygate can use, filterable by
category.

## How the AI chooses

Waygate sends the AI the slug, title, categories, description and keywords of each page-section
pattern. Headers, footers, templates and whole-page patterns are never offered. The AI returns an
ordered list of 3–7 slugs (hero first, call to action last, no two grids in a row) and a
one-sentence reason. Slugs that were not offered are dropped.

## Verticals

A vertical limits the AI to patterns in that vertical's category (e.g. `elayne/legal`). It
defaults to the active child theme's vertical: a child theme named `elayne-legal` selects `legal`.
If a vertical has fewer than three page sections, Waygate falls back to all patterns.

Built-in verticals: `food-beverage`, `legal`, `plumbing`, `salon`, `spa`. Add your own with the
`waygate_verticals` filter.

## Text rewriting

With rewriting on, a second AI call rewrites the headings, paragraphs and button labels inside the
chosen patterns to fit your description. The page then stores the rewritten markup instead of
pattern references. Rewritten markup comes from the AI, so open the draft in the editor and check
it before publishing.

## After generation

The AI's reasoning is shown after generation and kept on the page; the **Waygate** box in the page
editor sidebar shows it. With `WP_ENV=development`, the box and the notice also show the ordered
pattern slugs and the generation time.

## Aludra patterns

With the [Aludra](https://github.com/imagewize/aludra) block library active (a release that
registers its `aludra/compose-page` ability), a **Use Aludra patterns** setting appears on
Tools → Waygate (`waygate_use_aludra` option). When it is on:

- Aludra's section and carousel patterns are offered alongside the theme's. Its whole-page,
  header, footer and mega-menu patterns are not.
- A page made **only** of Aludra patterns is built by `aludra/compose-page`, which inlines the
  pattern markup and refuses a pattern that uses a block disabled under Settings → Aludra. Text
  rewriting is skipped for these pages, because rewritten markup would bypass that.
- A page that mixes theme and Aludra patterns is built as before, from `wp:pattern` references.

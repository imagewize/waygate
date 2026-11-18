# Generating pages

Everything happens on **Tools → Waygate** (requires `manage_options`; creating pages requires
`publish_pages`).

## Steps

1. Optionally pick a **Quick template** (Homepage, About, Services, Contact, Landing Page,
   Portfolio). It fills in the description; replace any `[placeholder]` text with your specifics.
2. Write the **Page description**: industry, page type and the sections you want.
3. Pick a **Vertical**, or leave **All patterns**.
4. Pick a **Page template**, for example a no-title template for a full-width homepage.
5. Leave **Rewrite pattern text to match my description** on for on-topic copy, or turn it off for
   a faster result that keeps the patterns' sample text.
6. Optionally choose **Images**: click **Choose images from the Media Library** and pick or
   upload pictures for your page. The AI places them in the patterns' image slots.
7. Click **Generate Page**. The form runs the generation as a series of short requests — one to
   choose the patterns, one per section to rewrite its text, plus one per section to place your
   images — and shows each step's progress. A failed step shows a **Retry this step** button and
   picks up where it stopped. Without JavaScript the form falls back to a single request for the
   whole flow (your images are not placed in that fallback).
8. Open the draft in the block editor, adjust and publish.

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

With rewriting on, a second AI call rewrites the headings, paragraphs, button labels and form
labels of the chosen patterns to fit your description. The AI never sees or writes markup: it
gets each pattern's texts as numbered lines and answers with new text per line number. Waygate
turns that into the same find/replace pairs `waygate/create-page` takes (see
[Replacing text, links and images](api.md#replacing-text-links-and-images)), so the page keeps the patterns'
markup and stays valid in the editor.

- Texts without a letter, such as `3,600+` or `20+`, are not offered, so the AI cannot invent
  statistics. Neither are people's names and initials ("Sarah J.", "SJ"), so testimonials keep
  their placeholder reviewers. Change them in the editor.
- A new text that adds, drops or changes a phone number or email address is rejected, so the
  pattern's placeholder contact details stay until you replace them.
- Answers for line numbers that don't exist, repeated lines and unchanged texts are dropped.
- The text is written in the language of your description, even when it names a place where
  another language is spoken.
- The rewrite asks fast models first (`mistral-small-latest`, `claude-haiku-4-5`,
  `gemini-2.0-flash`): a large model can take minutes for a whole page. In the admin form each
  rewrite is its own server request, waiting up to 45 seconds per section
  (`waygate_ai_step_timeout` filter, capped by `waygate_ai_request_timeout`), so no single request
  runs long enough to hit the web server's upstream timeout (nginx:
  `fastcgi_read_timeout`, 60 seconds by default). The single-run `waygate` CLI and REST flows wait
  up to 120 seconds per AI call (`waygate_ai_request_timeout` filter).
- A pattern with nothing to rewrite (statistics-only sections, icon grids) is kept as is and the
  generation continues. If a rewrite step fails outright, the form shows **Retry this step** and
  picks up where it stopped; in the single-run flows the page is still created with the patterns'
  text and the notice says why.
- Links keep the patterns' values.
- The pairs are stored as inlined pattern content, not `wp:pattern` references. Read the draft
  before publishing: the copy is the AI's.

## Image selection

Pick or upload pictures with the **Images** row, and the AI decides which pattern each picture
belongs in and which slot it fills. Slots are a pattern's photos: `core/image` blocks and
background images (`core/cover`). Decorative graphics — icons, stars, arrows, SVGs and
anything under 100px — are never slots, so they stay untouched.

- Each picture fills at most one slot per section; the AI sees each slot's alt text and each
  picture's filename and alt text.
- A swapped image uses the attachment's large size URL, and the attachment's alt text replaces
  the pattern's, so screens readers and the editor both see the new picture.
- Slots nothing fits, or a page with more slots than pictures, keep the pattern's original
  image; the build reports a warning when that happens.
- A pattern with no image slots is kept as is; the image step for it makes no AI call.
- Pages composed by Aludra's `aludra/compose-page` skip image pairs (with a warning) until
  Aludra supports them; edit those in the block editor instead.

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
  rewriting works for these pages too: the pairs are passed on to `aludra/compose-page`.
- A page that mixes theme and Aludra patterns is built as before, from `wp:pattern` references.

## Troubleshooting

**Blank page / `ERR_INCOMPLETE_CHUNKED_ENCODING` during generation** — the web server cut the
request while PHP was still waiting on the AI. A single long request hits upstream read timeouts:
nginx's default `fastcgi_read_timeout` is 60 seconds, while a selection plus rewrite can run for
minutes. With JavaScript enabled the admin form already avoids this by sending each step as its
own short request. If you still see it (the no-JS fallback, or a step timeout raised past the
server's), raise your server's timeout — nginx:

```nginx
# In the fastcgi location block.
fastcgi_read_timeout 300;
```

and keep `waygate_ai_request_timeout` (single-run flows) below it. The per-step
`waygate_ai_step_timeout` (admin form, default 45 seconds) should stay below it too.

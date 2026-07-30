# Presenter 1.x compatibility contract

This document identifies behavior that must be characterized before Presenter
2.0 replaces it. A behavior may later change deliberately, but it must not
change accidentally.

## Identity and routing

- Preserve the `slideshow` post type, existing post IDs, slugs, permalinks, and
  `/slideshows/` archive.
- Preserve title, editor, excerpt, page attributes, custom fields, and revisions
  unless a documented 2.0 decision supersedes a support.
- Use Presenter presentation output for an eligible singular slideshow.
- Preserve the normal WordPress password form and cookie flow. Do not expose the
  presentation document before the password is satisfied.
- Keep archives in the active WordPress theme.
- Preserve the companion plugin's exclusion of protected decks from the public
  archive for anonymous visitors.
- Register `slideshow` once on `init`; the modern post-type provider is the
  single source of truth for labels, REST visibility, supports, and capability
  mapping.

## Legacy slide data

`_presenter_slides` is repeated post metadata containing serialized slide
objects. Preserve, before native conversion:

- numeric slide order;
- raw content HTML;
- title and its historical anchor behavior;
- class names;
- arbitrary Reveal.js `data-*` names and values;
- notes content and the Markdown flag;
- older records that lack notes or data fields;
- nested `<section>` markup, even though new vertical-stack authoring is not in
  the Presenter 2.0 scope.

Legacy title-derived IDs can collide. Characterize the output, but do not carry
that limitation into new Slide anchors.

Presenter 2.0 deliberately changes the legacy execution boundary: raw metadata
remains available for conversion and restore, but previously stored HTML is
filtered at render time until an `unfiltered_html` user saves that exact slide
set and establishes a content-bound, site-keyed trust fingerprint. Trust never
comes from the post author, deck age, or the mere presence of legacy metadata.

## Presentation metadata

- `_presenter-theme` stores a content-relative stylesheet path.
- `_presenter-short-url` stores an optional URL.
- `[presenter-url]` returns the short URL when present and otherwise the
  slideshow permalink.
- A non-empty short URL appears in the presentation chrome.
- Theme migration resolves historical `aaron-purple` locations without losing
  the stored selection.

## Public hooks and handles

Actions:

- `presenter-head`
- `presenter-footer`
- `presenter-reveal-footer`

Filters:

- `presenter-init-object`
- `presenter-reveal-js-dependencies`
- `presenter-reveal-css-dependencies`
- `presenter-theme`
- `presenter-theme-directories`
- `presenter-themes`
- `presenter-default-theme`

Other public integration points:

- shortcode `presenter-url`;
- script/style handles `reveal`, `reveal-theme`, and `presenter`;
- historical Reveal plugin handles;
- public `get_themes()` and `get_default_theme()` methods until compatibility
  usage is understood.

Presenter 1.x's `presenter-themes` behavior does not reliably add new themes;
directory registration is the dependable extension path. Tests should capture
the behavior before the 2.0 registry and compatibility adapter clarify it.

Public presentation rendering treats these extension hooks as untrusted
integration seams. Strict configuration and registry methods still reject
invalid values for migration and diagnostics, while the public renderer reports
and discards malformed filter results rather than white-screening a deck. A
broken legacy settings filter first falls back to the valid native deck
settings; invalid modern settings or plugin IDs fall back to Presenter defaults.
Invalid theme filters fall back without filters to the selected registered theme
or bundled Black.

## Reveal.js baseline

- The 1.5.2 submodule commit is Reveal.js 4.3.1.
- PHP asset registrations contain older `4.1.2` version strings. Treat those as
  a cache-version bug, not the actual runtime baseline.
- Default configuration enables controls, progress, history, and centering.
- The characterized 1.x baseline includes Markdown, Search, Notes, Math, and
  Zoom. Presenter 2.0 deliberately omits the historical `RevealMath` integration
  because its default renderer downloads executable MathJax from a CDN. Sites
  that need formula rendering can supply a locally hosted Reveal plugin through
  the retained dependency and native plugin extension seams.
- Highlight is used when SyntaxHighlighter is absent; SyntaxHighlighter uses a
  Presenter CSS bridge when active.
- Dependency filters determine the plugin objects passed to Reveal.
- The retained legacy template now uses current WordPress document-title APIs,
  owns one zoom-capable responsive viewport, and resolves its split template
  files from `__DIR__`. The native template owns the same document metadata.
  Both continue to fire the standard WordPress head, body-open, and footer hooks,
  so regression tests distinguish intended asset support from unrelated theme
  leakage.

Do not use the separate workspace-level Reveal.js 6 checkout for Presenter 1.x
baseline captures.

## Aaron theme companion

Characterize and preserve or explicitly replace:

- the additional theme directory;
- `aaron-purple` as the default;
- historical theme URL rewriting;
- no transition and no background transition defaults;
- Chart.js Reveal plugin registration and Math removal;
- persistent social footer output;
- custom CSS helpers, fragment behavior, columns, galleries, fonts, and images;
- chart dataset changes driven by fragment data attributes.

## Migration invariants

For every migrated deck, compare:

- status, visibility, password, slug, and permalink;
- slide count and order;
- title, original anchor, content hash, classes, and data attributes;
- plain and Markdown notes;
- theme and short URL;
- referenced asset availability;
- front-end DOM structure and approved visual snapshots.

Keep the legacy source until native conversion is verified and restorable.

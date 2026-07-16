# ADR 0002: Deck and Slide block content model

Status: Accepted  
Date: 2026-07-15

## Context

Reveal.js requires a predictable deck and slide hierarchy. A post containing
independent top-level Slide blocks can also contain accidental non-slide blocks,
which produces invalid presentation structure. Presenter must retain the native
WordPress post editor and allow applicable native and third-party blocks inside
slides.

## Decision

- Register both blocks from Block API v3 metadata.
- Load Deck and Slide editor code through one shared, dependency-generated
  editor bundle.
- A slideshow post contains one locked `presenter/deck` block.
- The Deck allows only `presenter/slide` as direct children.
- The Deck is an editor and data-model boundary, not the Reveal shell. Its
  front-end render output passes its Slide children through without an
  additional wrapper.
- A Slide leaves its inner-block area unrestricted so WordPress determines
  which registered blocks are valid in the post-content context.
- Presentation-level settings live on the Deck when they belong to revisioned
  presentation content. The initial contract includes aspect-ratio preset,
  logical width and height, margin, controls, progress, hash navigation,
  centering, keyboard input, deck transition, background transition, and theme
  ID.
- Slide-level Reveal.js settings and speaker notes live on each Slide. The
  initial contract includes display label, stable anchor, hidden state,
  transition override, background color, background image URL, notes, and notes
  format.
- The first authoring slice supports a stable URL-safe anchor, hidden state,
  and speaker notes stored as plain text with either plain-text or Markdown
  interpretation. Presenter escapes note text in both modes; Markdown remains
  inert until Reveal's Markdown plugin processes the notes container.
- New decks default to 1280 by 720 logical pixels. Migrated decks preserve
  their historical dimensions.
- Presentation routing accepts exactly one non-empty top-level Deck whose
  direct children are all Slides. Empty Decks, extra roots, nested Decks, and
  non-Slide direct children remain on the active theme route instead of
  emitting malformed Reveal markup.
- Vertical slide stacks are excluded from 2.0 and planned as a later explicit
  content type.

## Consequences

The saved block tree always maps to valid Reveal.js structure while preserving
native block editing, revisions, autosave, transforms, patterns, and List View.
The presentation template and renderer provide `.reveal > .slides`; each Slide
renders one `<section>` as a direct child of `.slides`. A Deck wrapper on the
front end would violate that Reveal.js hierarchy and is therefore prohibited.
The Deck returns WordPress's already-rendered child content, so dynamic child
blocks execute exactly once. A non-empty Slide label becomes an escaped
`aria-label` on that section. The native template relies on WordPress for its
single viewport declaration and provides a skip link whose `tabindex="-1"`
target can receive programmatic focus.
Adding vertical stacks later requires a separate `presenter/stack` design rather
than silently changing Slide semantics.

The registered blocks and their save/reload path are now executable as the
first Milestone 4 vertical slice. Deck settings accept the canonical 1280×720
16:9 and 960×720 4:3 presets or positive-integer custom dimensions. Reveal
margin must be at least zero and less than one. Slide background colors use
strict `#RRGGBB` syntax, background images accept only absolute HTTP(S) URLs,
and render output includes only explicitly allow-listed Reveal attributes.
Invalid values are not passed to Reveal or emitted as Slide data attributes.

Settings are covered by JavaScript normalization tests, PHP rendering and
revision-restoration integration tests, and headless authoring/presentation
checks. The editor save/reload corpus covers Heading, Paragraph, Group, Columns,
List, Code, Image, Buttons, Accordion, Shortcode, and Latest Posts. A published
fixture additionally verifies WordPress 7 Accordion interactivity and dynamic
Latest Posts output without breaking Reveal navigation, while a synthetic PHP
fixture proves a dynamic render callback executes exactly once. Editor theme
preview and the Milestone 5 navigator remain pending.
The classic Presenter meta boxes, editor script, editor stylesheet, and legacy
WYSIWYG initialization remain available only when the edited post has stored
`_presenter_slides` metadata; native and new slideshow editors do not load them.

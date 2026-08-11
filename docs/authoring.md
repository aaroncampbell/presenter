# Authoring presentations

Presenter 2.0 uses the standard WordPress post editor. A slideshow contains one
locked Presenter Deck at the document root, and the Deck contains Presenter
Slides or one level of Nested Slides. Each Slide can contain applicable
WordPress blocks.

## Create a slideshow

1. Open **Slideshows → Add New**.
2. Give the slideshow a WordPress post title.
3. Select the Deck and choose its presentation settings in the block inspector.
4. Add content to the starter Slide.
5. Add more Slides with the Slides navigator.
6. Preview and publish through the standard WordPress workflow.

New decks default to 1280×720 (16:9). The Deck's root structure is protected,
but normal block editing, undo, revisions, autosave, patterns, List View, and
post locking remain available inside Slides.

## Deck settings

The Deck inspector controls:

-   aspect ratio: 16:9, 4:3, or custom width and height;
-   margin around slide content;
-   selected presentation theme;
-   slide and background transitions;
-   visible controls and progress;
-   URL hash updates;
-   vertical centering; and
-   keyboard navigation.

Presenter always retains at least one of visible controls or keyboard navigation.
Changing a migrated deck's aspect ratio changes its authored layout; it is never
performed automatically.

## Slides and the navigator

The Presenter **Slides** rail opens beside the editor by default and remains
available when WordPress switches between Slideshow and Block settings. Use its
vertical **Slides** tab to collapse or reopen it. The rail follows the selected
Slide, while its sticky header keeps **Add Slide** available as the Slide list
scrolls.

Use the primary **Add Slide** action to add after the selected Slide at its
current level. Its menu also offers **Add before** and, for a top-level Slide,
**Add nested**. Add nested preserves the selected Slide and its anchor, wraps it
in a Nested Slides group, and adds a new child. It is absent for an already
nested Slide because Presenter and Reveal support exactly one nested level.

The navigator numbers horizontal Slides `1`, `2`, `3`. The first Slide in a
nested set keeps the horizontal position (`2`), while its downward continuation
Slides use `2.1`, `2.2`, and so on. The first Slide looks like any other Slide;
continuations are slightly smaller, indented, and marked with a left border.
Nested sets can still be duplicated and deleted with confirmation.
Drag-and-drop, Up/Down controls, **Move to top level**, and the adjacent **Move
into Nested Slides** commands all update the same block tree, and each operation
participates in WordPress undo. Moving or deleting a child that would leave a
one-Slide group automatically unwraps the remaining Slide.

Slide labels help identify slides in the navigator and provide accessible names
in the presentation. Stable anchors provide Reveal hash targets. Hidden Slides
remain editable but are excluded from the public Reveal slide sequence.

Select a Nested Slides block in List View to edit its label or group anchor.
The label is for editor navigation; the stable anchor can target the group in a
Reveal URL. Nested Slides cannot contain another Nested Slides group.

## Slide settings

Each Slide can override its transition and configure:

-   background color;
-   HTTP or HTTPS background image;
-   image size, position, and repeat;
-   background opacity and transition;
-   auto-animate, auto-animate ID, and restart behavior; and
-   allow-listed advanced Reveal data attributes.

Invalid colors and image URLs are not silently accepted. Advanced attributes
are for Reveal features that do not yet have a typed control, such as background
video or iframe behavior.

Hover the top or bottom edge of a Slide to insert a block before its first or
after its last content block. These boundary controls open WordPress's standard
block inserter, matching the inserter available between existing content blocks.

## Fragments

Select a block inside a Slide and use its fragment controls to reveal it in a
specific order or with a Reveal fragment effect. Fragment settings apply only
inside Presenter Slides. Use explicit fragment indices only when the sequence
cannot be represented by document order.

## Speaker notes

Slide notes support plain text, Markdown, limited HTML, and Markdown with limited
HTML. Unsupported HTML is removed when rendered. Migrated notes may retain
internal compatibility behavior so their historical output stays exact. Open
Reveal speaker view from the presentation to verify notes before presenting.
Speaker notes are included in the delivered presentation markup and are not
secret; never put credentials or other confidential information in them.

## Charts

The Presenter Chart block supports line and bar charts, tabular columns and
rows, dimensions, a caption, and a bounded options object. The visual canvas is
paired with one semantic data table for assistive technology and print output.
Use a meaningful caption whenever the chart's purpose is not obvious from its
surrounding heading and text.

## Migrated HTML

Lossless migration may initially retain a Slide as Custom HTML. Its preview is
rendered in a script-disabled isolated document. Use **Convert to blocks** on one
Slide, or the Deck's **Convert legacy slides to blocks** action, to convert
supported markup. Unsupported markup remains in the smallest safe Custom HTML
island; conversion does not execute legacy scripts.

When one retained Slide contains a complete canonical Reveal section stack,
the toolbar offers **Convert to Nested Slides**. Presenter converts the outer
hierarchy and each exactly representable child wrapper as one structural edit.
Ambiguous outer behavior, unsupported child attributes, or deeper nesting keep
the complete source as Custom HTML for review.

Review the result with WordPress undo available before saving. Background-only
slides may correctly contain no inner content blocks.

## Presenting and exporting

-   Arrow keys and configured controls navigate the deck.
-   The URL hash can provide stable links to anchored Slides.
-   Reveal speaker view displays notes in a connected window.
-   Browser print/PDF mode lays out one Slide per printable page.
-   Presenter follows the operating system's reduced-motion preference and the
    WordPress site's left-to-right or right-to-left text direction.

Always test the public presentation in a signed-out browser, especially when it
contains dynamic blocks, password protection, remote media, or companion themes.

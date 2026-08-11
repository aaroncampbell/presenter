# Native nested slides plan

Status: Approved product direction for Presenter 2.0 polish
Prepared: 2026-08-07
Decision owner: Aaron D. Campbell
Implementation status: Block model, editor, hierarchical navigator with
cross-level drag/drop, runtime, and strict conversion implemented locally;
production-corpus rehearsal and release hardening remain

## Decision summary

Bring native Reveal.js nested slides into Presenter 2.0 before the public
release candidate is frozen. Reveal.js describes the underlying structure as a
vertical stack, but Presenter should use the more approachable **Nested
Slides** language in the editor. Presenter already renders retained nested
`<section>` markup correctly, but authors cannot manage that hierarchy as
native WordPress blocks. The production-derived conversion inventory contains
187 stack-related Custom HTML fallbacks, and Reveal users report that nested
navigation is a regular authoring pattern rather than an edge case.

The recommended content model is:

```text
Deck
├── Slide
├── Nested Slides container
│   ├── Slide
│   ├── Slide
│   └── Slide
└── Slide
```

The approved authoring control is a single **Add Slide** split button:

-   The primary **Add Slide** action defaults to **Add after**, creating a sibling
    immediately after the selected Slide at the same nesting level.
-   The dropdown also offers **Add before**, creating a sibling immediately before
    the selected Slide at the same nesting level.
-   **Add nested** continues the current topic. On a top-level Slide it wraps the
    selected Slide in the required container and adds a second child. The option
    is absent when a nested Slide is selected because Reveal does not support
    another level.
-   Top-level positions use whole numbers (`3`); nested Slides use dotted
    positions (`3.1`, `3.2`, `3.3`).

The product decisions below were approved before implementation began. This
document now records both the accepted contract and the remaining release
gates.

## Why this belongs in 2.0

-   Nested Slides are a first-class Reveal.js navigation primitive.
-   Existing decks already rely on them, so excluding nested Slides leaves a material
    part of Reveal authoring trapped in Custom HTML.
-   The current Slide navigator is the natural supported place to expose the
    hierarchy without depending on private WordPress editor APIs.
-   Adding the structural block before public release avoids introducing a second
    native storage generation immediately after 2.0.
-   A native Nested Slides container makes speaker view, print/PDF, anchors,
    fragments, thumbnails,
    and migration testable as one supported contract.

## Product goals

1. Authors can create, understand, navigate, reorder, duplicate, hide, and
   remove nested Slides without editing HTML.
2. Flat decks remain visually and structurally unchanged.
3. Saved blocks always map to valid Reveal hierarchy.
4. Existing horizontal slide anchors continue working when a slide becomes the
   first child of a Nested Slides group.
5. Characterized retained stacks can be converted explicitly and reversibly.
6. Keyboard, screen-reader, touch, print/PDF, speaker view, and URL navigation
   receive the same coverage as horizontal slides.

## Non-goals

-   Nested groups inside other nested groups or arbitrary-depth slide trees.
-   Freeform canvas positioning.
-   A private or custom permanent left editor rail.
-   Automatic production-content conversion during plugin update.
-   Generic conversion of malformed or behaviorally ambiguous nested HTML.
-   Redesigning Reveal's established keyboard behavior.

## Verified Reveal.js nesting limit

Presenter supports exactly the hierarchy Reveal.js documents: top-level Slides
may be replaced by one container whose direct child `<section>` elements are
nested Slides. Reveal.js does not define a third navigation axis or a supported
group-inside-group structure. The official
[vertical Slides documentation](https://revealjs.com/vertical-slides/) shows
only `.slides > section > section`, and Reveal.js 6.0.1's
[`VERTICAL_SLIDES_SELECTOR`](https://github.com/hakimel/reveal.js/blob/6.0.1/js/utils/constants.ts#L3)
selects only direct child Slides at that depth.

A focused Reveal.js 6.0.1 runtime probe confirmed the failure mode. With a
third-level `<section>`, Reveal reported that element as the current Slide while
leaving it decorated as `future`; a different second-level sibling was
decorated as `present`, indices disagreed, and further downward navigation was
unavailable. Presenter must therefore reject or retain—not author or normalize—
deeper section trees.

The allowed authoring tree is consequently fixed at:

```text
Deck
├── Slide
├── Nested Slides container
│   ├── Slide
│   └── Slide
└── Slide
```

**Add nested** is available only while a top-level Slide is selected. It is not
rendered in the placement menu for a nested Slide, preventing the UI from
implying that another nesting level is possible.

## Proposed block contract

### `presenter/stack`

-   Uses **Nested Slides** as its editor-facing name; `stack` remains the internal
    block identifier and the Reveal.js implementation term.
-   May appear only as a direct child of `presenter/deck`.
-   May contain only `presenter/slide` children.
-   May not contain another Stack or arbitrary content directly.
-   Saves one outer `<section>`; each child Slide saves its existing inner
    `<section>`.
-   Has a stable optional group anchor and a human-readable label for editor and
    assistive navigation.
-   Carries only attributes proven to apply to Reveal's outer stack container.
    Attribute inventory and runtime characterization precede schema finalization.
-   Accepts a singleton child defensively, but editor actions automatically unwrap
    a Stack when removing or moving a child would leave only one Slide.
-   Never renders an empty Stack.

### `presenter/slide`

-   Becomes valid under either Deck or a Nested Slides container.
-   Keeps the current content, notes, backgrounds, fragments, transition,
    auto-animate, hidden state, label, and stable anchor contract.
-   Receives container context so editor controls and numbering can distinguish
    top-level and nested positions.

### Deck validation

-   Deck direct children may be Slide or Nested Slides container.
-   A Nested Slides container must contain only Slides.
-   The server validates the complete tree before routing to the presentation
    document; invalid structures fail safely to the site theme instead of
    emitting malformed Reveal markup.
-   Theme resolution, password protection, and presentation settings continue to
    use the single validated Deck root.

## Recommended editor experience

### Keep Slides persistently discoverable

Use a Presenter-owned, collapsible Slides rail beside the editor. It opens by
default, coexists with WordPress's Slideshow or Block settings, and leaves a
labelled vertical tab visible when collapsed. The rail measures the current
editor shell and Settings panel instead of assuming a fixed sidebar width. The
navigator becomes a two-level tree rather than a flat ordered list:

-   The first Slide in a nested set is presented as the ordinary horizontal
    Slide and keeps its whole-number position.
-   Continuation rows are indented, carry a left border, and are numbered
    `x.1`, `x.2`, and so on.
-   Selection follows descendants of a Slide just as it does today.
-   Selection also keeps the active Slide visible in both the rail and canvas.
-   Hidden state remains attached to each real Slide.
-   The selected item retains a visible thumbnail and actions.
-   The rail header and Add Slide split button remain sticky while the list
    scrolls.

### Use order-and-hierarchy creation actions

Expose one **Add Slide** split button in the navigator header and selected-Slide
toolbar. Its main button performs the common default action; its disclosure
opens the three placement choices:

-   **Add after** is the default. It inserts a sibling immediately after the
    selected Slide under the same parent: Deck for a top-level Slide, or the
    current Nested Slides group for a nested Slide.
-   **Add before** inserts a sibling immediately before the selected Slide under
    that same parent.
-   **Add nested**
    -   From a top-level Slide: replace that position with a Nested Slides
        container holding the selected Slide followed by a new Slide, as one
        undoable operation.
    -   From a nested Slide: do not show this menu option.

When nothing is selected, Add after appends to the Deck. Add before and Add
nested remain unavailable until a Slide is selected, because inventing their
relative position or implicit parent would be ambiguous. The menu repeats Add
after and identifies it as the default so the primary button's behavior remains
discoverable. The menu is derived from selection context rather than showing an
invalid operation disabled.

The existing Slide anchor remains on the original Slide when it is wrapped in a
new Nested Slides container. The container receives a new group anchor.
Existing direct links to the Slide therefore continue to resolve. Here,
**wrap** specifically means placing the selected Slide inside the required
container; it does not add visual wrapper markup inside the Slide itself.

### Make hierarchy visible in the canvas

-   Render the first Slide without group chrome so it matches every other
    horizontal Slide.
-   Indent only the continuation Slides, reduce them slightly to preserve the
    common right edge, and mark them with a left border.
-   Keep every child as the same proportional Slide canvas used by flat decks.
-   Show the document-order children together in the editor; do not simulate
    Reveal transforms or hide sibling content during editing.
-   Provide **Move to top level** for a selected child and **Nested Slides
    settings** for the group.
-   Keep WordPress List View useful by exposing the real Deck → Nested Slides → Slide
    block tree.

### Reordering and restructuring

The explicit, accessible commands and cross-level drag targets share the same
proven data transforms and undo behavior:

-   Drag and keyboard moves reorder within the current parent.
-   **Move to top level** places a child immediately after its Nested Slides
    group.
-   **Move into group before** and **Move into group after** are available from
    the More menu when valid.
-   Removing the penultimate child automatically unwraps the remaining child.
-   Cross-level drag/drop uses card-sized insertion placeholders for reordering
    and an indented preview when the right edge of a top-level Slide is targeted
    for nesting.
-   The rail autoscrolls when a dragged Slide reaches its top or bottom edge.

### Duplication and deletion

-   Duplicating a Slide duplicates only that Slide with a new anchor.
-   Duplicating a Nested Slides group clones its complete subtree with new group
    and Slide anchors.
-   Deleting a Nested Slides group requires confirmation when it contains
    authored Slides.
-   Deleting an individual child uses the current non-destructive WordPress undo
    path and unwraps a resulting singleton.

## Accessibility contract

-   The navigator uses tree semantics with one focusable selection control per
    Slide.
-   Accessible names include position and title: `Slide 3.2: Architecture`.
-   The first Slide in a nested set uses the horizontal position (`3`); its
    continuations use dotted positions (`3.1`, `3.2`).
-   Selection and actions remain separate controls.
-   Arrow-key behavior follows the chosen WordPress/component tree pattern and
    does not conflict with editor shortcuts.
-   All operations are available without drag/drop.
-   Announcements describe structural changes, for example `Slide 3 became a
Nested Slides group with 2 slides`.

## Reveal runtime contract

Native output must use canonical Reveal nesting:

```html
<div class="reveal">
	<div class="slides">
		<section id="topic-before">...</section>
		<section id="case-study">
			<section id="overview">...</section>
			<section id="architecture">...</section>
			<section id="results">...</section>
		</section>
		<section id="questions">...</section>
	</div>
</div>
```

The contract must cover:

-   horizontal and vertical keyboard/touch navigation;
-   `#/h/v`, child-anchor, and historical-anchor URLs;
-   progress and slide-number calculations;
-   controls and available-direction states;
-   fragments and auto-animate within vertical children;
-   notes and connected speaker view;
-   print/PDF order and page count;
-   overview mode, search, zoom, and hidden Slides;
-   transition/background behavior on outer Stack versus child Slide;
-   deterministic IDs when old nested sections have no anchors.

## Existing-content conversion

### Characterize before converting

Re-run the production-derived inventory and classify all stack fallbacks by:

-   outer and child section attributes;
-   child count and anchor presence;
-   notes, Markdown, fragments, backgrounds, and auto-animate;
-   nested wrappers or malformed structures;
-   whether the current outer Presenter Slide carries behavior that belongs on
    Stack, first child, or every child.

The converter claims only complete structures whose behavior is understood.
Unknown or ambiguous structures remain exact Custom HTML with a review reason.

### Explicit reversible transform

-   Add a dedicated `Convert to Nested Slides` action for one retained
    stack and a reviewed deck-wide action when every target is eligible.
-   Replace the outer Slide/Custom HTML pair with one Stack and native child
    Slides in a single editor history operation.
-   Create and identify a WordPress revision before a persisted bulk conversion.
-   Preserve the retained migration source and immutable Presenter backup.
-   Never convert production content automatically during plugin activation or
    update.
-   Provide an editor preview/report showing the number of Nested Slides groups,
    child Slides,
    generated anchors, and retained exceptions without exposing authored HTML in
    logs.

## Implementation phases

### Phase 0 — Contract and UI approval

-   Record the approved terminology, hierarchy, numbering, and Add Slide split
    button model.
-   Characterize outer/inner Reveal attributes across the 187 retained stack
    fallbacks.
-   Record the accepted storage/rendering decision in a new architecture record
    that supersedes ADR 0002 only for nested Slides.
-   Freeze representative flat, native Nested Slides, and retained-stack
    fixtures.

Exit: UI and data contracts are approved; no unresolved attribute ownership or
URL-compatibility question remains.

### Phase 1 — Block model and rendering

-   Register Stack metadata and context.
-   Extend Deck/Slide parent and allowed-block contracts.
-   Render and validate canonical nested sections.
-   Add server-side structure, configuration, theme, and routing tests.

Exit: hand-authored block markup renders valid Reveal stacks while flat decks
remain byte- and pixel-stable.

### Phase 2 — Canvas authoring

-   Add the Nested Slides editor structure, clean parent/continuation hierarchy,
    block appender, and settings.
-   Implement Add nested wrapping and singleton unwrapping as one-step undoable
    transforms.
-   Cover save/reload, copy/paste, revisions, undo/redo, and invalid structures.

Exit: authors can create and edit Nested Slides without the custom navigator.

### Phase 3 — Hierarchical navigator

-   Change navigator selectors from a flat Slide list to a two-level model.
-   Add parent/continuation numbering, thumbnails, selection, actions, and
    same-parent reordering.
-   Add explicit Move to top level/Move into group operations, prove their undo
    behavior, and then add cross-level drag/drop using the same transforms.
-   Exercise a large mixed deck for performance and keyboard accessibility.

Exit: every structural operation is available with pointer and keyboard and is
reversible by WordPress undo; cross-level drag/drop is release-ready or its
shipping gate has been explicitly waived.

### Phase 4 — Retained-stack conversion

-   Implement strict complete-stack recognition and conversion.
-   Add single-stack and reviewed deck-wide actions.
-   Rehearse conversion and exact restore on the production-derived corpus.
-   Document any intentionally retained exceptions.

Exit: every characterized eligible fallback becomes native; every remaining
fallback has a stable reason code and reviewed disposition.

### Phase 5 — Runtime and release hardening

-   Run Chromium, Firefox, and WebKit authoring/runtime coverage.
-   Verify speaker, print/PDF, overview, search, fragments, URLs, touch, RTL,
    reduced motion, accessibility, and performance.
-   Re-run all flat-deck, migration, release, Plugin Check, and companion-theme
    gates.
-   Deploy privately with backup, rollback, scoped OPcache invalidation, and
    representative production signoff before freezing the public 2.0 candidate.

Exit: native and converted Nested Slides are approved in private production and no
flat-deck regression remains.

## Required test matrix

| Layer            | Required coverage                                                                                                                                                                                                                                                                                                                                  |
| ---------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| PHP              | registration, structure validation, nested rendering, sanitization, routing, revisions, migration and restore                                                                                                                                                                                                                                      |
| JavaScript       | hierarchy selectors, numbering, wrapping, unwrapping, cloning, move boundaries, anchors and conversion                                                                                                                                                                                                                                             |
| Editor E2E       | prove Add after/default and Add before preserve the selected Slide's parent at both levels; prove Add nested appears and wraps only for top-level Slides and is absent for nested Slides; prove no third-level nesting; select, edit, reorder, move to top level/into group, cross-level drag/drop, hide, duplicate, delete, undo, save and reload |
| Reveal E2E       | keyboard, touch, hashes, fragments, controls, progress, overview, search, zoom and speaker view                                                                                                                                                                                                                                                    |
| Print            | vertical order, hidden Slides, notes exclusion and page count                                                                                                                                                                                                                                                                                      |
| Accessibility    | tree semantics, focus order, announcements, names, contrast and axe scan                                                                                                                                                                                                                                                                           |
| Migration corpus | all stack signatures, deterministic conversion, visual/structural parity, revision and exact restore                                                                                                                                                                                                                                               |
| Regression       | all existing flat-deck, editor, navigator, theme, chart and release gates                                                                                                                                                                                                                                                                          |

## Risks and mitigations

| Risk                                             | Mitigation                                                                                                                                            |
| ------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| WordPress block moves create multiple undo steps | Compose each structural transform before dispatch and assert one undo restores the prior tree                                                         |
| Existing anchors break when wrapping a Slide     | Keep the original Slide anchor on the first child; give Stack a separate group anchor                                                                 |
| Navigator becomes visually dense                 | Two-level maximum, collapsible Nested Slides rows, dotted numbering, compact group summaries                                                          |
| Drag/drop is ambiguous across levels             | Build explicit accessible Move commands first, reuse those proven transforms for cross-level drag/drop, and keep drag/drop as the normal release gate |
| Outer and child Reveal attributes differ         | Complete corpus characterization before finalizing Stack attributes                                                                                   |
| Conversion changes historical output             | Strict all-or-nothing recognition, structural/pixel comparison, revision plus Presenter restore                                                       |
| Large decks become slow                          | Item-level subscriptions, selection-follow scrolling, measured mixed-deck performance gate                                                            |
| Block schema destabilizes 2.0                    | Add before public freeze, preserve flat tree semantics, and avoid generic recursive nesting                                                           |

## Approved product decisions

1. Use **Nested Slides** as the editor-facing structural term. Use one **Add
   Slide** split button whose default is **Add after**, with **Add before** and
   **Add nested** available from its menu. Reveal's “vertical stack” remains an
   implementation and compatibility term only.
2. Add nested on a top-level Slide wraps it in the container required by Reveal
   and adds the second child. The option is absent for an already nested Slide.
   Add after and Add before always create siblings under the selected Slide's
   current parent. The selected Slide's own content and anchor stay intact.
3. Show nested Slides together in the editor with an ordinary horizontal parent
   and a clean, indented continuation rail—without a group outline, heading, or
   count.
4. Build explicit move commands first. Cross-level drag/drop is expected before
   public shipment unless the release gate is explicitly waived after testing.
5. Start with minimal Nested Slides settings and expand only when the production
   attribute inventory proves which Reveal options belong on the outer
   container.

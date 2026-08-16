# ADR 0007: Native Nested Slides

Status: Accepted  
Date: 2026-08-07

## Context

Reveal.js supports a two-dimensional presentation structure: a top-level
`section` may itself contain direct child `section` elements. Presenter 2.0
initially retained those vertical stacks as Custom HTML because Deck accepted
only direct Slide children. The production-derived migration inventory showed
that this leaves a material share of otherwise convertible content opaque, and
authors use Reveal's nested navigation as a normal presentation pattern.

Reveal does not support a third nested Slide level. A runtime probe against
Reveal 6.0.1 produced inconsistent current-state and navigation behavior for a
group inside a group, so Presenter needs a deliberately bounded hierarchy.

## Decision

-   Use **Nested Slides** in editor-facing copy. `presenter/stack` remains the
    internal block name and “vertical stack” remains a Reveal compatibility term.
-   Support exactly `Deck → Slide` or `Deck → Stack → Slide`. Deck accepts Slide
    and Stack children; Stack accepts only direct Slide children. Recursive,
    empty, and wrapper-content structures are invalid.
-   Render Stack dynamically as the outer Reveal `section`; each existing Slide
    renderer emits one direct child `section`. Stack stores a sanitized anchor,
    editor navigation label, and validated wrapper classes that apply unchanged
    to Reveal's outer stack section.
-   Accept a singleton Stack defensively when parsing stored content, while editor
    move and delete transforms automatically unwrap it. Never author an empty
    Stack.
-   Expose one **Add Slide** split button. Its primary action is **Add after** at
    the selected Slide's current level. The menu offers **Add after**, **Add
    before**, and—only for a top-level Slide—**Add nested**. Add nested wraps the
    selected Slide and a new sibling in one Stack while preserving the selected
    Slide's anchor.
-   Represent the first Slide in a Stack as the horizontal position `x`; its
    continuation Slides use `x.1`, `x.2`, and so on. A Presenter-owned Slides
    rail opens by default, remains available beside WordPress's Slideshow or
    Block settings, and collapses to a labelled vertical tab. Its navigator uses
    a two-level ARIA tree, full-subtree group duplication, confirmed group
    deletion, same-parent ordering, explicit cross-level move commands, and
    cross-level drag/drop backed by the same pure transforms. The canvas and rail
    show the first Slide normally and identify continuations through indentation,
    a left border, and a slightly reduced canvas rather than group chrome.
-   Compose structural edits before one root replacement so WordPress undo
    restores them atomically. When Gutenberg would otherwise reuse an unchanged
    Stack client ID with changed children, materialize a fresh Stack block while
    preserving its persisted anchor and child identities.
-   Convert only complete, canonical retained stacks. The outer migrated Slide
    must carry no behavior or notes that cannot move to Stack; every direct child
    must be an exactly representable `section`. Unsupported attributes, opaque
    nesting, or ambiguous behavior retain the complete Custom HTML source.
-   Provide the same strict conversion in both the server migration planner and
    the editor's retained-HTML conversion path. Child content may remain in the
    smallest Custom HTML island when its wrapper hierarchy is fully understood.

This decision supersedes ADR 0002 only where that record excludes vertical
stacks and restricts every Deck child to `presenter/slide`.

## Consequences

Presenter can author and render Reveal's supported nested navigation without
recursive block trees. The rail uses public plugin registration and block/data
APIs for behavior; a small, fail-soft editor-shell geometry adapter positions it
beside WordPress's optional Settings panel. Flat decks keep their existing
structure and output. Retained-stack conversion preserves each historical
child ID exactly, including an absent ID or an ID duplicated by its parent;
newly authored and duplicated native groups and Slides receive distinct
anchors.

Validation and conversion are intentionally conservative. A malformed or
behaviorally ambiguous retained stack remains reviewable Custom HTML instead of
being partly converted. The release gate now includes nested authoring,
cross-level drag/drop, undo, hashes and navigation, speaker view, print/PDF,
accessibility, and a production-derived conversion rehearsal.

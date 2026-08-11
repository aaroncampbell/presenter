# Vertical slides — admin UI design

Status: Proposed for review
Prepared: 2026-08-07
Relates to: `docs/vertical-slide-stacks-plan.md` (the programme plan)
Prototype: `docs/mockups/vertical-slides-ui.html`

This document covers **only the wp-admin authoring experience**. It assumes the
block contract, rendering, migration, and test strategy from the programme plan
and does not restate them. Where it disagrees with that plan, the disagreement
is called out explicitly in "Departures from the programme plan".

---

## 1. The problem, stated precisely

Reveal has two navigation axes. Horizontal slides step with `←` `→`; vertical
slides nested inside a top-level `<section>` step with `↑` `↓`.

Presenter's editor canvas today lays every slide out **in a single vertical
column** (`.presenter-deck-editor .slides { display:grid }` with full-size
1280×720 frames scaled to fit the canvas width). That is a good content-editing
surface and it should not change.

But it means the canvas already spends its vertical axis on *horizontal*
slides. If vertical slides are also drawn top-to-bottom, the two axes become
visually identical, and the author has no way to see the difference except by
reading labels.

Worse: at full size, only about one and a half slides fit on screen. Structure
of any kind is simply invisible. Scrolling a 4-slide stack takes six screens.

**So the design problem is not "where do we put the nesting controls". It is
"how does an author ever see the shape of their deck".** Every decision below
follows from that.

---

## 2. Design principles

1. **Nothing changes for flat decks.** A deck with no stacks looks and behaves
   exactly as it does today. Stack affordances appear on hover, or once a stack
   exists — never as permanent chrome an author must learn around.
2. **The editor should mirror what the audience does.** If the author sees the
   deck laid out the way Reveal's overview mode presents it, "vertical" needs no
   explanation.
3. **Structure and content are different jobs.** Do not force one canvas to do
   both well.
4. **Every structural action is reachable without a mouse**, and says out loud
   what it did to the tree.
5. **Never silently rewrite an author's structure.** Reversible, explicit, one
   undo step.

---

## 3. The core proposal: two canvas modes

Add a segmented control to the editor header: **Edit** ▭ / **Arrange** ⊞.
It is the only new permanent chrome, and it is useful for flat decks too.

### 3.1 Edit mode (default — today's canvas, extended)

Full-size slides in one column. This is where content is written.

A stack renders as a **bounded, tinted group**:

```
┌─ ↓ Vertical stack 4 · WP HTTP ── 4 slides · audience arrives at 4.1, then presses ↓ ── ⋮ ─┐
│                                                                                          │
│  ●──  4.1  Overview   #wp-http   #/3/0                                          ⋮       │
│  │    ┌──────────────────────────────────────────────────────────────────────┐          │
│  │    │                    wp_remote_get()                                   │          │
│  │    └──────────────────────────────────────────────────────────────────────┘          │
│  ↓    [ ＋ Add vertical slide here ]                                                     │
│  ●──  4.2  Arguments  #wp-http-args   #/3/1                                     ⋮       │
│  ...                                                                                     │
└──────────────────────────────────────────────────────────────────────────────────────────┘
```

Signals that distinguish the vertical axis from the horizontal one:

| Signal | Horizontal slide | Vertical slide |
| --- | --- | --- |
| Container | none | tinted, bordered group |
| Indent | flush | inset behind a rail |
| Rail | none | continuous vertical line with a node per slide |
| Position badge | `4` on grey | `4.1` on purple |
| Between-slide affordance | full-width `＋ Add slide` divider | `↓ ＋ Add vertical slide here` on the rail |

**Purple is load-bearing.** One reserved hue means "this is the down axis" —
in the stack border, the rail, position badges, the deck map, the navigator
tree, and the stack buttons. Nothing else in the editor uses it.

Each slide gets a thin meta bar above it showing position, label, its anchor,
and its Reveal URL (`#/3/1`). This is small but does a lot of work: it makes
the 1-based author label and the 0-based Reveal hash visible side by side, so
the mapping is never a mystery.

### 3.2 Arrange mode (new — the structural surface)

Scaled thumbnails laid out as a **true 2-D grid**: columns run left-to-right
for horizontal slides, cells run top-to-bottom inside a stack.

```
     1              2              3          ↓ 4 · WP HTTP (4)        5              6
┌──────────┐  ┌──────────┐  ┌──────────┐  ┏━━━━━━━━━━━━━━┓  ┌──────────┐  ┌──────────┐
│ Title    │  │ Me       │  │ Why APIs │  ┃ ┌──────────┐ ┃  │ Caching  │  │Questions?│
└──────────┘  └──────────┘  └──────────┘  ┃ │ 4.1      │ ┃  └──────────┘  └──────────┘
                                          ┃ └──────────┘ ┃
                                          ┃ ┌──────────┐ ┃
                                          ┃ │ 4.2      │ ┃
                                          ┃ └──────────┘ ┃
                                          ┃    4.3 4.4   ┃
                                          ┗━━━━━━━━━━━━━━┛
```

This is deliberately the same picture as Reveal's own overview mode and the
diagram on revealjs.com/vertical-slides. An author who has ever pressed `Esc`
during a presentation already knows how to read it.

Drag targets, all with visible drop zones:

- **between columns** → move to that horizontal position
- **into a stack, between children** → move to that vertical position
- **the `↓ nest here` strip beneath a lone slide** → wrap both into a new stack
- **out of a stack onto a column gap** → flatten that slide back to horizontal

One gesture covers create-a-stack, reorder-in-either-axis, and un-nest. That is
the whole structural vocabulary, and it is spatially literal.

Arrange is a *view*, not a mode with its own state. Selection is shared, so
switching to Edit lands the author on the slide they were just arranging.

---

## 4. The Slides sidebar

The sidebar stays on `PluginSidebar` and remains the keyboard-complete,
screen-reader-complete equivalent of everything Arrange offers with a mouse.

### 4.1 Deck map (new, top of the panel)

A compact grid of cells, one per slide, always visible: columns across,
vertical children stacked down, purple cells for vertical. Click to jump; hover
for `3.2 — Architecture  #/2/1`.

At ~20×13px a cell, a 40-slide deck fits in the panel width with room to spare.
This is the always-on orientation aid that a scrolling list can never be, and
it costs almost nothing.

### 4.2 Two-level tree

```
  1   [thumb]  Title            #title
  2   [thumb]  Me               #me
  3   [thumb]  Why APIs         #why-apis
▼ 4   [thumb]  WP HTTP          4 vertical slides
  │ 4.1 [thumb] Overview        #wp-http
  │ 4.2 [thumb] Arguments       #wp-http-args
  │ 4.3 [thumb] Responses       #wp-http-resp
  │ 4.4 [thumb] Errors          #wp-http-errors
  5   [thumb]  Caching          #caching
```

`role="tree"` / `role="group"` / `role="treeitem"`, one disclosure button per
stack, one tabbable selection target per row. Accessible names carry position
and count: *"Slide 4.2: Arguments"*, *"Stack 4: WP HTTP, 4 slides"*.

### 4.3 Row actions collapse into a `⋮` menu

**Implemented.** The earlier navigator rendered five text buttons (Up / Down /
Hide / Duplicate / Delete) on every row. On a real 18-slide deck that was 90
buttons in the tab order and roughly 100px of row height each. The current
navigator exposes one three-dot menu on hover or focus instead.

The menu provides the valid operations for that Slide's current level:

```
Move up / Move down
Move to top level                     nested Slides only
Move into Nested Slides before/after top-level Slides when adjacent
──────────────────────────────
Hide / Show
Duplicate
──────────────────────────────
Delete
```

The tree remains scannable and the tab order is no longer multiplied by every
available action.

### 4.4 Header actions

**Implemented.** The **Add Slide** split button defaults to **Add after** at the
current level. Its menu offers **Add before** and, when a top-level Slide is
selected, **Add nested**.

---

## 5. Terminology

| Concept | Term | Never |
| --- | --- | --- |
| Top-level slide | **slide** | "horizontal slide" in normal UI copy |
| Nested slide | **nested Slide** | "vertical slide", "sub-slide" |
| The group | **Nested Slides** | "vertical stack", "section", "column" |
| Create nested | **Add nested** | "Add below", "Add vertical" |
| Create sibling | **Add before** / **Add after** | "Add left", "Add right" |
| Un-nest one slide | **Move to top level** | "Promote", "Outdent" |

Reveal still uses down/right navigation for the resulting structure, but the
editor describes the authoring relationship instead of exposing those
directions as product terminology.

---

## 6. Anchors — the thing most likely to break links

Aaron's published decks are linked directly by anchor
(`.../#/wp-http`, `.../#/5`). Wrapping must never invalidate an existing URL.

Rule: **the slide that already existed keeps its anchor; the new stack gets a
new group anchor.**

When you "Add vertical slide" under slide 5 (`#caching`):

- slide 5 becomes `5.1`, still `#caching` — every existing link resolves
- the new stack gets `#stack-…`, editable in the inspector
- the new vertical slide gets a fresh `#slide-…`

The prototype announces exactly this, in words, every time it happens:

> *Slide 5 became a vertical stack with 2 slides. The original slide kept its
> anchor "caching", so existing links still work.*

Flatten does the reverse and says so: each slide keeps its own anchor, the
group anchor is discarded.

---

## 7. What the production content says

I scanned the 27 MB production database export (`aarondcampbell (1).sql`) for
outer `<section>` elements whose first child is another `<section>`:

| Finding | Count |
| --- | --- |
| Vertical stacks in production content | **77** |
| …carrying only an `id` attribute | 52 |
| …carrying no attributes at all | 25 |
| …carrying a background, transition, class, or `data-*` | **0** |
| Vertical child slides | ~200 |
| …with their own `id` anchor | ~199 |
| Largest stack observed | 4 children |

Two design conclusions follow directly:

1. **Stack settings are `label` + `anchor`. Nothing else.** No background, no
   transition, no Reveal attribute panel. Not one stack in the corpus needed
   them. Adding those controls would invent a decision authors have never had
   to make, and would create a second place to look for slide settings.
2. **Stacks are shallow (2–4).** So expand-by-default in the navigator is
   correct, Arrange mode never needs vertical scrolling within a column, and
   collapse is a convenience rather than a necessity.

> Caveat: this was a regex scan, not a parse, and it counts stacks in current
> post content. The programme plan's figure of "187 stack-related Custom HTML
> fallbacks" counts something different (fallback blocks, possibly including
> revisions). Both numbers should be reconciled with
> `Legacy_Section_Validator::classify()` before Phase 4 planning. The
> attribute distribution is the load-bearing finding here, and it is
> unambiguous.

---

## 8. Departures from the programme plan

### 8.1 "Add right" and "Add below" are wrong words — drop them

The plan proposes spatial verbs. They are correct about Reveal, and wrong
about the editor: in the Edit canvas, top-level slides are drawn **downward**,
so "Add right" would create a slide that appears *below* the current one, and
"Add below" would create one that appears below it too. Two different actions,
identical apparent result.

Use **Add slide** / **Add vertical slide** in Edit mode, where there is no
meaningful horizontal axis. Reserve genuinely spatial language for Arrange
mode, where left/right and up/down are literally true — there the `⋮` menu
reads "Move left" / "Move right".

### 8.2 Do not auto-unwrap a stack down to one slide

The plan says removing the penultimate child should automatically unwrap the
remaining child. Recommend against:

- A one-child stack is **valid Reveal** and renders correctly. There is no
  correctness argument for the rewrite.
- It destroys the stack's anchor and label without asking. If the author was
  mid-edit — delete two slides, add three back — the next add produces a
  *different* group anchor, and any link to the group silently breaks.
- It makes one authored action produce two tree mutations, which is exactly the
  undo hazard the plan's own risk table warns about.

Instead: keep the stack, and tell the author it is now a singleton with an
explicit way out.

> *That stack now holds one slide. It still presents correctly — use "Flatten
> stack" if you want it horizontal.*

Still auto-remove a stack that reaches **zero** children; that one is genuinely
unrenderable.

### 8.3 Ship cross-level drag in Arrange from the start

The plan defers cross-level drag/drop past MVP as risky and ambiguous. That
reasoning holds for the *Edit* canvas and for the sidebar tree, where a drag
between levels has no unambiguous target.

It does not hold in Arrange mode, because the 2-D layout gives every level its
own visible region: columns are horizontal, cells inside a bordered box are
vertical, and the `↓ nest here` strip is a discrete labelled target. The
gesture is unambiguous *because the layout is*.

Keyboard and menu equivalents still ship first and remain complete — drag is an
accelerator, never the only route.

### 8.4 Add `navigationMode` to deck settings

Presenter's Reveal config (`includes/class-reveal-config.php`) does not emit
Reveal's `navigationMode` option at all. That option exists specifically for
decks that mix horizontal and vertical slides:

- `default` — `←` `→` between columns, `↑` `↓` within a stack
- `linear` — `←` `→` step through everything, no `↑` `↓`
- `grid` — `←` `→` preserve your vertical position

`linear` is the single most useful accessibility and print-parity affordance
for a deck with stacks: it lets an author offer the whole deck as one sequence.
Shipping vertical slides without it means authors hit the limitation and have
no control.

Surface it under **Deck → Navigation**, and only once the deck contains a
stack, so flat decks gain no new setting.

### 8.5 Do not fail an invalid tree to the site theme

The plan routes structurally invalid decks to the active site theme. From the
author's seat that is indistinguishable from a broken plugin — a completely
different page, no explanation, no pointer to the offending block.

Prefer: render the deck with the invalid node flattened to a top-level slide,
plus an editor notice naming the block. Reserve the theme fallback for content
that cannot be parsed at all.

### 8.6 Positions in the UI, not just in the navigator

The plan scopes `3.1` numbering to the navigator. Put it everywhere a slide is
identified — canvas meta bar, Arrange card, deck map tooltip, inspector, and
every announcement — alongside the Reveal URL. Authors debug links and speaker
notes by position; one consistent vocabulary across every surface is close to
free.

---

## 9. Accessibility

- Navigator is a real tree: `role="tree"`, `role="group"`, `role="treeitem"`,
  `aria-expanded` on the disclosure, `aria-selected` on rows, roving tabindex.
- The disclosure button is a **sibling** of the selection target, never covered
  by it. (Today's `.presenter-slide-navigator-select` is an absolutely
  positioned full-bleed overlay — that pattern cannot be reused for stack rows.)
- Accessible names carry position, title, and, for stacks, child count.
- Arrange cards are `role="button"` with the same names, focusable, and every
  drag has a menu equivalent.
- Structural changes announce their result through `aria-live="polite"`,
  describing the tree change *and* the anchor consequence — not just "moved".
- Purple is never the only signal: indentation, rail, dotted numbering, border,
  and text labels all carry the same information.
- Contrast: `#5c2f96` on `#f6f1fc` is ~8.9:1; the `#8c53d0` border is used for
  structure, never for text.

---

## 10. Suggested build order

This re-sequences the programme plan's Phases 2–3 around the finding that the
structural surface is the hard part.

| Step | Scope | Why here |
| --- | --- | --- |
| 1 | `presenter/stack` block + nested rendering + validation | nothing is demonstrable without it |
| 2 | Collapse navigator row actions into `⋮` | independent improvement; unblocks the tree |
| 3 | Edit-mode stack group: boundary, rail, positions, meta bar | authors can *see* a stack |
| 4 | `Add vertical slide` + wrap, with anchor preservation and one-step undo | the core create gesture |
| 5 | Two-level navigator tree + deck map | keyboard-complete structure editing |
| 6 | `Move out` / `Move into` / `Flatten`, menu-driven | complete the vocabulary without drag |
| 7 | Arrange mode, read-only | the orientation win, cheap once the model exists |
| 8 | Arrange drag and drop | accelerator on a proven model |
| 9 | `navigationMode` deck setting | small, and completes the runtime story |

Steps 1–6 are a shippable feature on their own. Steps 7–8 are what make it feel
obvious.

---

## 11. Open questions

1. Should Arrange mode be available for flat decks too? (Recommend yes — it is
   a better slide sorter than the sidebar for any deck over ~15 slides, and it
   makes the mode switch familiar *before* the first stack exists.)
2. Should `Add vertical slide` on the **last** slide of a stack be the same
   button as on a lone slide? (Recommend yes — same label, different result,
   both spatially correct.)
3. Does WordPress List View need a custom label for stack rows, or is the block
   title enough?
4. Should the deck map be collapsible, or always visible? (Recommend always —
   it is ~60px and it is the orientation aid.)
5. Print/PDF and speaker view are out of scope here but need their own pass:
   does `linear` navigation mode change the expected print order?

---

## 12. Trying the prototype

`docs/mockups/vertical-slides-ui.html` is a self-contained, dependency-free
prototype of everything above. It is a design artefact, not plugin code.

Because it needs to be served over HTTP rather than opened as a `file://` URL,
the simplest route with `wp-env` running is:

```
http://localhost:8890/wp-content/plugins/presenter/docs/mockups/vertical-slides-ui.html
```

What is worth exercising:

- **Show a flat deck** — confirms nothing changes when there are no stacks
- **Edit ↔ Arrange** — the same deck, both surfaces
- `⋮` → **Add vertical slide below** on slide 5, and read the announcement
- In Arrange, drag slide 3 onto the `↓ nest here` strip under slide 2
- In Arrange, drag `4.3` out of the stack onto a column gap
- **Block** tab with a stack selected — note how few settings it has
- Delete vertical slides until a stack has one left — note that it is *not*
  silently rewritten

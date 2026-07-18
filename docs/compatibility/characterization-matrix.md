# Presenter 1.x characterization matrix

This matrix tracks executable evidence for behavior that may otherwise change
accidentally during the 2.0 rewrite. “Characterized” does not mean that a
legacy limitation must remain in new content; it means any change must be
deliberate and migration-aware.

| Area | Current coverage | Status |
| --- | --- | --- |
| Post type | Public `slideshow`, archive slug, supported editor features | Automated |
| Template routing | Presenter template for unprotected decks; theme/password path for protected decks | Automated |
| WordPress 7 loading | Script registry is initialized safely during template selection | Automated |
| Short URL | Stored short URL and permalink fallback | Automated |
| Legacy slides | Numeric order, title anchors, missing optional fields, nested sections, classes, arbitrary data, plain/Markdown notes | Automated |
| Password confidentiality | Slide and note secrets are absent before authentication; snapshot serves the password form without Reveal markup; a synthetic local password completes the WordPress cookie flow and survives reload | Automated plus local browser evidence |
| Legacy saving | Nonce, capability check, programmatic-save preservation, posted ordering, renumbering, Markdown flag, data attributes | Automated |
| Public hooks | Head, Reveal-footer, footer order and priority around initialization | Automated |
| Assets | Stable public handles, Reveal dependencies, filter additions, paths and legacy version strings | Automated |
| Themes | Directory discovery, both header formats, restrictive theme filter behavior, cache, default and URL filters | Automated |
| Reveal runtime | Reveal 4.3.1 availability/readiness, first-slide selection, keyboard navigation, and forward/backward fragments on the private snapshot | Repeatable local browser assertions |
| Companion plugin | Theme directory/default/rewrite, Chart.js dependency replacement, transition defaults, archive privacy, persistent footer, and snapshot asset loading | Automated plus local browser evidence |
| Private corpus | Deterministic 11-deck selection, HMAC-protected structural manifests, anonymous/authenticated browser readiness | Repeatable local capture complete |
| Archive behavior | Local archive responds through a normal WordPress theme and excludes the protected deck anonymously | Local HTTP evidence |
| Speaker/print views | Seven public corpus decks captured in standard, print-PDF, and speaker modes; print mode activates, speaker shell/connection/preview readiness are recorded independently | Repeatable ignored local visual baselines |

Presenter 1.x characterization is sufficient to begin the 2.0 runtime skeleton.
Known legacy limitations remain recorded in the private manifests and visual
baselines rather than being promoted to new-runtime requirements.

## Milestone 3 dual-runtime boundary

| Request shape | Selected behavior | Evidence |
| --- | --- | --- |
| Password required | Active-theme password template; no presentation assets or slide markup before authentication | Automated routing and confidentiality tests |
| `_presenter_slides` present | Read-only Presenter 1.x compatibility template and Reveal.js 4.3.1; no metadata writes | Automated routing, renderer, and interaction tests |
| `presenter/deck` present without legacy slides | Presenter 2.0 template, standard WordPress hooks, registered theme, and Reveal.js 6 | Automated routing, renderer, configuration, and headless runtime tests |
| Neither storage shape present | Template selected by WordPress or the active theme | Automated routing tests |

Legacy metadata wins if both storage shapes are present. This makes migration a
deliberate handoff instead of allowing partially written native content to
replace a published legacy deck.

## Milestone 4 native authoring slice

| Area | Current coverage | Status |
| --- | --- | --- |
| Block registration and structure | Block API v3 Deck/Slide metadata, locked one-Deck post template, Slide-only direct Deck children, unrestricted Slide content | Automated PHP and editor runtime |
| Native editor assets | Deck and Slide share one generated editor bundle; native and new decks do not load legacy Presenter editor UI or assets | Automated PHP and editor runtime |
| Native rendering | Direct `.slides > section` hierarchy, dimensions, anchors, hidden slides, escaped plain/Markdown notes, and single execution of dynamic child render callbacks | Automated PHP and real WordPress headless runtime |
| Route validation | Empty Decks, extra roots, nested Decks, and non-Slide direct children do not enter the native presentation template | Automated PHP |
| Editor persistence | Create, configure, undo, save, reload, validate, and remove a deck containing Heading, Paragraph, Group, Columns, List, Code, Image, Buttons, Accordion, Shortcode, and Latest Posts blocks | Repeatable headless editor runtime |
| Core-block interoperability | Static/nested markup, media, buttons, shortcode output, WordPress 7 Accordion Interactivity API behavior, dynamic Latest Posts output, and Reveal navigation after interaction | Repeatable real WordPress headless runtime |
| Theme registry and preview parity | Server-filtered registry and shared default/legacy resolution; WordPress `transformStyles` scoping beneath a Reveal-shaped editor wrapper without Reveal base CSS; cached retryable preview requests and visible failure warning; inline Slide backgrounds; bundled-theme and external site-specific `aaron-purple` fixture computed-style parity between editor and front end | Automated PHP, JavaScript, and real WordPress headless runtime |
| Accessibility safeguards | Escaped Slide-label `aria-label`, visible hidden-Slide treatment, `aria-invalid` background feedback, controls-or-keyboard invariant, keyboard-tested skip link and focusable target, and one viewport declaration | Automated PHP, JavaScript, and real WordPress headless runtime |
| Legacy editor isolation | Classic meta boxes, WYSIWYG initialization, script, and stylesheet load only for posts with `_presenter_slides` metadata | Automated PHP |

The Milestone 4 native-block, accessibility/regression, and theme-parity
coverage is executable. The companion plugin registers stable `aaron-purple`
and makes it the modern site default while retaining its legacy directory,
default-path, and stylesheet-rewrite hooks. The checkpoint passes 88 PHP tests
with 421 assertions and 37 JavaScript tests. No functional Milestone 4 gaps are
known: the navigator is Milestone 5, and inherited Presenter 1.x Plugin Check
debt remains release-hardening work rather than part of this slice.

## Milestone 5 presentation workflow

| Area | Current coverage | Status |
| --- | --- | --- |
| Supported placement | Public WordPress `PluginSidebar`; no private permanent-rail or List View API | Automated build and real editor runtime |
| Thumbnails and labels | `BlockPreview`, explicit label, recursive Heading/text fallback, numbered untitled fallback, slide number, current and hidden state | JavaScript unit and 60-slide headless runtime |
| Slide management | Select, add, duplicate with a unique anchor, delete with one-slide safeguard, and hide/show | Real editor runtime with single-step undo |
| Reordering | Visible insertion boundaries using native HTML5 drag/drop plus explicit move-up/move-down controls | Real editor runtime with undo and pure index tests |
| Large-deck safeguards | Final-slide selection, 60 live previews, browser-error collection, deterministic fixture cleanup | Repeatable headless runtime |
| Native fragments | Scoped controls and inherited Slide context; static, nested, and dynamic roots; allow-listed effects/custom classes; shared explicit ordering; forward/backward steps; ordinary posts unchanged | JavaScript, PHP, editor save/reload, and Reveal headless runtime |
| Advanced Reveal behavior | Controlled background data attributes, auto-animate settings and event, progressive print/PDF pages, connected Markdown speaker view, and configuration/plugin extension contracts | PHP, JavaScript, and real WordPress headless runtime |
| Migration dry-run | Complete immutable source snapshot, site-keyed fingerprint, stable ordering/no deduplication, historical 960×700 sizing, registered legacy-theme aliases, exact Slide class/data mapping, content-free blockers, bounded CLI batches, and zero writes | PHP/JavaScript integration, real WP-CLI synthetic gate, and isolated 64-deck corpus report |

The Milestone 5 gate passes without adding virtualization. Further performance
work remains measurement-driven rather than being introduced speculatively.

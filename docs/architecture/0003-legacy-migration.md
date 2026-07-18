# ADR 0003: Fidelity-first legacy migration

Status: Accepted  
Date: 2026-07-15

## Context

Existing Presenter decks store serialized slide objects in repeated
`_presenter_slides` metadata. The production-derived dataset contains custom
HTML, inline styles, shortcodes, fragments, arbitrary Reveal.js data attributes,
and Markdown notes. Automatic approximate conversion risks visible or semantic
data loss.

## Decision

- Migration never runs as an unbounded activation task or a write during a
  public request.
- Provide dry-run, batched, resumable, idempotent, and restorable migration
  through WP-CLI and an authenticated admin tool.
- Preserve versioned, checksummed source data and a WordPress revision before
  changing content.
- Keep legacy metadata through the initial 2.0 release.
- The structural MVP creates Deck and Slide blocks and uses a Custom HTML child
  whenever native conversion cannot be proven lossless.
- Custom HTML is a tracked fallback, not the desired final state. Conversion
  coverage is measured per slide and recurring patterns receive deterministic,
  tested native-block converters.
- Remaining fallbacks on AaronDCampbell.com must become reviewed, documented
  exceptions rather than silently unhandled content.

## Consequences

Presenter can ship a safe structural migration before every legacy pattern has
a native converter. Native conversion can improve iteratively without making
published decks unavailable or destroying the original representation.

## First implementation checkpoint

The first Milestone 7 slice is deliberately read-only:

- `Legacy_Deck_Snapshotter` captures the complete post identity and visibility,
  existing post content, legacy theme and short URL, and every repeated slide
  value in source order.
- Snapshots expose isolated copies and a site-keyed HMAC fingerprint. Reports do
  not expose the fingerprint or authored content.
- `Legacy_Slide_Normalizer` accepts object, array, older, and malformed records,
  retains source ordinal and slide number, and stable-sorts by number then source
  ordinal without deduplicating anything.
- `Migration_Planner` produces a deterministic Deck/Slide tree with `core/html`
  fallback only when the current schema can preserve the source. Otherwise it
  returns no applicable content and content-free blocker codes.
- `wp presenter migration dry-run` accepts one post ID or a bounded batch of at
  most 100 decks. It reads and reports only; it has no writer dependency.

## Second implementation checkpoint

The next read-only slice closes the representation gaps that have exact native
equivalents:

- Migration preserves the Presenter 1.x 960×700 Reveal canvas explicitly while
  new decks continue to default to 1280×720.
- Registered themes own explicit historical aliases. Migration resolves those
  paths to stable Deck theme IDs; an unknown or ambiguously owned alias remains
  blocked. Aaron Purple aliases remain owned by the external companion plugin.
- Slide wrapper classes and ordered Reveal data attributes use one validation
  contract shared by migration and rendering. Invalid lists are rejected as a
  whole rather than cleaned or partially applied.
- Known Reveal values map to typed Slide settings. Other safe `data-*` values
  remain ordered advanced Slide attributes, including plugin-specific values.
- HTTP(S), protocol-relative, and root-relative presentation resources are
  supported without rewriting their authored values. Unsafe protocols remain
  blocked.

Against the isolated production snapshot, 20 of 64 legacy decks now produce a
ready plan. Wrapper classes and registered theme paths no longer block any deck.
The remaining content-free blocker counts are HTML notes (34 decks), nested
sections (9), duplicate/ambiguous data attributes (8), and malformed source
values (2). These remain an implementation queue, not permission to discard
values. Backup, revision, write, verification, route cutover, resume, and
restore services remain intentionally absent until those gaps are closed.

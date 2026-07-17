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

Current blockers are legacy wrapper classes, arbitrary data attributes, HTML
notes, nested sections, leftover legacy post content, explicit legacy theme
paths, and malformed source values. These are an implementation queue, not
permission to discard those values. Backup, revision, write, verification,
route cutover, resume, and restore services remain intentionally absent until
the representation gaps are closed.

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

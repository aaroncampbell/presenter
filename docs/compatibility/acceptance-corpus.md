# Private acceptance corpus

The production-derived acceptance corpus remains local and uncommitted. Record
post IDs only in an ignored private manifest; do not copy presentation content
into issue descriptions, CI logs, or public fixtures.

## Aggregate snapshot profile

The audited production-prefix data contains 65 current decks and 1,111 legacy
slides. The set includes published, draft, private, and password-protected
content; Markdown notes; fragments; background images, colors, and videos;
charts; code; images; custom data attributes; short URLs; and explicit or
default `aaron-purple` selection.

Some legacy slide content includes nested `<section>` markup. Preserve that
markup as a compatibility case without adding new vertical-stack authoring to
2.0.

## Selection criteria

Choose 8–12 canonical decks deterministically. Build a content-free feature
vector for every deck, select mandatory boundary/security cases first, then add
the deck covering the most still-unrepresented features. Break ties by fewer
redundant features and then lower post ID. Pin the result in the ignored local
manifest so a later snapshot cannot silently change the baseline.

The required coverage is:

1. smallest simple published deck;
2. median-size typical `aaron-purple` deck;
3. largest or near-largest deck;
4. legacy nested-section content;
5. notes with Markdown enabled;
6. arbitrary data attributes plus image or color backgrounds;
7. background video;
8. heavy fragments and custom theme classes;
9. Chart.js integration;
10. code/highlighting integration;
11. the password-protected deck;
12. an empty/default theme selection.

Across the set, include a short-URL deck and a deck without one, plus separate
draft/private visibility checks. Prefer decks whose referenced uploads exist in
the supplied archive.

## Captures

For each selected deck, store locally:

- a structural manifest of counts, feature flags, and content hashes;
- rendered DOM captured from Presenter 1.5.2 with Reveal.js 4.3.1;
- presentation screenshots at the historical logical dimensions;
- archive and password-flow screenshots when applicable;
- referenced upload availability;
- migration dry-run and conversion reports;
- post-migration DOM and screenshot comparisons.

Commit only synthetic fixtures derived from generalized patterns or content the
owner explicitly approves for the public repository.

Use HMAC-SHA256 with an ignored local key for content/title/notes comparisons.
Unsalted hashes can reveal whether private text matches a guessed value and
must not be logged or committed. Normalize volatile IDs, nonces, timestamps,
hostnames, and cookies before comparing rendered DOM.

## Migration comparison acceptance

For each corpus deck, capture legacy and native rendering at the same viewport
and require a repeat capture of each representation before comparing them.
Structural acceptance is exact after removing only characterized
Reveal-owned runtime state. The comparison covers runtime readiness,
dimensions, semantic configuration, resolved theme identity, flat/stack
hierarchy, Slide count/order/address/anchors, notes format and content,
fragments, Reveal data attributes, wrapper classes, and canonical rendered
content. Any fixed structural diagnostic is a failed deck, not a visual-review
exception.

Visual comparison uses exact RGBA pixels. Zero changed pixels passes; any
nonzero difference is `review_required` and needs a recorded human decision.
Capture failure, repeat-capture nondeterminism, incomplete images, console or
page errors, local HTTP failures, and blocked external assets fail closed. They
must not be reclassified as a harmless visual difference.

Keep each run's screenshots, diffs, and keyed report beneath ignored private
storage such as `local/migration-comparison-gate/run-<opaque-id>/`, or outside
the repository and web root. Raw images can disclose presentation content.
Only the exact-schema JSON report is content-free: it stores opaque identities,
domain-separated keyed digests, fixed codes, counts, access classifications,
and pixel statistics, never captured DOM or authored values. Do not commit
either the report or its raw artifacts for the private corpus.

The browser capture boundary permits only the selected loopback origin plus
`about:`, `data:`, and same-origin `blob:` resources. Inventory external fonts,
embeds, and scripts before a corpus run; unavailable external resources remain
an incomplete capture until an explicit snapshot-only strategy is reviewed.
The AaronDCampbell.com environment must mount and activate the separate
companion themes plugin so legacy `aaron-purple` behavior—including removal of
the old RevealMath CDN dependency—is represented. That private theme plugin is
test environment context, not part of Presenter or its release package.

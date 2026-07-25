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

For each selected deck, store the access classification and migration/restore
evidence locally. For anonymously capturable public decks, also store:

-   a structural manifest of counts, feature flags, and content hashes;
-   rendered DOM captured from Presenter 1.5.2 with Reveal.js 4.3.1;
-   presentation screenshots at the historical logical dimensions;
-   archive and password-flow screenshots when applicable;
-   referenced upload availability;
-   migration dry-run and conversion reports;
-   post-migration DOM and screenshot comparisons.

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
`about:`, `data:`, and same-origin `blob:` resources. A reviewed private
substitution manifest may restore an exact missing same-origin AVIF counterpart
or provide one reviewed offline Aaron Purple artifact only for the exact legacy
and native versioned same-origin stylesheet aliases. Reviewed staging-origin
Reveal background tokens may also bind to exact archive paths. Only matching
slide-section background attributes are rewritten to same-origin paths before
Reveal initializes, the digest-verified bytes are served locally, and CSP
remains active. The manifest identity and each capture's sorted canonical
entry-digest/usage-count list are authenticated with the comparison. The report
labels and digests the verified entry-set basis; browser request counts remain
authenticated diagnostics because cache and Reveal loading behavior can differ
without changing the exact substituted bytes. This establishes parity under a
pinned offline basis, not historical network-asset fidelity. Unavailable
resources remain incomplete captures. Deck 119's BlueDog image has no local
counterpart and therefore remains an explicit visual-capture failure.
The latest pristine full-corpus rehearsal migrated and exactly restored all 65
decks with zero runner failures and found no structural mismatch among completed
comparisons: 60 structural passes, no structural failures, and five access
skips. No comparison was nondeterministic and no legacy or native page produced
a server error. An earlier full run was interrupted after deck 35;
authenticated resume revalidated all 35 completed records and continued at deck
36 through exact restore of that full corpus.

Six of seven public visual representatives—decks 96, 106, 202, 592, 1322, and
2548—were clean and deterministic but required human review for the expected Reveal 6
navigation-control drift. Independent pixel review found zero changed authored
pixels in every frame: all differences were confined to the bottom-right Reveal
controls. Deck 106's larger bounds are its vertical navigation arrows. Deck
1322's decoded background-video frame and stateful frames remain correct, and
deck 2548's legacy and native 19-frame repeat captures are byte-identical within
each representation. Deck 119 retains an external image with no supplied local
counterpart. Nine exact staging-origin images already present in the supplied
archive now make nine additional decks self-contained. Thirty-one decks in
total, including deck 119, retain historical asset failures and fail closed
rather than producing incomplete parity evidence.
The AaronDCampbell.com environment must mount and activate the separate
companion themes plugin so legacy `aaron-purple` behavior—including removal of
the old RevealMath CDN dependency—is represented. That private theme plugin is
test environment context, not part of Presenter or its release package.

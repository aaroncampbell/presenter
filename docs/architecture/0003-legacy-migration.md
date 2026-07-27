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

## Third implementation checkpoint

The third read-only slice adds exact representations for two recurring legacy
shapes:

- Speaker notes share one rendering and migration policy. Plain text,
  Markdown, allow-listed HTML, and Markdown with allow-listed HTML have
  explicit Slide formats. HTML is eligible only when WordPress sanitization is
  byte-for-byte lossless; unsafe or rewritten markup remains blocked.
- HTML detection uses WordPress's HTML processor so angle-bracket prose and
  type notation are not mistaken for markup.
- A legacy Slide containing only one or more top-level `<section>` elements,
  optional whitespace, and comments is preserved byte-for-byte in `core/html`.
  This compatibility-only path retains existing Reveal vertical stacks without
  adding vertical-stack authoring to Presenter 2.0.
- Mixed roots, indirect sections, missing explicit closers, and descendant
  sections remain blocked because the Presenter Slide wrapper would add an
  unsupported or ambiguous Reveal level.

Against the isolated production snapshot, 52 of 64 legacy decks now produce a
ready plan. HTML notes no longer block any deck, and only 3 decks retain the
nested-section blocker. The remaining content-free blockers are
duplicate/ambiguous data attributes (8 decks), nested sections (3), and
malformed source values (2). Nine decks report the compatibility-stack warning,
including blocked decks, so every preserved legacy structure remains visible in
review output. Migration write, verification, route-cutover, resume, and restore
services remain intentionally absent.

## Fourth implementation checkpoint

The fourth read-only slice closes every remaining representation blocker found
in the isolated production snapshot without broad or silent cleanup:

- Exact duplicate Slide data attributes collapse per Slide only when both the
  rendered name and value match. The first occurrence and unique-attribute
  order are retained. Conflicting duplicate names remain blocked.
- Reports expose a content-free duplicate warning and removal count. The corpus
  contains 318 redundant records across 25 Slides in 8 decks, with no
  conflicting values.
- An obsolete top-level `background` field is ignored only when its value is
  exactly empty, matching both legacy renderers. A stored boolean `false` Slide
  record becomes the same empty Slide produced by the read-only legacy runtime.
  Both shapes retain visible normalization warnings; all other unknown or
  malformed source shapes remain blocking.
- A second section classification recognizes exactly one outer section whose
  direct content consists only of child sections, with no mixed content or
  further depth. Source bytes remain unchanged. A headless contract proves the
  resulting structure, current-slide selection, unavailable downward route,
  and horizontal exit behave identically in Reveal 4.3.1 and Reveal 6.

The isolated production snapshot now produces 65 ready plans from 65 legacy
decks with zero representation blockers. This proves planning coverage, not
migration completion: all plans still rely on tracked Custom HTML fallbacks,
and backup, revision, write, verification, route-cutover, resume, and restore
services remain intentionally absent.

The first corpus inventories used a filterable `WP_Query` and reported 64. The
private companion plugin correctly hides the password-protected deck from
public archive queries, but that policy must not alter migration maintenance.
The bounded inventory now uses a prepared direct query, includes that 65th
legacy deck, and remains deterministically ordered.

## Write-safety foundation

Retaining legacy metadata requires an explicit storage-mode contract before a
writer can exist. Presenter therefore resolves every public and editor route
through one fail-safe rule: legacy metadata remains authoritative unless the
private `_presenter_deck_mode` value is exactly `native`. A migration may set
that marker only as its final cutover operation after native content has been
written and verified. Missing or malformed markers cannot bypass legacy mode,
and read-only routing never writes or repairs state.

The marker contract does not itself authorize writes.

The next write-safety checkpoint establishes storage primitives without
exposing an apply command:

- retained legacy payloads preserve explicit existence and every ordered value
  for Slides, theme, and short URL metadata, including duplicate rows;
- typed canonical encoding and a persistent, non-autoloaded site secret provide
  domain-separated integrity hashes without relying on rotating WordPress salts;
- the secret's read path remains zero-write, and creation is reserved for an
  explicit future preparation operation;
- one non-autoloaded option per deck provides atomic lock acquisition, exact
  compare-and-swap expiry takeover, and ownership-safe release;
- append-only backup envelopes are immediately reread and verified and expose
  only a content-free reference;
- append-only journal events form a verified hash chain across characterized
  apply and restore states, with exact retries remaining idempotent; and
- snapshot fingerprints now include metadata existence, duplicates, and row
  order rather than only each single-value metadata projection.

These primitives issue no content, routing, or legacy metadata changes on their
own. Revision creation, preparation orchestration, content verification,
cutover, restore, and their explicit WP-CLI commands remain required before the
first migration apply command is exposed.

## Preparation checkpoint

`wp presenter migration prepare <post-id>` now creates the safety artifacts for
one explicitly selected, ready legacy deck. While holding an expiring per-deck
lock, it rejects active WordPress edit locks, captures and verifies an exact
revision of the post fields, creates or reuses the source's immutable verified
backup, rechecks the source after renewing the lock, and appends a verified
`apply_prepared` journal event. An exact retry is idempotent and creates no new
artifacts.

`wp presenter migration status <post-id>` provides the corresponding zero-write
inspection path. Its schema reports only content-free state and capabilities;
it does not create the signing secret or disclose authored values, hashes,
references, revision or backup IDs, option names, or lock tokens.

Preparation does not write block content, change the deck-mode marker, remove
legacy metadata, or authorize public routing to the native runtime. Apply,
post-write verification, final cutover, restore, and their failure-state tests
remain required before migration can affect a deck's published representation.

## Apply transaction contract

The apply operation authorizes itself from the verified private journal and
backup while holding the migration lock; public status is descriptive and is
not an authorization boundary. Immediately before writing, apply must recheck
the exact prepared source, retained metadata, revision, backup, planner version,
empty deck-mode metadata, active WordPress edit lock, and migration-lock
ownership.

Because WordPress edit locks are advisory, a normal `wp_update_post()` cannot
prevent an editor save between the final check and the migration write. The
content writer uses one isolated, byte-exact compare-and-swap from the
prepared original content and post fields to the prepared target content. It
changes no other authored field and immediately clears and rereads WordPress's
post cache. A lost comparison means another writer won and migration performs
no content mutation.

After the content write, apply must verify the target hash, the shared native
Deck/Slide structural contract, unchanged non-content source fields and retained
metadata, the original revision and backup, and renewed lock ownership. Only
then may it add exactly one private `native` marker. An exception-free
conditional database insert prevents post-insert hooks from losing ownership
evidence. The marker is the final representation mutation and must itself be
reread as the only stored mode row before the journal advances to `applied`.

Failures before the content comparison leave `apply_prepared` unchanged.
Failures after a successful content write remove only a marker created by that
attempt, restore content with the inverse byte-exact comparison, and verify the
legacy route before appending `apply_rolled_back`. If safe compensation cannot
be proven, the journal advances to terminal `recovery_required` for manual
recovery. A safely rolled-back attempt may prepare again under a new attempt ID.

Interrupted states are resumed from verified storage rather than guessed from
the last command response. In particular, `apply_prepared` with verified target
content and no marker resumes at cutover; the same state with verified target
content and one exact native marker completes the durable `applied` event. Any
modified content, malformed marker set, or inconsistent artifact fails closed.

## First apply checkpoint

`wp presenter migration apply <post-id>` implements this transaction for one
explicitly prepared deck. The production writer preserves timestamps and every
non-content post field, bypasses ordinary save hooks, clears WordPress's post
cache, and performs an uncached exact readback. This narrowly isolated direct
database operation is required because WordPress provides no conditional post
update API and its edit locks are advisory.

The command resumes characterized crashes at verified target content before or
after cutover, emits only content-free JSON, returns nonzero for operational
failure, and is footprint-idempotent after success. Status explicitly
distinguishes interrupted-before-cutover, interrupted-after-cutover, missing
applied cutover, and malformed applied cutover states. Recovery journal writes
require renewed ownership of the per-deck lock.

This checkpoint retains all legacy metadata and the immutable backup/revision.

## Restore checkpoint

`wp presenter migration restore <post-id>` is the explicit inverse transaction.
It accepts only the same verified attempt in `applied` or `restore_prepared`,
acquires and renews the per-deck lock, refuses an active WordPress edit lock,
and independently revalidates the journal, immutable backup, source revision,
retained legacy metadata, and non-content post fields.

Restore appends `restore_prepared` before its first representation mutation.
It then removes the exact native marker with one metadata-ID, post-ID, key, and
byte-value-scoped conditional delete. Only after verifying target content on
the legacy route does it perform the inverse byte-exact content comparison.
The final `restored` event is appended only after the original content, legacy
route, and retained artifacts reread exactly.

Crashes resume from three safe persisted representations: target content with
the native marker, target content without it, or original content without it.
Modified content, ambiguous markers, changed post fields or retained metadata,
and invalid artifacts fail closed. Recovery journal writes require renewed
lock ownership, while safe interrupted states remain retryable.

## First authenticated admin checkpoint

The first wp-admin slice deliberately exposes preparation but not cutover. A
shared `Legacy_Deck_Inventory` now owns the direct, filter-independent query
used by both WP-CLI and the admin tool. It enforces a maximum of 100 IDs per
query and keeps password-protected decks visible to authorized maintenance
users even when site archive filters hide them.

Tools → Presenter Migration renders at most 20 decks per request. The GET path
is zero-write and shows only post identity plus content-free plan and journal
classifications and whether preparation is currently available. It never
renders slide content, private artifact references, hashes, or lock values.
Rows are additionally filtered through the
current user's per-post edit capability. `manage_options` is the screen's
complete visibility boundary; the per-post check is an additional mutation
guard, not a promise to conceal aggregate inventory counts from site
administrators with custom roles.

Preparation accepts exactly one slideshow through `admin-post.php`. It requires
POST, `manage_options`, the specific post's edit capability, and a nonce bound
to both the prepare operation and post ID. The response redirects with one
fixed result code rather than serialized service output. Apply, restore, and
client-chained batches remain absent from this checkpoint; they will reuse the
same verified services only after this request boundary is proven.

## First admin apply checkpoint

The next wp-admin slice exposes native cutover for exactly one prepared deck per
request. Apply has its own operation-and-post-scoped nonce and repeats the POST,
`manage_options`, slideshow type, and per-post edit-capability boundary used by
preparation. A labeled, required confirmation checkbox explains that active
post content and the public renderer will change; the server independently
requires its exact confirmation value.

The admin adapter calls the same verified `Migration_Applier` used by WP-CLI.
It does not authorize from the displayed status or introduce another state
store. Fixed result classifications distinguish verified apply, verified apply
with a lock-cleanup warning, safe rollback to legacy, recovery-required state,
failure while the exact original legacy representation remains proven, and an
indeterminate manual-review state that makes no claim about the active
representation. Only those fixed codes reach redirect notices; authored content
and private migration values do not.

The safe-rollback and proven-legacy failure notices require the exact legacy
mode, original content, matching prepared source and retained metadata, and a
verified backup and revision. A rollback additionally requires the expected
`apply_rolled_back` result and clean lock release. Any drift or contradictory
diagnostic is classified for manual review instead of inheriting confidence
from the journal state alone.

Integration tests prove the prepared Apply form is zero-write and redacted, a
valid request changes exactly one deck, the retained legacy payload remains,
the final native representation is restorable, and exact retries add no state.
Missing or malformed confirmation, a Prepare or wrong-post nonce,
migration-lock contention, and an active WordPress edit lock cannot cut over the
deck. Redirects preserve a validated, bounded inventory page. Admin Restore and
client-chained batches remain separate later checkpoints.

## First admin restore checkpoint

The next wp-admin slice exposes the verified inverse transaction for exactly
one applied or resumable deck. Restore has a distinct operation-and-post-scoped
nonce, repeats the authenticated mutation boundary, and requires the exact
`restore` confirmation value. The form explains that the verified legacy
content and renderer will become active again; interrupted safe states expose a
separate Resume restore label.

The admin adapter delegates to the same `Migration_Restorer` used by WP-CLI.
Its persisted-state-first classifier recognizes only the exact restored legacy
representation, the exact applied native representation, or one of the three
verified resumable restore representations. Lock contention can make a proven
resumable state temporarily unavailable without misclassifying it as data
ambiguity. Modified content, invalid routing markers, changed retained metadata
or post fields, and invalid safety artifacts fail closed for manual review.

Redirect notices use a short-lived HMAC receipt bound to the fixed result code,
post ID, expiry, and current administrator. Every receipt is rechecked against
fresh content-free status before rendering. Forged, expired, cross-user, and
stale receipts cannot produce success; cleared lock warnings are canonicalized
to the currently verified success state. Receipt URLs contain no authored
content, artifact identifiers, hashes, or lock values, and rendering remains
zero-write.

Integration tests cover exact one-deck restore, retained safety artifacts,
idempotent replay, scoped nonce and confirmation rejection, all resumable
representation tuples, forged and stale receipts, current-user binding, and
zero-write redacted rendering. Client-chained batches remain a later
checkpoint.

## First client-chained admin checkpoint

The first batch-capable wp-admin slice intentionally chains preparation only.
An administrator explicitly selects freshly eligible decks from the current
20-row inventory page. The browser freezes that rendered selection, removes
duplicate post IDs, and submits one request at a time through a dedicated
authenticated AJAX transport. Each item still passes the existing POST,
`manage_options`, per-post edit capability, slideshow type, and
operation-and-post-scoped Prepare nonce boundary before calling the shared
`Migration_Preparer`.

There is no server-side bulk loop, batch transaction, persisted queue, offset
cursor, background task, or cross-page selection. The per-deck migration
journal remains the only durable truth. Closing or reloading the page loses only
ephemeral progress; already prepared decks are safe and idempotent, and the
fresh screen exposes only decks that remain eligible. The client never retries
automatically and stops before the next deck after a fixed non-clean response,
invalid JSON, HTTP or network failure, or an explicit Stop request.

The JSON response is an exact content-free allow-list containing only schema,
Prepare operation, and `prepared` or `stopped`. It contains no authored data,
post ID, service diagnostics, hashes, artifact references, lock values, or
nonces. Existing single-deck forms remain functional without JavaScript. Apply
and Restore are not chained: batch Apply requires a later, separately reviewed
confirmation and preflight workflow, and preparation never changes a published
representation.

PHP and JavaScript tests prove page boundedness, fresh eligibility, redaction,
zero-write rendering, exact response shape, deterministic deduplication,
single-request concurrency, cancellation, and stop-on-failure behavior. A real
command-line Playwright gate selects three disposable legacy decks beside an
unselected neighbor, injects a fixed failure into item two, proves item three is
not requested, reloads, resumes the two remaining items, and proves every
request remained serial. An independent verifier confirms all three selected
decks are prepared while the neighbor and every public legacy route are
unchanged.

## First rendered-parity checkpoint

Presenter 1.x supplied each complete `<section>`, including speaker notes, to
WordPress before the default `wpautop()` stage. A native block tree causes
`do_blocks()` to suppress that stage for the current `the_content` pass. The
migration planner therefore marks only generated Slides with an internal
`legacyAutoParagraph` attribute. Their render callback applies `wpautop()` to
the completed section; ordinary authored Slides retain normal block behavior.
Custom HTML is serialized immediately inside the Slide boundary because a
formatting newline adjacent to bare text becomes observable DOM. This target
change increments the planner contract to version 2 so older prepared content
cannot be applied.

## Typed legacy background shorthand

Presenter 1.x accepted Reveal's historical `data-background` shorthand. When
its value is a validated presentation resource URL, planner version 3 maps it
to the native Slide `backgroundImageUrl` attribute so the image is visible and
editable in the block editor. Non-resource shorthand remains generic to avoid
changing Reveal semantics. Incrementing the planner contract invalidates older
prepared content for any new apply rather than applying a representation that
omits the typed editor setting. A signed transaction that was already applied
remains verifiable and restorable with its stored planner version: restore
reverses that historical transaction and does not run the current planner.
After reaching a terminal restored or apply-rolled-back state, preparation is a
new attempt built from the current legacy source under the current planner.

## Complete-slide native conversion extensions

Planner version 4 adds the `presenter_migration_slide_blocks` filter before
the lossless Custom HTML fallback. A converter may claim an entire legacy
slide by returning a non-empty parsed block list; returning `null` leaves the
fallback unchanged. Presenter rejects incomplete block shapes and results that
do not survive a parse-and-serialize round trip. A converted slide disables
whole-section legacy paragraph processing while retaining the historical notes
processing order. Migration reports identify these slides as `native-blocks`
and count them separately from Custom HTML fallbacks.

The private Aaron theme companion uses this extension to recognize its
characterized Google Charts grammar without executing legacy JavaScript and to
emit native `presenter/chart` blocks. Advancing the planner contract prevents a
prepared version 3 target from being applied after this representation change;
already-applied signed transactions remain eligible for exact restoration.

The native presentation template also enters the standard WordPress Loop before
applying `the_content`. This is required for Core's content-image loading and
fetch-priority heuristics and benefits migrated and newly authored decks alike.
The synthetic comparison fixture covers multiline content, quote-sensitive
plain and Markdown notes, fragments, and a content image. The first
production-derived canary moved from zero to all 22 Slides matching
structurally. A pristine full-corpus run completed all 65 migrations and exact
restores with zero failures. It reduced the residual from 42 decks and 203
Slides to 15 decks and 17 Slides, all caused by Marked 4/18 differences around
unblanked list/heading boundaries.

Migrated notes now preserve the legacy filter order by texturizing before the
speaker-notes policy escapes or sanitizes them. Migrated Markdown notes alone
are then rendered with the exact Marked 4.0.12 grammar used by Reveal 4 before
Reveal 6 initializes; newly authored notes retain Reveal 6's current parser.
The compatibility parser escapes raw HTML tokens, and the migration planner
continues to block unsafe legacy HTML notes. A targeted replay of all 15
formerly failing decks produced zero structural differences across their 17
residual Slides. Fragment parity remains complete across the captured corpus.
The legacy runtime and classic editor now project heterogeneous stored values
through this same normalizer. This removes the PHP 8.3 failure previously caused
by the corpus's boolean `false` row while leaving its stored metadata unchanged.
All 60 public decks complete structural capture; five protected/nonpublic decks
are skipped, and incomplete local visual assets remain tracked separately from
structural parity.

The comparison coordinator treats an authenticated `access_skipped` sidecar as
terminal after restore. This state is finalized as `access_not_captured`; it
does not require the `native_captured` state reserved for public decks.

# Development tooling

Presenter 2.0 uses locked Composer and npm dependencies. Run `composer install`
and `npm ci` after cloning.

## Required checks

```sh
composer run check:validate
composer run check:normalize
composer run lint
composer run compat
composer run phpcs
composer run phpstan
composer run check:audit

npm run lint
npm run test:unit
npm run test:php
npm run build
npm run test:runtime
npm run test:native-runtime
npm run test:editor-runtime
npm run test:core-blocks-runtime
npm run test:navigator-runtime
npm run test:migration-comparison
npm audit --omit=dev
```

Run `npm run plugin-check` against the active local wp-env site when checking
WordPress.org rules. The command excludes source-only and test directories;
the development-tree run also excludes the `file_type` check because packaging
metadata is intentionally present. The release-candidate gate will run every
check against the assembled distributable plugin instead. Presenter 1.x
currently has inherited Plugin Check findings in its monolithic PHP and legacy
templates, so those must be removed as the 2.0 runtime replaces legacy code.
The clean environment pins Plugin Check 2.0.0 so this advisory baseline does
not depend on mutable container state.

PHPCompatibility and PHP syntax cover all current PHP, including the Presenter
1.x implementation. WPCS and PHPStan gate new Presenter 2.0 code immediately.
The legacy `presenter.php` and templates have substantial pre-existing standards
and type debt; they are not declared clean or suppressed through a generated
baseline. They remain under characterization and compatibility tests and will
enter WPCS/PHPStan scope as responsibilities move into the new class structure.

The npm lock currently contains audit findings in development-only transitive
dependencies of the WordPress 7.0 `@wordpress/scripts` toolchain. The installable
plugin has no npm runtime dependencies, and `npm audit --omit=dev` is clean.
Do not run `npm audit fix --force`: it replaces the WordPress 7.0-aligned
toolchain with a breaking release. Re-evaluate the full audit whenever the
WordPress release-aligned tooling is updated.

## Version alignment

-   WordPress runtime: 7.0.1.
-   PHP runtime floor: 8.3.
-   Node.js: 24.15.0; npm: 11.12.1.
-   `@wordpress/scripts`: 31.7.0.
-   `@wordpress/env`: 10.39.0.
-   WordPress packages used by the editor: `@wordpress/block-editor` 15.13.2,
    `@wordpress/blocks` 15.13.1, `@wordpress/components` 32.2.1,
    `@wordpress/data` 10.40.1, `@wordpress/editor` 14.40.1,
    `@wordpress/element` 6.40.1, `@wordpress/i18n` 6.13.1,
    `@wordpress/plugins` 7.40.1, and `@wordpress/url` 4.40.1.
-   `@wordpress/e2e-test-utils-playwright`: 1.42.0; `@playwright/test`:
    1.58.2.
-   PHPUnit: latest 9.6 release, because the WordPress 7.0 integration framework
    still uses PHPUnit APIs removed in PHPUnit 10 and newer.
-   Reveal.js source dependency: 6.0.1.

`npm run build` compiles the Presenter front-end entry and then copies the
pinned Reveal.js base stylesheet, bundled themes, upstream license, and source
version metadata into `build/reveal/`. Reveal plugins are emitted as on-demand
webpack chunks from the same pinned npm package. A clean build must reproduce
the committed runtime assets without fetching mutable upstream files.

The current default native configuration requests all six bundled Reveal
plugins. Although they are split from the initial bundle, the emitted highlight
plugin chunk is roughly 897 KiB uncompressed and triggers webpack's asset-size
warning. This is a tracked performance follow-up, not a reason to raise or
disable the warning: select plugins from actual deck features and measure
compressed transfer and initialization cost before the release gate.

WordPress-facing npm packages must be added as explicit direct dependencies at
the versions associated with WordPress 7.0. Do not rely on whichever transitive
version happens to be installed by the build tools.

## Native authoring runtime checks

With the clean development environment running, `npm run test:native-runtime`
creates a deterministic local fixture and verifies the actual WordPress route,
Reveal initialization, direct Slide sections, configured dimensions and margin,
navigation booleans, transitions, exact background attributes, theme loading,
Markdown notes, hidden-slide exclusion, and navigation. `npm run
test:editor-runtime` signs into the local block editor; authors aspect ratio,
custom dimensions, navigation, transition, theme, label, anchor, and background
settings through visible Inspector controls; verifies preset behavior and undo;
saves and reloads Heading, Paragraph, Group, Columns, List, Code, Image,
Buttons, Accordion, Shortcode, and Latest Posts blocks; checks block validity
and persisted attributes; and deletes the temporary post. The editor also
identifies hidden Slides, reports invalid background inputs with
`aria-invalid`, and prevents authors from disabling both visible controls and
keyboard navigation.

`npm run test:navigator-runtime` creates a disposable 60-slide deck and opens
the supported Slides plugin sidebar. It verifies `BlockPreview` thumbnails,
ordered-list/navigation semantics, explicit and derived labels, hidden state,
selection, add, duplicate, delete, hide, keyboard movement, native HTML5
drag/drop, a unique anchor for every duplicate, and one-step undo after every
mutation. It selects the final slide to exercise the large-deck path, deletes
the fixture, and fails on page, console, or duplicate-registration errors.

`npm run test:core-blocks-runtime` creates a deterministic published deck and
verifies representative static, nested, media, interactive, shortcode, and
server-rendered blocks through the real WordPress route. In particular, it
exercises the WordPress 7 Accordion Interactivity API, confirms dynamic Latest
Posts output, and proves that Reveal navigation still works after interaction.
The native runtime check additionally verifies the skip link's keyboard focus
path to its `tabindex="-1"` target, accessible Slide labels, and a single
viewport declaration. All three commands run Chromium headlessly and fail on
page or console errors. PHP integration coverage separately restores an older
revision, verifies that its Deck and Slide settings drive Reveal configuration
and rendered attributes, and proves a synthetic dynamic block render callback
executes exactly once.

Theme options are serialized from the filtered PHP registry before the shared
editor bundle; the editor does not duplicate the built-in list or default-theme
logic. It fetches the resolved stylesheet and scopes it beneath the Presenter
preview wrapper with WordPress `transformStyles`. The preview uses
Reveal-compatible wrappers without Reveal's base layout CSS, and applies
validated Slide background color/image values inline. Stylesheet requests are
cached by URL, failed requests are removed for a later retry, and authors see a
non-blocking warning when preview CSS is unavailable.

The committed wp-env configuration pins an immutable public companion-plugin
commit as an external test fixture. The ignored `.wp-env.override.json` may
instead mount the editable sibling `../aarondcampbell-presenter-themes`
checkout for local integration. Neither configuration copies the companion or
its site-specific `aaron-purple` theme into Presenter, makes it a runtime
dependency, or includes it in Presenter release packaging. The companion
registers the stable `aaron-purple` theme ID and selects it as the modern site
default while retaining its Presenter 1.x hooks. The editor and core-block
headless checks compare computed theme styles and inline backgrounds between
the editor and published presentation for bundled and external companion
themes.

At the Milestone 5 navigator checkpoint, the integration suite passes 88 PHP
tests with 421 assertions and the JavaScript suite passes 48 tests. These counts
are a checkpoint record, not a reason to avoid adding coverage.

At the Milestone 6 advanced-behavior checkpoint, the integration suite passes
128 PHP tests with 567 assertions and the JavaScript suite passes 83 tests.
The editor runtime additionally saves and reloads visible fragment and advanced
Slide controls, while `test:m6-runtime` covers Reveal navigation, auto-animate,
print/PDF, and speaker-view behavior through the real WordPress route.

The first Milestone 7 checkpoint adds a real `test:migration-dry-run` WP-CLI
gate. It covers bounded discovery, deterministic content-free ready/blocked
reports, numeric slide ordering with source-order tie breaking, Custom HTML
fallback accounting, JSON envelope validation, and exact pre/post source
fingerprint comparisons. The synthetic source values and private fingerprints
are kept in the local WordPress environment and are not committed or printed.

At this checkpoint the full PHP integration suite passes 138 tests with 687
assertions. The JavaScript suite remains at 83 tests because this slice contains
no browser-side migration code.

The second Milestone 7 representation checkpoint passes 149 PHP tests with 745
assertions and 88 JavaScript tests. The real editor and native presentation
headless gates also pass with no invalid blocks, console errors, or page errors.

The third Milestone 7 representation checkpoint passes 159 PHP tests with 783
assertions and 88 JavaScript tests. `test:migration-representation-runtime`
adds real WordPress and Reveal.js coverage for canonical legacy vertical stacks,
vertical navigation, horizontal exit, print inclusion, allow-listed HTML notes,
and Markdown processing within allow-listed HTML notes. Existing editor, native
presentation, and Milestone 6 runtime gates remain green.

The fourth Milestone 7 representation checkpoint passes 165 PHP tests with 822
assertions and 88 JavaScript tests. `test:legacy-section-parity` compares the
same opaque nested-section fixture in Reveal 4.3.1 and Reveal 6, including slide
discovery, current-slide selection, available routes, blocked downward movement,
and horizontal exit. The real WP-CLI zero-write, migration representation,
editor, native presentation, and Milestone 6 runtime gates all remain green.

The Milestone 7 write-safety foundation passes 175 PHP tests with 872
assertions and 88 JavaScript tests. One deck-mode resolver now owns the legacy
and native route boundary. Retained legacy metadata remains authoritative until
an exact private native cutover marker exists; malformed or duplicate markers
fail safely, and the old editor and save handler remain disabled after cutover.
No apply command or request-time writer exists at this checkpoint.

The Milestone 7 persistence-safety checkpoint passes 216 PHP tests with 1,116
assertions and 88 JavaScript tests. It adds exact retained-metadata capture,
typed canonical hashing, zero-write secret reads, atomic expiring locks,
append-only verified backups, and an immutable hash-chained state journal.
Snapshot fingerprints now detect duplicate theme and short-URL rows. These are
storage foundations only: no command writes slideshow content, changes deck
mode, or exposes authored values, hashes, lock tokens, or backup payloads.

The Milestone 7 preparation checkpoint passes 260 PHP tests with 1,901
assertions and 88 JavaScript tests. `test:migration-prepare-status` exercises
the registered WP-CLI commands against deterministic local fixtures. It proves
status is zero-write, preparation creates one exact revision, verified backup,
and `apply_prepared` event, exact retries do not add artifacts, rejected decks
return nonzero, and every command response remains content-free. No native
content writer, route cutover, or restore command exists at this checkpoint.

The Milestone 7 apply-prerequisite checkpoint passes 282 PHP tests with 1,989
assertions and 88 JavaScript tests. It adds exact marker-row ownership,
attempt-neutral verified backup recovery reads, resumable new attempts after a
safe rollback, immutable same-attempt journal context, and one native Deck
structure validator shared by routing and future post-write verification.
Malformed or duplicate deck-mode rows now fail preparation even though public
routing correctly falls back to legacy mode. No apply command exists yet.

The first Milestone 7 apply checkpoint passes 314 PHP tests with 2,803
assertions and 88 JavaScript tests. `test:migration-apply` exercises the real
registered prepare/apply/status commands and proves exact native block content,
singleton cutover, retained source metadata, nonzero failure, content-free
output, and footprint-idempotent reruns. Integration tests additionally force
pre-write races, edit locks, post-write exceptions, owned-marker cleanup,
third-party compensation conflicts, crash resume, and terminal recovery. The
restore command remains intentionally absent at that checkpoint.

The restore and full-corpus rehearsal checkpoint passes 347 PHP tests with
4,336 assertions and 88 JavaScript tests. It adds `test:migration-restore`,
which exercises the real
registered prepare/apply/restore/status commands. It proves exact recovery of
the original legacy content and route, retained source metadata and immutable
safety artifacts, content-free output, nonzero failure, crash-resumable
intermediate representations, and footprint-idempotent reruns. PHPUnit covers
edit and migration lock contention, marker and content races, artifact and
source tampering, observer failures at every mutation boundary, and terminal
recovery behavior.

Before production-derived rehearsal, `snapshot:bootstrap` rebuilds the isolated
port-8890 database from verified sources, sanitizes accounts and URLs, activates
only Presenter and the external private theme plugin, and proves the documented
safety controls. Its start wrapper binds every published snapshot container
port to loopback and fails closed if Docker reports otherwise. The ignored
`local/` tree is denied over HTTP, while credentials and keyed-verification
material live outside the repository and web root. `snapshot:preflight` is a
separate zero-write, content-free gate that must pass immediately before any
corpus mutation. Its resume-safe mode repeats isolation and source continuity
without rejecting the expected in-progress artifacts. Never reuse an old
snapshot database for migration acceptance merely because its containers are
still running.

The first production-derived canary uses the smallest published deck. It proves
ready planning, idempotent prepare/apply/restore commands, HTTP 200 responses
through both Reveal 6 native and Reveal 4 legacy routes, and an exact keyed
authored-state fingerprint before migration versus after restore. The pristine
snapshot database is exported only to ignored local storage before this write.

The serialized full-corpus rehearsal passes all 65 legacy decks with zero
failures. For every deck it captures a keyed direct-database baseline, verifies
idempotent prepare/apply/restore transactions, checks native and legacy HTTP
behavior, preserves every pre-existing revision plus the signed migration
revision, and requires exact authored-state restoration before advancing. The
run discovered and now covers WordPress metadata unslashing of backslashes in
nested arrays and object properties. It separately records one pre-existing
legacy PHP 8.3 server error and the protected deck's empty anonymous response;
both migrate successfully without content leakage and restore to their exact
baseline behavior.

The first authenticated admin checkpoint passes 359 PHP tests with 4,423
assertions and 88 JavaScript tests. The bounded inventory is shared by WP-CLI
and wp-admin and remains deterministic despite public query filters. The
server-rendered Tools screen proves zero-write, content-free rendering and
per-post nonce scoping. Integration tests prove GET, unauthorized POST, and
mismatched-nonce requests create no migration artifacts. This checkpoint
exposes one-deck preparation only; apply, restore, and chained admin batches
remain intentionally unavailable until the authenticated request boundary is
extended and tested.

The first admin apply checkpoint passes 365 PHP tests with 4,521 assertions and
88 JavaScript tests. A prepared deck exposes one explicit, confirmed Apply form
whose nonce cannot authorize Prepare or another deck. The successful-path gate
proves verified native content and routing, retained legacy metadata, restore
capability, one-deck request scope, and exact replay idempotence. Migration-lock
and WordPress edit-lock contention leave the prepared legacy representation
unchanged. Fixed admin result classes preserve the distinction between applied,
verified apply with a lock-cleanup warning, safely rolled back, recovery
required, proven legacy failure, and an indeterminate manual-review state.
Malformed confirmation values fail closed, and bounded page context survives a
successful redirect. Restore remains WP-CLI-only at this checkpoint.

The first admin restore checkpoint passes 375 PHP tests with 4,617 assertions
and 88 JavaScript tests. Applied and verified resumable rows expose an explicit,
confirmed Restore or Resume restore form with its own operation-and-post nonce.
The successful-path gate proves exact legacy content and routing restoration,
retained safety artifacts, one-deck scope, and replay idempotence. Result
redirects carry short-lived, administrator-bound HMAC receipts and every notice
is checked against fresh persisted status; forged, cross-user, stale-success,
and stale-warning paths cannot make an obsolete claim. Exact resumable states
remain distinguishable from representation ambiguity during lock contention.
Chained admin batches were intentionally unavailable at that checkpoint.

The first client-chained admin checkpoint passes 380 PHP tests with 4,682
assertions and 100 JavaScript tests. It adds a built admin-only runner for
Prepare-selected, bounded to freshly eligible decks on the current 20-row page.
The queue freezes and deduplicates the selection, submits one existing
nonce-scoped deck boundary at a time, supports Stop after current deck, and
never retries or advances after a non-clean, malformed, HTTP, or network
result. Its dedicated AJAX response is a fixed three-field content-free
allow-list. `test:migration-admin-batch` uses command-line Playwright plus an
independent persisted-state verifier to inject an item-two stop, prove the third
item was not requested, reload and resume the two remaining decks, and finish
three selected preparations strictly serially. The unselected neighbor and
public legacy content and routing remain unchanged. Apply and Restore remain
single-deck actions.

The authenticated batch Apply checkpoint passes 386 PHP tests with 4,728
assertions and 111 JavaScript tests. Prepared rows can enter a separately
confirmed, current-page queue that sends exactly one attempt-bound Apply
authorization at a time. The server derives the current verified journal
attempt when checking each nonce, then the applier rechecks that exact attempt
and prepared sequence under the per-deck lock before any write. An old or raced
form therefore cannot authorize a later restore-and-reprepare attempt. Exact
three-field JSON receipts expose only
`applied`, `applied-warning`, `stopped`, or `review-required`; malformed or
unknown transport outcomes stop without retry. The command-line Playwright
gate injects a stop on Apply item two, proves earlier success remains native,
reloads and resumes the two prepared decks, and verifies serial execution and
an untouched neighbor. Restore remains deliberately one-deck only.

## Migration comparison gate

`npm run test:migration-comparison` runs the unit contracts for capture,
normalization, exact structural comparison, pixel comparison, and the private
report schema before exercising one real legacy → native → restored fixture.
The headless gate requires two deterministic captures of each representation,
an exact normalized structural match, verified restore, and an unchanged
neighbor. Structural diagnostics are fixed codes only and cover runtime,
dimensions, semantic Reveal configuration, theme, hierarchy, Slide identity
and order, notes, fragments, data attributes, wrapper classes, and rendered
content.

The fixture deliberately includes bare multiline HTML, plain multiline notes,
fragment markup, and a content image. Planner-generated Slides carry one
private compatibility attribute that restores the whole-section `wpautop()`
stage used by Presenter 1.x; ordinary native Slides do not. Migrated Custom HTML
is serialized immediately inside its Slide boundary so formatting whitespace
cannot become authored text. The native presentation template enters the
standard WordPress Loop before applying `the_content`, preserving Core's normal
image loading and fetch-priority behavior for all native decks.

Visual status is intentionally stricter than a tolerance: zero differing RGBA
pixels passes, while any difference becomes `review_required`. Review-required
is an operator disposition, not an automatic failure or approval. A capture
error, nondeterministic repeat, unavailable local asset, external request,
console/page error, or local HTTP failure fails closed rather than producing a
visual pass.

The capture library accepts no default artifact directory. Callers must choose
an absolute path outside the repository or beneath its ignored `local/` tree;
the synthetic gate uses
`local/migration-comparison-gate/run-<opaque-id>/`. Screenshots and diffs remain
private raw artifacts. The atomically written JSON report uses an exact schema
containing only domain-separated HMACs, opaque deck/attempt/environment
identities, fixed codes, counts, access classes, and pixel statistics. It never
persists captured DOM or authored values. The synthetic key is random per run
and cleared from process memory after use, so report digests bind evidence
within that run rather than acting as unkeyed content fingerprints.

All browser requests must remain on the expected loopback origin, apart from
`about:`, `data:`, and same-origin `blob:` resources. The local fixture uses a
bundled theme and verifies the externally mounted companion themes plugin is
active and removes Presenter 1.x's RevealMath CDN dependency. This is local
test setup only; the companion plugin is not a Presenter dependency or release
payload. Real-corpus external fonts or embeds must be made available through an
explicit, reviewed snapshot-only strategy or reported as incomplete—not
ignored by the comparator. The private snapshot may provide
`local/snapshot/asset-substitutions.json`: an exact-schema manifest bound to
both authoritative source hashes. Missing same-origin JPG/PNG requests may map
only to an absent source's same-directory, same-stem AVIF counterpart. One
separate entry names exactly the legacy `?ver=7.0.1` and native
`?ver=2.0.0-dev` same-origin Aaron Purple stylesheet URLs. Both aliases resolve
to one canonical substitution identity and a digest-pinned offline copy that
removes its unavailable Google Fonts import. Reviewed staging-origin upload
references may also name an exact protocol-relative source token and its exact
archive path. Before Reveal initializes, capture rewrites only matching
`data-background`, `data-background-image`, and `data-background-video`
attributes on slide sections to same-origin snapshot paths, then serves the
digest-verified manifest bytes. The snapshot CSP remains unchanged. Every entry
pins its exact source URL or token, path, size, SHA-256, MIME type, and browser
resource type; magic bytes, path containment, and symlink boundaries are
verified, and unknown URLs still fail closed. The manifest and artifacts remain
ignored private data, while the capture tool and manifest digest are part of
the comparison identity.

WordPress canonical redirects are resolved before browser navigation. Capture
accepts at most one exact same-origin hop, rejects external or chained
redirects, and then navigates directly to the canonical URL. The browser
therefore retains the real `document.baseURI`, relative-resource resolution,
and Reveal history/hash behavior while the canonical document alone passes
through the reviewed rewrite boundary.

`npm run snapshot:rehearse -- --yes` applies the same digest-only comparison
contracts to the private 65-deck corpus. Per-deck atomic sidecars bind the
environment, ordered selection, access class, migration attempt, normalized
models, visual-selection policy, and private frame paths. Structural-only decks
write no screenshots. Representative visual decks fail closed on incomplete
assets. Each capture also authenticates the sorted canonical
entry-digest/usage-count list of substitutions it actually used. Archive-backed
background usage is counted from exact document rewrites rather than unstable
browser media-range requests;
primary/repeat and legacy/native acceptance require the same list. The
content-free report identifies original versus substituted snapshot bases,
distinct canonical-entry counts, and a keyed list digest. The final comparison
report is written only after every deck has been
restored and contains fixed codes, counts, HMACs, and pixel statistics rather
than post IDs or authored values.

Before every screenshot, an authored or Reveal background video on the active
slide must have decoded a frame, then be paused and settled at time zero. A
decode error, unavailable source, seek timeout, or in-progress seek fails the
capture. Manifest-backed media ranges are fulfilled from the verified bytes;
Chromium may cancel an already-satisfied Range while changing slides, so only
an exact `ERR_ABORTED` for a resolver-verified rewritten media target is
excluded from request-failure counts. Every slide is still activated and its
media must pass the decode, pause, seek, and frame-zero barriers or the capture
fails. The capture-only `play()` wrapper handles `AbortError` caused by that
intentional pause; every other playback rejection and unrelated media failure
remains a capture failure.

The acceptance manifest must name the authoritative database snapshot digest;
source verification, preflight, and the rehearsal coordinator consume one
shared immutable identity definition. Schema-v4 sidecars checkpoint four fixed
legacy/native primary/repeat slots, reject unauthenticated v2 evidence, and bind
each frame's bytes and metadata to its randomized run, deck, phase, role, and
location. Capture-level HMACs also protect normalized models and ordered frame
records, and a root checkpoint HMAC authenticates every resume decision field.
Visual repeat checks compare decoded RGBA exactly, while
structural-only repeats write no screenshots. Compared sidecars persist an
HMAC-bound, content-free report record, allowing final report generation to be
repeated without rerunning structural or visual comparison. Capture and schema
exceptions are normalized to fixed phase codes, while an incomplete asset set
preserves its structural evidence and reports `asset_failure`.

Nonpublic and protected decks checkpoint `access_skipped` and remain a no-op at
the post-restore comparison boundary. The finalized report converts that state
to `access_not_captured`; it must never require a native browser capture or
turn an otherwise verified migration/restore into a coordinator failure.

Deck and Slide metadata both reference the single `presenter-block-editor`
bundle. Do not split or duplicate that entry without a measured need. The Deck
render callback returns WordPress's already-rendered child content unchanged,
which ensures dynamic blocks execute once rather than being rendered a second
time by Presenter.

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
npm run test:e2e
npm run build
npm run test:runtime
npm run test:native-runtime
npm run test:editor-runtime
npm run test:core-blocks-runtime
npm run test:navigator-runtime
npm run test:plugin-selection-runtime
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

PHPCompatibility, PHP syntax, WPCS, and PHPStan cover all shipped PHP, including
the retained Presenter 1.x bootstrap and every standalone template. The public
lowercase `presenter` class, bootstrap filename, combined class/factory file,
and hyphenated 1.x hook names have narrow documented compatibility exceptions;
the rest of the legacy code has no PHPCS or PHPStan baseline. PHPStan uses a 2 GB
limit after expanding from the modern class tree to the complete shipped plugin.

The npm lock currently contains audit findings in development-only transitive
dependencies of the WordPress 7.0 `@wordpress/scripts` toolchain. Chart.js,
Reveal.js, and the aliased legacy Marked version are production dependencies
because their bytes are compiled into or copied into the installable plugin.
The plugin does not run npm in production, but this classification ensures that
`npm audit --omit=dev` audits every shipped JavaScript library; that audit is
clean. Do not run `npm audit fix --force`: it replaces the WordPress 7.0-aligned
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
    1.58.2; `@axe-core/playwright`: 4.12.1.
-   PHPUnit: latest 9.6 release, because the WordPress 7.0 integration framework
    still uses PHPUnit APIs removed in PHPUnit 10 and newer.
-   Reveal.js source dependency: 6.0.1.
-   Chart.js native runtime: 4.5.1.
-   Marked legacy-notes runtime: 4.0.12.

`npm run build` compiles the Presenter front-end entry and then copies the
pinned Reveal.js base stylesheet, bundled themes, upstream license, and source
version metadata into `build/reveal/`. Reveal plugins are emitted as on-demand
webpack chunks from the same pinned npm package. A clean build must reproduce
the committed runtime assets without fetching mutable upstream files.
Chart.js registers only the line/bar controllers, category/linear scales,
elements, and plugins used by the native Chart block. The editor includes that
runtime in its bundle; presentations request the named `chart.js` chunk only
when rendered markup contains a native Chart block.

`npm run release:build` and `npm run release:verify` also enforce the
WordPress.org source-and-license boundary. The assembled readme must link to
the public human-readable source and reproducible build instructions; the
third-party inventory must name each bundled component and version; and the
MIT, BSD-3-Clause, Reveal, and font license files must all be present inside
the ZIP. Source-only dependency trees remain outside the installable package.

Native configuration selects built-in Reveal plugins from rendered deck
features. Search, Notes, and Zoom remain the baseline. Markdown is requested
only when rendered markup contains `data-markdown`; Highlight is requested for
Markdown or a `code` element. Explicit plugin arrays and the filtered result
remain authoritative, so extensions can append or remove registered IDs. Math
remains available to extensions but is not a global default.

`npm run test:plugin-selection-runtime` verifies this through five real
WordPress routes. The plain fixture omits the 918,688-byte uncompressed
Highlight chunk measured in the current production build, while the Markdown
and native Code fixtures load it. A historical chart-fragment deck loads only
the companion bridge; a native Chart-block deck loads the 160,202-byte named
Chart.js chunk and proves the canvas is painted. Plain and historical-chart
decks each load 241,432 bytes of emitted Presenter scripts without that chunk.
The check asserts configuration IDs, waits for Reveal initialization, permits
registered extension IDs, measures emitted Presenter script responses, and
fails on browser errors. Webpack's asset-size warning remains enabled because
decks that use Highlight still require that large optional payload.

WordPress-facing npm packages must be added as explicit direct dependencies at
the versions associated with WordPress 7.0. Do not rely on whichever transitive
version happens to be installed by the build tools.

## Native authoring runtime checks

`npm run test:e2e` creates or updates a deterministic native deck in the
disposable WordPress test site and verifies its signed-out public route in
Chromium, Firefox, and WebKit. The gate exercises Reveal readiness, reduced
motion, document metadata, live status, Slide labels, hidden-Slide exclusion,
skip-link focus, keyboard navigation, browser errors, and automated WCAG 2 A/AA
and WCAG 2.1 A/AA rules. Install the three pinned Playwright browser engines
with `npx playwright install chromium firefox webkit` before running it locally.

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

`npm run test:plugin-selection-runtime` creates plain, Markdown-note, Core Code,
historical chart-fragment, and native Chart-block decks. It confirms each
receives only the built-in plugin subset its rendered markup requires, verifies
that Highlight is absent from the plain deck and present for the two
syntax-aware decks, and keeps the companion chart bridge distinct from the
native Chart.js payload.

`npm run test:navigator-runtime` creates a disposable 60-slide deck and opens
the supported Slides plugin sidebar. It verifies `BlockPreview` thumbnails,
ordered-list/navigation semantics, explicit and derived labels, hidden state,
selection, add, duplicate, delete, hide, keyboard movement, native HTML5
drag/drop, a unique anchor for every duplicate, and one-step undo after every
mutation. It selects the final slide to exercise the large-deck path, deletes
the fixture, and fails on page, console, or duplicate-registration errors.
This real editor flow also proves the post type's `template_lock: all` protects
only the single root Deck: the Deck's own explicitly unlocked Slide area accepts
the visible Add and Duplicate actions.

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
logic. Native blocks use a scoped stylesheet beneath the Presenter preview
wrapper. Migrated Custom HTML slides use a script-disabled, no-referrer iframe
with the complete single-slide Reveal hierarchy, the rebased selected-theme
stylesheet, authored deck dimensions, and uniform scaling. It applies validated
typed Slide backgrounds and understands image-valued `data-background`
shorthand from older migration plans. Trusted extensions may add persistent
preview markup through `presenter_editor_preview_footer`; the companion theme
plugin uses that seam for the same footer it adds to presentations. Stylesheet
requests are cached by URL, and failed requests are removed for a later retry.

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
nested arrays and object properties. The corpus also exposed a legacy PHP 8.3
server error caused by a stored boolean `false` Slide row. The
legacy renderer and classic editor now share the migration normalizer's
detached runtime projection, which preserves that row as its deterministic
empty Slide without changing stored metadata. The rehearsal permits no legacy
HTTP exception. It separately records the protected deck's empty anonymous
response and verifies migration and exact restore without content leakage.

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
active. Presenter itself no longer registers the historical CDN-backed
`RevealMath` integration. The companion plugin is not a Presenter dependency or
release payload. Real-corpus external fonts or embeds must be made available
through an explicit, reviewed snapshot-only strategy or reported as
incomplete—not ignored by the comparator. The private snapshot may provide
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

Two exact historical chart-library URLs have a narrower snapshot-only path.
The saved Chart.js 3.5.1 CDN URL is rewritten on slideshow responses to the
same version pinned in `package-lock.json`; preflight verifies the installed
bytes. The saved Google Charts loader URL is rewritten to a digest-pinned,
independently authored same-origin LineChart compatibility renderer because
the [Google Charts FAQ](https://developers.google.com/chart/interactive/faq)
does not permit local hosting of its runtime. That renderer covers only the
corpus API surface and establishes a deterministic functional rendering basis,
not Google pixel parity. The CSP and browser request allowlist remain unchanged,
unrelated script URLs remain blocked, and the rewrite, mount, and renderer
sources contribute to the comparison environment identity.

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
browser media-range requests. Primary/repeat and legacy/native acceptance
require the same verified entry set; request-count differences remain
authenticated diagnostics but do not change that byte basis. The
content-free report identifies original versus substituted snapshot bases,
distinct canonical-entry counts, and a keyed entry-set digest. The final
comparison report is written only after every deck has been
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

## Opt-in native content conversion

The block editor exposes **Convert legacy slides to blocks** on a migrated Deck
and **Convert to blocks** on an individual retained-HTML Slide. Conversion runs
Core's canonical raw handler after the historical paragraph stage, maps Reveal
fragment metadata, and retains unsupported markup as Custom HTML. Saving the
post creates the normal WordPress revision boundary; conversion never runs on
editor load or an ordinary migration Apply.

For the private local snapshot only,
`npm run snapshot:convert-native-blocks` drives that explicit editor action for
the post selected by `PRESENTER_SNAPSHOT_NATIVE_POST_ID`. It is state-changing,
requires the local administrator password environment variable, and must never
target a remote URL. `npm run test:snapshot-converted-editor` is read-only and
proves the saved Deck has 23 valid Slides, no whole-section legacy paragraph
flags, preserved legacy-notes processing, and no block-validation errors.

# Local development

Presenter uses separate environments for clean development, automated tests,
and private production-snapshot migration rehearsals.

## Prerequisites

-   Docker Desktop using the WSL2 backend on Windows.
-   Node.js 24.15 and npm 11.12, as pinned by `.nvmrc` and `package.json`.
-   PHP 8.3 or newer and Composer 2 for host-side quality checks.

Docker Desktop 4.82 or newer is installed on the audited Windows development
computer. Start Docker Desktop and wait for `docker info` to succeed before
running wp-env commands.

## Clean development site

The committed `.wp-env.json` pins WordPress 7.0.1, PHP 8.3, and an immutable
public commit of `aarondcampbell-presenter-themes` as an external integration
fixture. wp-env downloads that separate plugin only for development and tests;
it is not copied into Presenter, required at runtime, or included in a Presenter
release package. Its `aaron-purple` theme is Aaron's site-specific theme rather
than a bundled Presenter theme.

```sh
npm install
npm run env:start
npm run wp:cli -- plugin list
```

Open `http://localhost:8888/wp-admin` and sign in with the wp-env defaults:
`admin` / `password`.

A quick reference for this login and the private snapshot login is available in
the workspace root at `WORDPRESS-LOCAL-LOGINS.md`.

To mount the sibling Aaron themes plugin, copy
`.wp-env.override.example.json` to `.wp-env.override.json`. The override remains
local and ignored. The plugin array is repeated intentionally because wp-env
replaces arrays instead of merging them. wp-env mounts the sibling checkout
directly, so edits to its stable `aaron-purple` registration and modern default
filter are immediately available to Presenter integration and headless tests;
do not copy that plugin into this repository or commit the local override.

## Disposable test site

The root `.wp-env.json` also defines wp-env's disposable automated-test site on
port 8889. wp-env starts and stops both sites together; test commands target its
`tests-cli` container explicitly.

```sh
npm run wp:test:cli -- plugin list
npm run test:php
npm run env:clean:tests
```

The test environment must remain disposable. Automated tests may reset its
database.

## Native Deck and Slide smoke tests

Build the current assets and start the clean development site before running
the native front-end and editor checks:

```sh
npm run build
npm run env:start
npm run test:native-runtime
npm run test:e2e
npm run test:editor-runtime
npm run test:navigator-runtime
npm run test:m6-runtime
```

`test:native-runtime` creates or updates a deterministic local native-deck
fixture and verifies the public Reveal.js 6 presentation headlessly.
`test:e2e` uses the disposable test site and checks a deterministic signed-out
native presentation in Chromium, Firefox, and WebKit, including keyboard focus,
reduced motion, runtime errors, and automated WCAG 2/2.1 A/AA rules. Install the
required local engines once with `npx playwright install chromium firefox
webkit`.
`test:editor-runtime` signs in with the wp-env defaults, creates a temporary
slideshow through the real block editor data stores, saves and reloads it,
checks that the Deck/Slide tree and attributes remain valid, and removes the
post. Set `WP_BASE_URL`, `WP_ADMIN_USER`, and `WP_ADMIN_PASSWORD` only when the
clean site does not use the documented wp-env defaults.

`test:navigator-runtime` creates a disposable 60-slide deck, exercises the
Slides sidebar's accessible selection and management workflow—including
keyboard and drag/drop ordering with undo—and removes the fixture.

`test:m6-runtime` creates a deterministic advanced native deck and verifies
static and dynamic fragments, shared forward/backward steps, advanced
background attributes, an auto-animate event, connected Markdown speaker notes,
and progressive `print-pdf` pages. It uses headless Chromium and does not use
the in-app browser.

## Migration dry-run

The initial migration command is read-only and emits content-free JSON:

```sh
wp-env run cli wp presenter migration dry-run 123
wp-env run cli wp presenter migration dry-run --limit=20 --offset=0
```

Batch size is capped at 100 and ordered by post ID. A `ready` report contains a
deterministic internal Deck/Slide plan; a `blocked` report exposes no generated
content and lists the source features that still need a lossless representation.
Neither report includes slide content, titles, notes, class names, data values,
passwords, short URLs, theme paths, or source fingerprints.

Run the committed synthetic gate with:

```sh
npm run test:migration-dry-run
npm run test:migration-representation-runtime
npm run test:legacy-section-parity
```

It creates one ready and one blocked local fixture, runs the real WP-CLI command,
validates the JSON report and expected source ordering/blockers, and proves with
exact source fingerprints that the post and all legacy metadata remain unchanged.
Reserved fixture slugs are never allowed to overwrite an unmarked slideshow.
The representation runtime gate separately verifies canonical legacy vertical
navigation, horizontal exit, print inclusion, and both HTML-note modes through
the real WordPress and Reveal.js route in headless Chromium. The section-parity
gate compares the characterized opaque nested-section result directly between
the bundled Reveal 4.3.1 source and Reveal 6 dependency.

The isolated production snapshot can run the same read-only planner without
printing authored content or private metadata:

```sh
npm run snapshot:wp:cli -- presenter migration dry-run --limit=100 --offset=0
```

The production-derived rehearsal produces 65 ready plans across all 65 legacy
decks in that environment, with zero representation blockers. Reports
remain content-free. Canonical top-level section stacks and narrowly
characterized opaque nested structures are retained as compatibility paths;
this does not add vertical-stack authoring to new decks. Ready planning is not
authorization to write native content or change routing.

## Migration preparation and status

Preparation is an explicit, single-deck safety checkpoint. It captures an exact
WordPress revision, stores an immutable verified backup, and appends a verified
`apply_prepared` journal event. It does not change post content, legacy
metadata, or the deck-mode marker:

```sh
wp-env run cli wp presenter migration status 123
wp-env run cli wp presenter migration prepare 123 --yes
wp-env run cli wp presenter migration status 123
wp-env run cli wp presenter migration apply 123 --yes
wp-env run cli wp presenter migration status 123
wp-env run cli wp presenter migration restore 123 --yes
wp-env run cli wp presenter migration status 123
```

`status` is always read-only, including before the migration secret exists.
Both commands emit content-free JSON: authored values, hashes, storage IDs,
references, and lock tokens remain private. Preparation refuses blocked plans,
active WordPress edit locks, migration-lock contention, changed sources, and
unverified artifacts. Repeating preparation for the exact prepared source is
idempotent and creates no additional revision, backup, or journal event.

Run the real WP-CLI contract gate with:

```sh
npm run test:migration-prepare-status
npm run test:migration-apply
npm run test:migration-restore
npm run test:migration-admin-batch
```

`apply` accepts only a verified `apply_prepared` deck. It uses one byte-exact
conditional content update so an intervening editor or API write wins rather
than being overwritten, verifies the saved native Deck/Slide structure and all
prepared artifacts, then conditionally creates the singleton native cutover
marker as the final representation mutation. Legacy slide, theme, and short-URL
metadata remain exact and authoritative until that marker is verified.

Failures before the content write leave preparation intact. Failures after a
write remove only a marker owned by that invocation, restore original content
with the inverse conditional write, and record either `apply_rolled_back` or
terminal `recovery_required`. Exact successful reruns are footprint-idempotent.
The synthetic apply gate proves these contracts through the registered WP-CLI
command without using the in-app browser.

`restore` accepts only a verified `applied` or safely resumable
`restore_prepared` deck. It records durable restore intent before changing the
live representation, atomically removes the exact singleton native marker,
then uses the inverse byte-exact conditional content write. It verifies the
original legacy representation and every retained artifact before recording
`restored`. Interrupted restores resume from the exact verified marker/content
combination, and successful reruns are footprint-idempotent.

## Migration rendering comparison

Build the current assets and start wp-env, then run the synthetic legacy/native
comparison against the clean development site:

```sh
npm run build
npm run env:start
npm run test:migration-comparison
```

The command creates a marked local fixture, captures its legacy representation
twice, prepares and applies it, captures the native representation twice,
compares both structures and screenshots, restores the exact legacy state, and
checks an untouched neighboring deck. It uses command-line headless Chromium,
not the in-app browser.

Raw screenshots and pixel diffs are written only beneath the ignored
`local/migration-comparison-gate/run-<opaque-id>/` directory. The content-free
report is `report/comparison-report.json` inside that run. Treat the entire run
directory as private: screenshots and diffs can contain authored slide content
even though the JSON report contains only keyed digests, opaque identities,
fixed diagnostic codes, counts, and pixel ratios. Do not commit or publish
these artifacts.

Structural comparison is exact after narrowly removing Reveal-owned runtime
noise. It checks runtime readiness, dimensions, semantic configuration, theme
identity, stack hierarchy, Slide count/order/address/anchors, notes, fragment
sequence, data attributes, wrapper classes, and canonical rendered content.
Any structural difference fails the gate. Pixel-identical captures pass visual
comparison; any nonzero pixel difference is recorded as `review_required` for
human review rather than being silently accepted. Capture errors,
nondeterministic repeat captures, missing assets, console/page errors, and HTTP
failures fail closed.

Browser capture permits only the selected localhost origin plus `about:`,
`data:`, and same-origin `blob:` resources. External fonts, embeds, and scripts
are blocked and make the capture incomplete; do not weaken that policy to make
a deck pass. The committed wp-env configuration mounts the separate companion
themes plugin, and the synthetic fixture ensures it is active. Presenter no
longer registers the legacy CDN-backed RevealMath integration. The fixture uses
a bundled Reveal theme so the synthetic gate has no external theme assets. The
companion remains external test infrastructure and is never packaged with
Presenter.

## Private production snapshot

The supplied production-derived files are private and remain outside the
Presenter repository:

| File                                | Expected SHA-256                                                   |
| ----------------------------------- | ------------------------------------------------------------------ |
| `aarondcampbell.sql`                | `9DB21AD19A5A8DD8A75BF3C778508B1648629DDEDAB51BD7A8CF9A20512EA41A` |
| `aarondcampbell-wp-content.tar.bz2` | `708D35A7E7CEAD69F850C100F3A9C5185EF839C5D4DC72ECA0C4879B8306F298` |

Verify the source hashes and every archive path before preparing the snapshot:

```sh
npm run snapshot:verify
```

The command looks in the repository's parent directory by default. Set
`PRESENTER_SNAPSHOT_SOURCE` to the directory that directly contains both source
files when needed. It does not extract, import, or modify either source file.

Prepare a fresh ignored uploads tree only after verification succeeds:

```sh
npm run snapshot:prepare
```

The preparation command deletes only the resolved
`local/snapshot/wp-content/uploads` directory, extracts uploads from the
verified archive, rejects non-regular entries and executable-like extensions,
and writes a second Apache execution-denial rule inside the uploads directory.

The SQL contains 53 production tables with prefix `NVwKgD_` and 12 unrelated
staging tables with prefix `wp_`. A snapshot setup is invalid unless
`wp db prefix` returns exactly `NVwKgD_` after import.

The committed `tools/snapshot/env/.wp-env.json` is isolated by its own working
directory. After preparing `local/snapshot/`, `npm run snapshot:env:start`
starts it on port 8890 and mounts:

-   the working Presenter repository;
-   the sibling Aaron theme plugin;
-   extracted uploads only;
-   the committed snapshot safety MU plugin.

The snapshot start wrapper binds the development site, test site, and both
database ports to IPv4 loopback before Docker starts them. It then inspects the
actual Docker publications and stops the environment if any binding is absent
or public. Do not bypass this wrapper with a direct `wp-env start` command.

Never extract or execute the archived plugins, MU plugins, or themes. At least
one upload has an executable-like extension; extraction tooling must exclude
PHP, PHAR, PHTML, CGI, Perl, Python, and shell files and add an uploads execution
deny rule.

Set a local-only administrator password in the current shell, then run the
fail-fast bootstrap and read-only preflight:

```powershell
$env:PRESENTER_SNAPSHOT_ADMIN_PASSWORD = '<local-only password>'
npm run snapshot:bootstrap
npm run snapshot:preflight
```

Do not put the password on a command line, in `.env`, or in a committed file.
On Aaron's local workspace, the generated password and its usage note are kept
in user-readable-only files in the workspace parent, outside both Git and the
WordPress-mounted plugin directory. The bootstrap reads the password from the
process environment, never logs it, and refuses to run without it. The snapshot
bootstrap performs, in this order:

1. verify both hashes and archive path safety;
2. extract only non-executable uploads into `local/snapshot/wp-content/uploads`;
3. start the dedicated snapshot environment;
4. reset the wp-env database before changing its table prefix;
5. set `$table_prefix` to `NVwKgD_` and assert it;
6. stream the SQL to `wp db import -` without copying it under the web root;
7. disable all imported plugins, then activate only Presenter and the companion
   themes plugin;
8. replace production URLs with `http://localhost:8890` using serialization-safe
   WP-CLI search/replace;
9. disable public indexing, randomize imported passwords, and create one local
   administrator from an environment-provided password; refresh the local
   `admin_email_lifespan` option so automated login is not diverted to the
   production-derived email-confirmation screen;
10. assert the prefix, local URLs, approved plugin list, and safety controls.

`snapshot:preflight` repeats the safety assertions without changing WordPress.
It also verifies source/corpus identity, the uploads tree, loopback-only Docker
bindings, denial of HTTP access to the ignored `local/` tree, local response
headers, and that the legacy migration corpus has no existing locks, backups,
journal events, or native routing markers. Its output is content-free. During a
crash-resume rehearsal, `node tools/snapshot/preflight.mjs --resume-safe` runs
the same isolation and continuity assertions while inspecting, but not
rejecting, the expected in-progress migration footprint.

The optional math audit also emits aggregate counts only. It distinguishes
strong renderer signals such as complete TeX delimiters, MathML, MathJax, and
KaTeX from ambiguous paired dollar signs that may be ordinary currency:

```sh
npm run snapshot:audit:math
```

After a fresh bootstrap and normal preflight pass, rehearse every legacy deck
serially with explicit confirmation:

```sh
npm run snapshot:rehearse -- --yes
```

The runner keeps its keyed, content-free checkpoint outside the repository and
web root in the workspace-level `migration-rehearsal/` directory. It verifies
prepare/apply/restore idempotency, native and restored HTTP behavior, exact
authored-state restoration, revision preservation, and terminal artifact
integrity before advancing. Resume an interrupted run only with `--resume`; the
runner then repeats the resume-safe isolation preflight and accepts only known,
independently verified migration representations.

The same run now captures a normalized legacy render before Prepare and a
normalized native render after Apply. Every public deck receives an exact
structural comparison; the ignored `local/acceptance-corpus/corpus.json`
selection additionally receives exact screenshot comparison when all local
assets load cleanly. Protected and nonpublic decks are explicitly recorded as
`access_not_captured`. Raw screenshots, opaque per-deck resume sidecars, and
diffs remain in the run's private `comparison-private/` directory. The only
content-free summary is `comparison/comparison-report.json`. Comparison happens
after exact Restore verification, so a comparison failure cannot strand a deck
in native mode. A migration rehearsal may therefore complete successfully
while its comparison report correctly records structural, capture, or visual
acceptance failures for follow-up work.

The acceptance selection is bound to the exact, authoritative database-snapshot
digest shared by source verification and preflight; a stale or malformed corpus
manifest fails before capture. Schema-v4 sidecars checkpoint legacy and native
primary/repeat captures independently, using four fixed ordinals per deck. Each
frame HMAC binds its bytes to the run, deck, phase, role, path, and frame
metadata; a capture HMAC also binds its normalized model and ordered artifact
records. A whole-checkpoint HMAC protects the stage, failure disposition,
retry state, and stored report from decision-only tampering. Structural-only
decks repeat and authenticate their normalized models
without writing screenshots. Visual selections additionally require exact
decoded-RGBA equality between each primary/repeat pair. A sidecar that reaches
`compared` stores the exact content-free deck report record plus its keyed
digest. Final report assembly reconstructs that immutable record instead of
repeating a comparison or allocating another diff directory, so finalization
is safe to resume. Asset-dirty captures retain their structural result and are
recorded as `capture_incomplete` with the fixed `asset_failure` reason. A
private optional `local/snapshot/asset-substitutions.json` manifest can restore
only explicitly reviewed snapshot assets. It is bound to both source-archive
hashes. Missing upload mappings require an absent JPG/PNG and its exact
same-directory, same-stem AVIF. One stylesheet entry names exactly the legacy
`?ver=7.0.1` and native `?ver=2.0.0-dev` same-origin Aaron Purple URLs; both
aliases resolve to the same verified offline bytes and canonical entry digest
beneath the private capture-assets directory. Reviewed staging-origin uploads
may additionally bind exact protocol-relative background source tokens to
their exact archive paths. Capture rewrites only Reveal background attributes
on slide sections, before Reveal initializes, to same-origin snapshot paths and
serves the manifest-verified bytes without changing CSP. The loader rejects
traversal, symlinks, duplicate URLs, changed bytes, invalid media signatures,
and unknown types. Each capture authenticates its sorted canonical
entry-digest/usage-count list. Primary/repeat and legacy/native parity require
the same verified entry set, while request counts remain diagnostic evidence:
browser caching and Reveal loading behavior can request the same verified bytes
a different number of times. Document-rewrite occurrences, not nondeterministic
video Range requests, determine archive-backed usage counts. The report records
the fixed asset-basis class, distinct canonical-entry count, and keyed entry-set
digest without URLs.

Capture pre-resolves at most one same-origin WordPress canonical redirect and
then navigates the browser directly to that canonical URL. External and chained
redirects fail closed, while the resulting base URL, relative links, assets,
and Reveal history behavior remain browser-authentic.

Visual capture pauses each active authored or Reveal background video at its
decoded time-zero frame before taking a screenshot. It fails closed on media
decode, seek, or readiness errors. Only Chromium's exact `ERR_ABORTED` for a
resolver-verified rewritten media target is excluded after all slides pass that
barrier, and the capture-only playback wrapper handles only the corresponding
`AbortError` caused by the intentional pause. Unrelated requests and all other
playback errors remain failures.

A fresh one-deck rehearsal completed after the exact stylesheet aliases were
introduced. It restored the authored-state digest exactly, produced a complete
schema-v3 report, passed structural comparison, kept all four captures clean,
and recorded identical two-entry canonical substitution usage for legacy and
native. That deck was not in the visual selection, so the checkpoint validates
structural capture, alias normalization, determinism, reporting, and restore;
it does not establish screenshot parity or full-corpus acceptance.

The subsequent pristine 65-deck rehearsal completed every migration and exact
restore with zero runner failures. Its schema-v3 report found 60 structural
passes, no structural failures, five explicit protected/nonpublic skips, no
server errors, and no nondeterministic comparisons. An earlier full run was
interrupted after deck 35; `--resume` authenticated and revalidated all 35
completed records, then continued at deck 36 through the rest of that corpus.
Nine exact staging-origin images from the supplied archive make nine additional
decks self-contained. Thirty-one decks retain historical asset failures and
therefore fail closed rather than producing incomplete visual evidence.

Follow-up review traced the two clean visual differences to compatibility
chrome rather than authored slide content: the native template omitted the
public `presenter-reveal-footer` integration seam and the optional legacy short
URL. Native rendering now restores both, in the original order after `.slides`.
The short URL is material to this corpus: 63 of 65 decks have a non-empty
effective value, and rendering deliberately retains WordPress's first-meta-row
semantics while accepting only valid HTTP(S) URLs.

Capture activates every leaf slide at its initial fragment state, waits for that
slide's images, and restores the exact initial Reveal address before recording
structure, assets, or screenshots. In the final full-corpus run, decks 96, 106,
202, 592, 1322, and 2548 produced clean deterministic captures requiring human
review only for Reveal 6 navigation-control drift. Independent pixel review
found no authored-pixel differences in any frame. Deck 106's expanded bounds
are its vertical navigation arrows; deck 1322's decoded video and stateful
frames remain correct; and deck 2548's four 19-frame capture sets are
byte-identical within each representation.

The first complete rehearsal migrated and restored all 65 legacy decks. Its
comparison report retained separate structural and capture failures. The run
also characterized two important baseline behaviors without weakening native
migration acceptance:

-   one public legacy deck initially produced a PHP 8.3 server error when a
    stored boolean `false` Slide row reached the old renderer; the shared legacy
    normalization projection now renders that row as its deterministic empty
    Slide without changing stored metadata, and the full rehearsal verifies the
    repaired legacy, native, and restored representations;
-   the password-protected deck returns an empty anonymous response rather than a
    password form in this site snapshot; legacy, native, and restored checks all
    require that no authored content or Reveal assets leak.

The corpus also found legacy payloads with backslashes inside arrays and object
properties. Immutable backup persistence therefore uses a detached deep-slash
copy before WordPress metadata unslashing. Regression coverage proves exact
payload and HMAC preservation without mutating caller-owned objects.

Snapshot inventory bypasses public query filters deliberately. The private
companion plugin hides password-protected slideshows from ordinary archive
queries, but migration maintenance must still discover and verify that deck.

Open `http://localhost:8890/wp-admin` and sign in as `presenter-local` with the
local-only password supplied through `PRESENTER_SNAPSHOT_ADMIN_PASSWORD` during
bootstrap.

The snapshot safety MU plugin suppresses mail, server-side external HTTP,
sitemaps, indexing, and browser requests to external services. Capture never
bypasses that CSP. A digest-pinned same-origin copy of the private theme
stylesheet removes its unavailable historical Open Sans import and preserves
the fallback typography used by the blocked offline baseline. It can prove
legacy/native parity under this pinned offline asset basis, not fidelity to the
unavailable web font. Other external images and media remain explicit capture
failures unless they have an exact reviewed manifest entry and pass the
archive-backed rewrite boundary above.

Historical chart scripts are also made deterministic without relaxing that
boundary. On slideshow responses only, the snapshot safety MU plugin rewrites
the exact saved Google Charts loader URL to an independently authored,
same-origin LineChart compatibility renderer. It implements only the API used
by the three known decks and preserves their initial/fragment chart swap; it is
not Google code and is not a pixel-parity claim for Google's mutable `current`
runtime. The [Google Charts FAQ](https://developers.google.com/chart/interactive/faq)
states that the chart runtime may not be downloaded or hosted locally. The
exact saved Chart.js 3.5.1 URL maps to the identically versioned
npm package. Preflight verifies both local asset digests, including the
Chart.js bytes represented by the historical SRI value, and the comparison
environment identity includes the rewrite and compatibility-renderer sources.
No other external script URL is rewritten.

After bootstrapping the snapshot, verify both chart families headlessly:

```powershell
$env:PRESENTER_SNAPSHOT_ADMIN_PASSWORD = (Get-Content -Raw "..\presenter-local-credentials.txt").Trim()
npm run snapshot:test:charts
```

For manual verification, the public Google chart is at
`http://localhost:8890/slideshow/bsideslv-2018-lessons-learned-by-the-wordpress-security-team/#/wordpress-growth-by-percent`.
It shows percentage growth immediately and swaps to extrapolated site count on
the first advance. After signing in, the historical Chart.js draft is at
`http://localhost:8890/?post_type=slideshow&p=2265&preview=true#/wp-marketshare-yearly`.

Audit the block editor's residual Custom HTML after complete-slide converters
and the same `wpautop()` plus Core raw-handler path used by the editor:

```powershell
$env:PRESENTER_SNAPSHOT_ADMIN_PASSWORD = (Get-Content -Raw "..\presenter-local-credentials.txt").Trim()
npm run snapshot:inventory-custom-html
Remove-Item Env:PRESENTER_SNAPSHOT_ADMIN_PASSWORD
```

The command is restricted to the local snapshot, never prints authored HTML,
and reports only aggregate structural signatures plus post/slide coordinates.
The July 27 planner-v5 audit covered 65 decks and 1,111 slides. Complete-slide
converters claimed seven slides and emitted ten Chart blocks. Conservative
header, unstyled-div, quote-footer, plain-citation, styled-panel, and class-only
wrapper conversion increased native-only raw conversions from 799 to 916 slides
and reduced residual Custom HTML blocks from 324 to 206. Of those residuals,
187 belong to canonical or opaque Reveal section stacks and 19 are reviewed
non-stack exceptions: fourteen absolute-position fragment overlays, three
quote footers with citation-specific sizing, and two embedded style blocks.
Styled panels and class-only theme/layout wrappers use Core Group with native
Heading, Paragraph, List, and other Core children. A real-render geometry probe
confirmed identical boxes and computed styles for representative panel and
classed Group conversions. The same probe rejected replacing the overlays with
Reveal's `r-stack` utility because it changed wrapper height and child
placement.

The snapshot Content Security Policy permits `unsafe-eval` only because the
legacy Reveal.js 4 UMD bundle requires it, and permits same-origin `blob:`
workers because Reveal creates one during local rendering. Scripts and browser
connections are still restricted to the local snapshot origin. Presenter 2.0
must not carry those exceptions into its production front end.

Destroy and rebuild the snapshot rather than treating it as durable data. Never
connect migration tooling to the production site during development.

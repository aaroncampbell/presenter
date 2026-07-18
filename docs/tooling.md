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

- WordPress runtime: 7.0.1.
- PHP runtime floor: 8.3.
- Node.js: 24.15.0; npm: 11.12.1.
- `@wordpress/scripts`: 31.7.0.
- `@wordpress/env`: 10.39.0.
- WordPress packages used by the editor: `@wordpress/block-editor` 15.13.2,
  `@wordpress/blocks` 15.13.1, `@wordpress/components` 32.2.1,
  `@wordpress/data` 10.40.1, `@wordpress/editor` 14.40.1,
  `@wordpress/element` 6.40.1, `@wordpress/i18n` 6.13.1,
  `@wordpress/plugins` 7.40.1, and `@wordpress/url` 4.40.1.
- `@wordpress/e2e-test-utils-playwright`: 1.42.0; `@playwright/test`:
  1.58.2.
- PHPUnit: latest 9.6 release, because the WordPress 7.0 integration framework
  still uses PHPUnit APIs removed in PHPUnit 10 and newer.
- Reveal.js source dependency: 6.0.1.

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

The Milestone 7 preparation checkpoint passes 258 PHP tests with 1,847
assertions and 88 JavaScript tests. `test:migration-prepare-status` exercises
the registered WP-CLI commands against deterministic local fixtures. It proves
status is zero-write, preparation creates one exact revision, verified backup,
and `apply_prepared` event, exact retries do not add artifacts, rejected decks
return nonzero, and every command response remains content-free. No native
content writer, route cutover, or restore command exists at this checkpoint.

Deck and Slide metadata both reference the single `presenter-block-editor`
bundle. Do not split or duplicate that entry without a measured need. The Deck
render callback returns WordPress's already-rendered child content unchanged,
which ensures dynamic blocks execute once rather than being rendered a second
time by Presenter.

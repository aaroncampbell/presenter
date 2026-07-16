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
  `@wordpress/element` 6.40.1, `@wordpress/i18n` 6.13.1, and
  `@wordpress/url` 4.40.1.
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
Reveal initialization, configured dimensions, direct Slide sections, Markdown
notes, and navigation. `npm run test:editor-runtime` signs into the local block
editor, creates and saves a two-slide deck, reloads it, checks block validity
and persisted attributes, then deletes the temporary post. Both commands run
Chromium headlessly and fail on page or console errors.

Deck and Slide metadata both reference the single `presenter-block-editor`
bundle. Do not split or duplicate that entry without a measured need. The Deck
render callback returns WordPress's already-rendered child content unchanged,
which ensures dynamic blocks execute once rather than being rendered a second
time by Presenter.

# Release checklist

This checklist prepares a Presenter release candidate without publishing it.
Uploading to WordPress.org, pushing a release tag, or changing a production site
requires explicit maintainer authorization after every pre-release gate passes.

## 1. Confirm scope and authority

-   Record the target version, source branch, candidate commit, WordPress baseline,
    PHP baseline, and release approver.
-   Confirm the working tree is clean and the legacy Reveal submodule is at the
    committed revision.
-   Review `CHANGELOG.md`, `readme.txt`, upgrade notice, version metadata,
    third-party inventory, licenses, and human-readable source links.
-   Stop if the candidate contains unrelated work, private fixtures, credentials,
    signing material, production URLs, or unreviewed generated files.

## 2. Recreate dependencies

Use a clean checkout of the exact candidate commit:

```sh
composer install --no-interaction --no-progress --prefer-dist
npm ci
```

Do not use `npm audit fix --force` or update lock files during release assembly.
Dependency changes require their own reviewed commit and a fresh candidate.

## 3. Run source and integration gates

```sh
composer check
composer phpcs
composer check:audit
npm run check
npm audit --omit=dev
npm run env:start
npm run test:php
npm run test:plugin-selection-runtime
npm run test:core-blocks-runtime
npm run test:chart-runtime
npm run test:m6-runtime
npm run test:migration-comparison
```

Run the remaining migration, editor, navigation, speaker-view, print, and legacy
runtime gates listed in `docs/tooling.md` when their prerequisites are available.
Stop the local environment after testing:

```sh
npm run env:stop
```

Any failure, skipped required browser, unavailable dependency audit, unexpected
console error, or unexplained visual difference blocks the candidate.

## 4. Build and inspect the candidate

```sh
npm run release:build
npm run release:verify
npm run plugin-check:release
git diff --exit-code -- build
```

Record the ZIP filename, SHA-256, manifest file count, candidate commit, and gate
results. Confirm the archive has exactly one top-level `presenter/` directory,
contains the policy and license files, excludes development/private paths, and
matches its committed human-readable source. Rebuild from the same commit and
require the same archive digest.

## 5. Rehearse on staging

-   Back up the staging database and `wp-content` before installing the exact ZIP.
-   Follow `docs/upgrade-guide.md` and `docs/migration-and-restore.md`.
-   Verify unmigrated legacy decks, native decks, password protection, speaker
    view, print/PDF, themes, charts, fragments, embeds, and representative dynamic
    blocks.
-   Run the complete migration corpus. Investigate every warning, review state,
    missing asset, and visual difference.
-   Exercise both Presenter restore and WordPress revision rollback, then verify
    presentation output and migration eligibility.
-   Obtain explicit visual approval for representative production-derived decks.

Do not continue if staging differs materially from production prerequisites or
if either rollback path has not been proven with the candidate.

## 6. Freeze the approved artifact

-   Change the 2.0.0 changelog entry from **Unreleased** to the approved release
    date, rebuild, and repeat every package verification affected by that change.
-   Confirm the final commit and working tree are clean and CI passes for that
    exact commit.
-   Create the maintainer-approved annotated or signed version tag without moving
    or recreating an existing release tag.
-   Preserve the final ZIP, manifest, checksum, gate record, and staging approval
    together.

## 7. Publish only when authorized

WordPress.org SVN changes are a separate, explicitly authorized operation.
Populate `trunk/` and the immutable version tag from the verified archive—not
from a development working tree—and review the SVN diff before committing.
Update repository release metadata only from the same approved commit and
artifact. Never place private rehearsal evidence, development dependencies, or
local configuration in SVN.

After publication, verify the public checksum/source tag, directory page,
downloaded ZIP, installation, activation, and a new native deck. Monitor the
private security channel and support reports, and use the rehearsed rollback or
a forward security release if a material problem appears.

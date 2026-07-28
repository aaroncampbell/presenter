# Presenter

Presenter creates self-hosted Reveal.js presentations with the standard
WordPress block editor.

**Requires WordPress:** 7.0 or newer within the supported 7.0 line

**Requires PHP:** 8.3 or newer

**Version:** 2.0.0

## Presenter 2.0

New slideshows contain one Presenter Deck with native WordPress blocks inside
Presenter Slides. Authors can control presentation dimensions, themes,
navigation, transitions, backgrounds, fragments, auto-animate, speaker notes,
charts, and slide order without leaving the post editor.

Existing Presenter 1.x slideshows continue to render and edit through their
legacy compatibility path until an administrator explicitly prepares and
applies a verified migration. Migration retains immutable safety artifacts and
supports exact restoration through Presenter or the dedicated WordPress
revision.

Start with the [Presenter documentation](docs/README.md):

- [Upgrade guide](docs/upgrade-guide.md)
- [Migration and restore guide](docs/migration-and-restore.md)
- [Authoring presentations](docs/authoring.md)
- [Theme API](docs/theme-api.md)
- [Hooks reference](docs/hooks-reference.md)
- [Local development](docs/local-development.md)
- [Release checklist](docs/release-checklist.md)
- [Changelog](CHANGELOG.md)
- [Security policy](SECURITY.md)

## Installation

Install and activate Presenter through the WordPress Plugins screen. New
slideshows use the block editor immediately. Updating the plugin does not
automatically rewrite an existing deck; review legacy decks under **Tools →
Presenter Migration**.

Before upgrading a production site, back up its database and `wp-content`, then
rehearse the complete migration and rollback workflow on staging.

## Development

Install the pinned PHP and Node dependencies, then run the project gates:

```sh
composer install
npm ci
npm run check
composer check
npm run env:start
npm run test:php
```

Build a deterministic distributable and verify its manifest with:

```sh
npm run release:build
npm run release:verify
```

See [local development](docs/local-development.md) and
[tooling](docs/tooling.md) for the complete environment and test matrix.
Human-readable source for every compiled release is maintained in the
[public Presenter repository](https://github.com/aaroncampbell/presenter); use
the tag matching the installed version. Bundled libraries and license locations
are recorded in [third-party notices](docs/third-party-notices.md).

## Changelog

See the standalone [changelog](CHANGELOG.md) for the complete release history.

### 2.0.0

- Rebuilt authoring around native Deck, Slide, and Chart blocks.
- Updated native presentations to Reveal.js 6 while retaining the characterized
  Reveal.js 4 compatibility runtime for unmigrated decks.
- Added themes, dimensions, navigation, transitions, backgrounds, fragments,
  auto-animate, notes, speaker view, print/PDF support, and a slide navigator.
- Added explicit, reversible migration with dry-run, prepare, status, apply,
  restore, immutable backups, dedicated revisions, crash resume, and admin and
  WP-CLI workflows.
- Added accessibility, RTL, reduced-motion, security, privacy, and Plugin Check
  hardening with comprehensive automated and production-derived rehearsal gates.

### 1.5.2

- Completed the Reveal.js 4.3.1 package update.

Earlier release history remains available in the WordPress.org plugin archive
and repository history.

## Security

Report suspected vulnerabilities privately using the process in the
[security policy](SECURITY.md). Do not publish exploit details or private deck
content in an issue or pull request.

## License

Presenter is licensed under GPL-2.0-or-later. Reveal.js is distributed under its
included MIT license. See [third-party notices](docs/third-party-notices.md) for
all bundled libraries, fonts, copyrights, source locations, and license terms.

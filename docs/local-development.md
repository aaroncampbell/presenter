# Local development

Presenter uses separate environments for clean development, automated tests,
and private production-snapshot migration rehearsals.

## Prerequisites

- Docker Desktop using the WSL2 backend on Windows.
- Node.js 24.15 and npm 11.12, as pinned by `.nvmrc` and `package.json`.
- PHP 8.3 or newer and Composer 2 for host-side quality checks.

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
npm run test:editor-runtime
npm run test:navigator-runtime
npm run test:m6-runtime
```

`test:native-runtime` creates or updates a deterministic local native-deck
fixture and verifies the public Reveal.js 6 presentation headlessly.
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
```

It creates one ready and one blocked local fixture, runs the real WP-CLI command,
validates the JSON report and expected source ordering/blockers, and proves with
exact source fingerprints that the post and all legacy metadata remain unchanged.
Reserved fixture slugs are never allowed to overwrite an unmarked slideshow.
The representation runtime gate separately verifies canonical legacy vertical
navigation, horizontal exit, print inclusion, and both HTML-note modes through
the real WordPress and Reveal.js route in headless Chromium.

The isolated production snapshot can run the same read-only planner without
printing authored content or private metadata:

```sh
npm run snapshot:wp:cli -- presenter migration dry-run --limit=100 --offset=0
```

The third representation checkpoint produces 52 ready plans across the 64
legacy decks in that environment. The remaining blockers are
duplicate/ambiguous data attributes (8 decks), deeper or mixed nested sections
(3), and malformed source values (2). Reports remain content-free. Canonical
top-level section stacks are retained as a compatibility path and reported with
`legacy_section_stack_preserved`; this does not add vertical-stack authoring to
new decks.

## Private production snapshot

The supplied production-derived files are private and remain outside the
Presenter repository:

| File | Expected SHA-256 |
| --- | --- |
| `aarondcampbell.sql` | `9DB21AD19A5A8DD8A75BF3C778508B1648629DDEDAB51BD7A8CF9A20512EA41A` |
| `aarondcampbell-wp-content.tar.bz2` | `708D35A7E7CEAD69F850C100F3A9C5185EF839C5D4DC72ECA0C4879B8306F298` |

Verify the source hashes and every archive path before preparing the snapshot:

```sh
npm run snapshot:verify
```

The command looks in the repository's parent directory by default. Set
`PRESENTER_SNAPSHOT_SOURCE` to an alternate source directory when needed. It
does not extract, import, or modify either source file.

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

- the working Presenter repository;
- the sibling Aaron theme plugin;
- extracted uploads only;
- the committed snapshot safety MU plugin.

Never extract or execute the archived plugins, MU plugins, or themes. At least
one upload has an executable-like extension; extraction tooling must exclude
PHP, PHAR, PHTML, CGI, Perl, Python, and shell files and add an uploads execution
deny rule.

The snapshot bootstrap must, in this order:

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

The snapshot safety MU plugin suppresses mail, server-side external HTTP,
sitemaps, indexing, and browser requests to external services. This also means
external embeds and fonts will not appear unless a narrow, snapshot-only host
exception is deliberately added.

The snapshot Content Security Policy permits `unsafe-eval` only because the
legacy Reveal.js 4 UMD bundle requires it. Scripts and browser connections are
still restricted to the local snapshot origin. Presenter 2.0 must not carry
that exception into its production front end.

Destroy and rebuild the snapshot rather than treating it as durable data. Never
connect migration tooling to the production site during development.

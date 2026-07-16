# Local acceptance-corpus capture

`capture-corpus.mjs` characterizes the ignored acceptance corpus against the
isolated snapshot at `http://localhost:8890`. It refuses to query another
origin, blocks every non-local browser request, and writes only beneath
`local/acceptance-corpus/`.

Run it from the repository root after starting and bootstrapping the snapshot:

```sh
node tools/baseline/capture-corpus.mjs
```

The first run creates `local/acceptance-corpus/.hmac-key` with 256 random bits.
Keep that ignored file to compare captures over time. Removing it makes earlier
digests incomparable. The generated `baseline/manifest.json` contains post IDs,
statuses, counts, boolean feature flags, local browser readiness, sanitized
script and stylesheet paths, error counts, and HMAC-SHA256 digests. It does not
contain titles, slide HTML, notes, URLs, rendered DOM, console messages, or
other raw presentation content.

Anonymous capture intentionally records draft and private decks as inaccessible
and records the password form for protected decks. To exercise draft/private
rendering, supply the dedicated local snapshot administrator at runtime:

```sh
PRESENTER_SNAPSHOT_USER=presenter-local \
PRESENTER_SNAPSHOT_PASSWORD='local-password' \
node tools/baseline/capture-corpus.mjs
```

Credentials are used only for the local login form and are never written to the
manifest or command output. An authentication failure stops the capture.

`collect-corpus.php` is an implementation detail run by the Node utility with
WP-CLI. It independently checks that WordPress reports a local environment on
port 8890 before reading any deck metadata.

## Visual-mode baselines

`capture-visual-modes.mjs` is an opt-in visual characterization pass for the
anonymous public decks selected in the corpus. It captures the first slide at
1280 by 720 pixels, exercises Reveal's print-PDF query mode, and attempts to
open and connect the Reveal speaker view:

```sh
node tools/baseline/capture-visual-modes.mjs
```

The utility is hard-coded to the isolated snapshot on port 8890 and blocks all
non-local browser requests. It skips every corpus entry that does not render a
ready Reveal deck anonymously. Screenshots and their content-free manifest are
written only to ignored `local/acceptance-corpus/visual-modes/` storage. The
manifest contains post IDs, viewport metadata, mode-detection booleans,
relative filenames, and SHA-256 file digests; it never contains slide text,
notes, titles, credentials, or browser messages.

Speaker results distinguish the popup shell opening, its Reveal connection,
and both preview frames actually rendering. A connected shell with blank
previews is recorded as a compatibility limitation, not a passing speaker
view.

## Reveal navigation and fragments

`verify-reveal-interactions.mjs` exercises real keyboard navigation against a
local public deck with fragments. It verifies forward fragment reveal,
backward fragment hiding, and slide advancement after the final fragment while
emitting only counts, booleans, and diagnostics:

```sh
npm run baseline:interactions
```

The command refuses every origin except the isolated snapshot on port 8890.

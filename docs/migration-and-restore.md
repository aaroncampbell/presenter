# Migration and restore guide

Presenter migration is explicit, per-deck, resumable, and reversible. The
workflow never treats a displayed status or browser response as authorization;
each operation revalidates the persisted post, legacy metadata, signed journal,
backup, revision, planner version, and lock while it runs.

## States and operations

| Operation | Writes active presentation content? | Result |
| --- | --- | --- |
| Review / `dry-run` | No | Reports whether the legacy representation can be planned. |
| `status` | No | Returns content-free persisted state and capabilities. |
| Prepare | No | Creates and verifies an immutable backup, a dedicated revision, and a prepared journal attempt. |
| Apply | Yes | Conditionally writes the prepared blocks, verifies them, and sets the native cutover marker. |
| Restore | Yes | Conditionally restores the signed legacy representation and legacy routing. |

The legacy slide metadata remains present after Apply. Presenter also retains
the immutable backup, journal, and dedicated pre-conversion revision.

## Legacy HTML trust

Presenter does not rewrite historical `_presenter_slides` records during an
upgrade. Previously stored HTML starts untrusted and is filtered with
WordPress's post-HTML allow-list only when the legacy presentation renders. This
keeps the original bytes available to migration converters and exact restore
while preventing an old stored script or event handler from executing.

A successful legacy-editor save by a user with `unfiltered_html` records a
private, site-keyed fingerprint for that exact post and slide sequence. This is
content-bound trust, not a reusable deck flag: changing the slide metadata,
copying the marker to another deck, storing duplicate markers, or saving through
a filtered account invalidates it.

Migration converters receive the untouched source before fallback policy is
evaluated. A complete converter may therefore replace a reviewed historical
chart script with safe native blocks. Untrusted active HTML that no converter
claims is reported as `legacy_untrusted_active_html` and cannot be prepared.
Review or convert that slide; do not edit the private trust metadata directly.

## WordPress administration workflow

Administrators can open **Tools → Presenter Migration**, or select **Review
upgrade** from a legacy slideshow editor.

1. Review the plan and current migration state.
2. Select **Prepare**. Preparation does not change the published slideshow.
3. Reload and review the resulting prepared state.
4. Select the Apply confirmation and choose **Apply native content**.
5. Verify the presentation before editing its new native blocks.

The current page also supports serial Prepare and Apply queues. Each deck is a
separate authenticated operation. The queue stops on the first warning, failure,
review state, or unknown transport outcome. Decks applied earlier in the queue
remain native; the batch runner never restores them automatically.

Restore is intentionally one deck at a time. Select the confirmation and choose
**Restore legacy content**. If a verified restore was interrupted, the action is
shown as **Resume restore**.

## WP-CLI workflow

Commands emit JSON so automation can retain the exact status and fixed result
codes without exposing authored content.

Inspect one deck without writing:

```sh
wp presenter migration dry-run 123
wp presenter migration status 123
```

Inspect a bounded inventory page:

```sh
wp presenter migration dry-run --limit=20 --offset=0
```

Prepare, apply, and restore one deck:

```sh
wp presenter migration prepare 123
wp presenter migration apply 123
wp presenter migration restore 123
```

Each write command asks for confirmation. Use `--yes` only in a controlled
script after checking the preceding command's exit status and JSON response.

If native content was edited after Apply, normal Restore stops rather than
discarding those edits. To preserve the current native content in a WordPress
revision and explicitly restore the signed legacy source:

```sh
wp presenter migration restore 123 --discard-native-edits
```

That option is destructive to the active native representation and should be
used only after reviewing and backing up the deck.

## Safe operating rules

- Run one write operation per deck at a time.
- Do not operate while a WordPress editor lock is active.
- Never apply a stale prepared target after the planner or source changed;
  restore or prepare a new attempt instead.
- Treat a changed legacy HTML trust fingerprint as source drift and prepare a
  new attempt only after reviewing the exact slide content.
- Reload status after a network interruption. Do not infer failure from a lost
  response because the server may have completed the operation.
- Stop on `review-required`, `recovery-required`, invalid journal, invalid
  backup, source drift, lock contention, or revision verification failures.
- Do not delete `_presenter_slides`, `_presenter_legacy_html_trust_v1`, or
  migration metadata to force progress.

## Verification after Apply

Compare legacy and native presentations using the same viewport, browser,
assets, theme, and route state. Verify standard, fragment-advanced, print/PDF,
and connected speaker views when the deck uses them. A missing historical asset
is not a visual pass; record and resolve it or retain the explicit failure.

## Restore and WordPress revisions

Presenter Restore is the preferred exact inverse of Apply. It verifies the
signed source, conditionally restores content and routing, and appends a terminal
`restored` journal event only after the legacy representation is proven.

The dedicated pre-conversion revision is also visible in the normal WordPress
Revisions UI. Restoring that exact revision restores revision-enabled legacy
metadata, after which Presenter reconciles the signed migration journal. An
unrelated revision does not receive this treatment.

During an active migration, Presenter protects only the verified source revision
from normal revision pruning. Once restoration is terminal, the revision returns
to the site's normal retention policy.

## Incident handling

For an incomplete or unclassifiable operation:

1. stop edits and migration requests for that deck;
2. save the command output or admin notice code;
3. capture `wp presenter migration status <post-id>`;
4. preserve the database, uploads, plugin version, and signing keys; and
5. investigate before retrying or restoring.

Do not expose raw backups, journal records, signing material, or authored slide
content in public tickets or logs.

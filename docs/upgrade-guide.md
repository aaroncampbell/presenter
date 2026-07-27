# Upgrade guide

This guide covers upgrading a site from Presenter 1.5.2 to Presenter 2.0. The
upgrade is deliberately split into plugin installation and per-deck content
migration. Updating the plugin does not automatically rewrite a slideshow.

## Requirements

- WordPress 7.0 or newer within the supported 7.0 line
- PHP 8.3 or newer
- A current database and `wp-content` backup
- An administrator account for the migration screen
- WP-CLI when using the command-line workflow

Test the complete procedure on a non-production copy before changing production.
Keep the previous Presenter plugin package and the site backup until every deck
has been reviewed and the rollback procedure has been exercised.

## Before installing Presenter 2.0

1. Back up the database and the complete `wp-content` directory.
2. Record any custom Presenter themes and plugins that use Presenter hooks.
3. Confirm that every public slideshow opens with Presenter 1.5.2.
4. Identify password-protected, private, draft, or asset-dependent decks that
   require separate review.
5. Clone production to staging and perform the upgrade there first.

## Install the plugin update

Install and activate Presenter 2.0 using the normal WordPress plugin update
process. Existing Presenter 1.x decks continue to use their legacy storage,
classic slide editor, Reveal.js 4 compatibility runtime, and existing theme
paths. New slideshows use the native block editor and Reveal.js 6 runtime.

Merely opening a legacy slideshow or the migration screen does not convert it.
The editor displays a **Review upgrade** action that links to the same migration
workflow available under **Tools → Presenter Migration**.

## Review extensions

Presenter 2.0 keeps the documented Presenter 1.x theme and Reveal hooks, but new
integrations should use stable theme IDs and the modern hooks described in the
[Theme API](theme-api.md) and [Hooks reference](hooks-reference.md).

Verify that each companion plugin:

- registers themes before Presenter resolves the editor theme list;
- provides every historical theme path as a legacy alias;
- uses Reveal.js 6-compatible JavaScript and CSS for native decks;
- does not depend on executing scripts inside editor previews; and
- returns a complete, round-trippable block list when converting a legacy slide.

## Migrate decks

Use the authenticated admin workflow or WP-CLI described in the
[Migration and restore guide](migration-and-restore.md). The safe sequence is:

1. dry-run or review the plan;
2. prepare verified safety artifacts;
3. apply the exact prepared representation;
4. verify the public presentation, print/PDF view, and speaker view; and
5. retain the backup and dedicated pre-conversion revision.

Do not prepare or apply a deck while another user is editing it. Stop on any
warning, unknown transport outcome, review-required state, asset failure, or
unexpected visual difference.

## After migration

Open the slideshow in the block editor and check:

- slide count and order;
- theme, dimensions, margin, centering, and transitions;
- backgrounds, fragments, auto-animate, notes, charts, and persistent footer;
- keyboard, controls, URL hash, speaker view, and print/PDF output; and
- the public URL in a signed-out browser.

Migrated decks retain their historical width and height. Changing the Deck
aspect ratio to 16:9 is an explicit authored layout change and should be reviewed
slide by slide.

## Rollback readiness

Presenter provides two independent rollback paths:

- **Presenter Restore** returns an applied deck to the exact signed legacy
  representation and legacy renderer.
- **WordPress Revisions** can restore the dedicated pre-conversion revision;
  Presenter reconciles its migration journal after Core completes the restore.

Test both on staging. If a deck enters a review-required or recovery-required
state, do not edit, retry, or delete migration metadata. Preserve the database
and investigate the persisted state first.


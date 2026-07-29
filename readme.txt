=== Presenter ===
Contributors: aaroncampbell
Tags: blocks, presentations, reveal.js, slides, slideshow
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create self-hosted Reveal.js presentations with native WordPress blocks and an explicit, reversible upgrade path for Presenter 1.x decks.

== Description ==

Presenter brings presentation authoring to the standard WordPress post editor.
Each slideshow contains a Presenter Deck with WordPress blocks inside Presenter
Slides. Deck and Slide controls cover dimensions, themes, navigation,
transitions, backgrounds, fragments, auto-animate, speaker notes, charts, and
slide organization.

Presenter 2.0 uses Reveal.js 6 for native decks. Existing Presenter 1.x decks
continue to use their characterized legacy editor and Reveal.js 4 compatibility
runtime until an administrator explicitly migrates them.

The migration workflow provides read-only planning, verified preparation,
explicit Apply and Restore operations, immutable backups, a dedicated
pre-conversion WordPress revision, crash resume, and both wp-admin and WP-CLI
interfaces. Updating the plugin alone never rewrites a deck.

== Installation ==

1. Back up the site's database and wp-content directory.
2. Install and activate Presenter through the WordPress Plugins screen.
3. Create new block-based slideshows normally.
4. Rehearse legacy migration and rollback on staging.
5. Review production legacy decks under Tools > Presenter Migration.

== Frequently Asked Questions ==

= Does updating Presenter automatically convert existing slideshows? =

No. Existing decks remain on the legacy compatibility path until an
administrator explicitly prepares and applies a verified migration.

= Can I restore a migrated slideshow? =

Yes. Presenter retains the exact legacy metadata, an immutable signed backup,
and a dedicated pre-conversion revision. Use Presenter Restore for the exact
inverse operation, or restore the verified source through WordPress Revisions.

= Can I use ordinary WordPress blocks in a Slide? =

Yes. Presenter Slides accept applicable registered blocks rather than a narrow
allow-list. Dynamic blocks render through WordPress as usual.

= How do I change presentation size or theme? =

Select the Presenter Deck and use its block inspector. New decks default to
1280 by 720 (16:9). Migrated decks retain their historical dimensions until an
author explicitly changes the aspect ratio.

= Does Presenter support speaker notes and PDF output? =

Yes. Slides support plain text, Markdown, and limited-HTML notes. Reveal speaker
view and browser print/PDF mode are supported. Speaker notes are included in the
delivered page markup and are not secret; do not place confidential information
in them.

= How do extensions register themes? =

Presenter 2.0 uses stable theme IDs through the presenter_theme_registry filter.
Themes may supply historical aliases so stored Presenter 1.x paths resolve
safely during migration. The packaged docs/theme-api.md contains the full API.

= Where is the human-readable source for compiled assets? =

The complete source is maintained at
https://github.com/aaroncampbell/presenter. Use the tag matching the installed
Presenter version. Reproducible build instructions are in docs/tooling.md, and
docs/third-party-notices.md inventories bundled libraries and licenses.

= How do I report a security issue? =

Do not open a public support topic or issue containing vulnerability details.
Follow SECURITY.md in the plugin package or source repository to report the
issue privately.

== Upgrade Notice ==

= 2.0.0 =

Requires WordPress 7.0 and PHP 8.3. Back up the database and wp-content, test on
staging, and review each legacy deck through the explicit migration workflow.
Installing the update does not rewrite existing slideshows.

== Changelog ==

= 2.0.0 =

* Rebuilt authoring around native Presenter Deck, Slide, and Chart blocks.
* Updated native presentations to Reveal.js 6 while retaining Reveal.js 4 compatibility for unmigrated decks.
* Added dimensions, themes, navigation, transitions, backgrounds, fragments, auto-animate, notes, speaker view, print/PDF support, and a slide navigator.
* Added explicit dry-run, prepare, status, apply, and restore migration operations with immutable backups, dedicated revisions, crash resume, wp-admin, and WP-CLI.
* Added accessibility, RTL, reduced-motion, security, privacy, Plugin Check, integration, headless, and production-derived rehearsal gates.

= 1.5.2 =

* Completed the Reveal.js 4.3.1 package update.

= 1.5.1 =

* Added missing files required by the Reveal.js 4.3.1 update.

= 1.5.0 =

* Updated Reveal.js to 4.3.1.
* Added password-protected slideshow support.

= 1.4.0 =

* Updated Reveal.js to 4.1.2.
* Added Reveal initialization, theme, script-dependency, and style-dependency filters.

= 1.3.1 =

* Added the legacy Presenter theme-directory filter.

= 1.3.0 =

* Updated Reveal.js to 3.9.2 and improved SyntaxHighlighter compatibility.

= 1.2.0 =

* Improved dynamically added slide editors for WordPress 4.8.

= 1.1.1 =

* Added notes migration, importer compatibility, and maintenance fixes.

= 1.1.0 =

* Added the speaker-notes interface and Slide data attributes.

= 1.0.1 =

* Corrected release version metadata.

= 1.0.0 =

* Initial release.

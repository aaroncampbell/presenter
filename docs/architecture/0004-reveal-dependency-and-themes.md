# ADR 0004: Reveal.js dependency and Presenter themes

Status: Accepted  
Date: 2026-07-15

## Context

Presenter 1.x stores Reveal.js as a Git submodule and discovers CSS themes by
filesystem path. Submodules complicate ordinary plugin clones and WordPress.org
release packaging. Paths are also poor persistent identities for themes supplied
by another plugin or moved between directories.

## Decision

- Pin a stable Reveal.js npm release and its lockfile.
- Build or copy only required runtime assets and bundled Reveal plugins into the
  installable Presenter artifact.
- Include upstream licenses and reproducible source/build instructions.
- Remove the Git submodule only when the replacement build is verified.
- Register Presenter themes through stable IDs with labels and stylesheet
  metadata instead of using a path as identity.
- Store an empty Deck theme ID as an intentional "site default" selection. It
  resolves through the configured default and legacy `presenter-default-theme`
  filter; an explicitly selected valid stable ID takes precedence.
- Expose the filtered server-side registry and its resolved default to the
  slideshow editor. The editor must not maintain a second hard-coded theme
  list or a different default-resolution algorithm.
- Fetch the resolved stylesheet in the editor and use WordPress
  `transformStyles` to scope its CSS beneath the Presenter preview wrapper.
  Keep a Reveal-shaped preview DOM for theme selector compatibility, but omit
  Reveal's base layout stylesheet so native blocks remain editable.
- Cache preview requests by stylesheet URL for the editor session. A failed
  request must be removed from the cache so it can be retried, and must produce
  a visible, non-blocking warning rather than changing the stored selection.
- Render validated Slide backgrounds inline in the editor as Reveal does on the
  front end, allowing theme and background parity without initializing Reveal.
- Preserve existing public theme and Reveal filters through a documented 2.0
  compatibility adapter.
- Treat the separately distributed `aarondcampbell-presenter-themes` plugin and
  Aaron's site-specific `aaron-purple` theme as external integration fixtures.
  Pin the public companion commit in wp-env so the contract is reproducible,
  but never package either one with Presenter or require it at runtime.

## Consequences

Fresh clones and release builds no longer depend on submodule initialization.
Theme selections survive file moves, and extensions gain a documented registry
while existing integrations have a controlled migration path.

The editor can select the site default or any theme registered through the
server filter, including companion themes. The native template resolves that
selection before `wp_head()` so the correct front-end stylesheet is enqueued,
and the editor consumes the same resolved registry/default data. WordPress
`transformStyles` scopes fetched theme CSS beneath `.presenter-theme-preview`;
the preview supplies Reveal-compatible wrapper and Slide elements without
loading Reveal's base CSS. Validated Slide background colors and images remain
inline in both contexts.

This design gives third-party CSS meaningful visual parity without allowing a
Reveal layout engine to take over the block editor. Cached requests avoid
duplicate downloads, failures can be retried, and authors see a warning when a
preview cannot be loaded while the published-theme selection remains intact.

The externally mounted `aarondcampbell-presenter-themes` integration now
registers `aaron-purple` under its stable ID and selects it through the modern
default-ID filter. It retains the legacy directory, default-path, and
stylesheet-rewrite hooks for Presenter 1.x. Computed-style headless assertions
cover both a bundled theme and the site-specific `aaron-purple` integration in
editor and front-end contexts. This validation does not make the companion a
Presenter distribution dependency.

# ADR 0005: Dual-runtime presentation routing

Status: Accepted  
Date: 2026-07-16

## Context

Presenter 2.0 must introduce a native-block renderer and Reveal.js 6 without
taking existing Presenter 1.x decks offline. Existing decks store their slides
in `_presenter_slides`; new decks will store a `presenter/deck` block in normal
post content. Password-protected presentations must continue through
WordPress's theme/password flow so slide content and speaker notes are not
rendered before authentication.

## Decision

Route singular `slideshow` requests by stored content, in this order:

1. If WordPress requires a post password, retain the selected theme template
   and do not enqueue presentation assets.
2. If `_presenter_slides` contains legacy slides, retain the Presenter 1.x
   compatibility template and Reveal.js 4.3.1 runtime. This path is read-only;
   selecting or rendering it does not migrate or rewrite metadata.
3. If the post contains a `presenter/deck` block and no legacy slides, use the
   Presenter 2.0 presentation template and Reveal.js 6 runtime.
4. Otherwise, retain the template WordPress or the active theme selected.

Legacy metadata deliberately takes precedence if a post temporarily contains
both storage shapes. Migration will switch a deck to the native route only
after it has produced and verified the native block tree.

The native template:

- renders block content before `wp_head()` so block styles and view scripts can
  register normally;
- calls `wp_head()`, `wp_body_open()`, and `wp_footer()`;
- emits the stable `.reveal > .slides` shell and configuration as
  non-executable `application/json`;
- uses `presenter_reveal_config` and `presenter_reveal_plugins` for typed native
  configuration; and
- resolves themes through stable registry IDs while honoring the legacy
  `presenter-default-theme` and `presenter-theme` stylesheet filters.

The native front-end initializes one Reveal instance. Built-in plugins are
registered by stable IDs and loaded as dynamic chunks. Extensions may register
a plugin object before initialization rather than injecting executable
configuration fragments.

## Consequences

Presenter can develop and test native Deck and Slide blocks without changing
published legacy output or data. Both Reveal.js major versions remain in the
plugin during the compatibility period, but a request loads only the runtime
selected for that deck. Removing the legacy runtime requires a separate,
explicit compatibility decision after migration and restore paths are proven.

The native runtime currently enables all six bundled plugins by default. The
highlight plugin produces a large optional chunk, so feature-aware plugin
selection and payload measurements remain a performance follow-up. This does
not change the Milestone 3 routing contract.

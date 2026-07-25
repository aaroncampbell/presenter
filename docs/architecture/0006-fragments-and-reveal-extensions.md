# ADR 0006: Native fragments and Reveal extension boundaries

Status: Accepted  
Date: 2026-07-16

## Context

Reveal fragments apply to individual rendered elements, while WordPress blocks
may be static, nested, or dynamic. Presenter must expose fragment authoring on
applicable native and third-party blocks without changing ordinary posts,
wrapping block output, or rendering dynamic callbacks twice. Advanced Slide
behavior and site-specific Reveal plugins need similarly explicit boundaries.

## Decision

- Add namespaced fragment attributes to non-structural block registrations.
- Expose fragment controls only on Presenter slideshow editor screens and only
  when the selected block is a descendant of `presenter/slide`.
- Have Slide provide a private `presenter/insideSlide` block context. Server
  rendering requires that inherited context rather than inferring scope from a
  post type or from authored attributes alone.
- Decorate the first existing root tag with `WP_HTML_Tag_Processor`; never add a
  wrapper or re-render child blocks.
- Allow-list Reveal 6 effects, custom CSS class tokens, and integer fragment
  indices from 0 through 9999. Equal explicit indices intentionally reveal as
  one shared step.
- Store advanced Slide behavior as revisioned block attributes. Auto-animate,
  background opacity, size, position, repeat, and transition values are
  validated before becoming Reveal data attributes.
- Keep Reveal configuration extensible through `presenter_reveal_config` and
  plugin selection through `presenter_reveal_plugins`.
- Pass the concrete ordered built-in plugin IDs to
  `presenter_reveal_plugins`, so extensions can append or remove IDs without
  copying an internal default list. The final envelope still validates and
  deduplicates every returned ID.
- Let extension scripts register plugin objects through
  `window.presenterReveal.registerPlugin()` before initialization. Registration
  closes when configured plugins begin resolving; unknown and duplicate IDs
  fail explicitly.
- Keep registration open through the browser's deferred-script sequence.
  Presenter waits for `DOMContentLoaded` while the document is `loading` or
  `interactive`; only a script loaded after the document is complete starts
  initialization through the immediate microtask path.

## Consequences

Fragment behavior works on static and dynamic WordPress blocks while the saved
block remains the semantic output root. The same core block rendered outside a
Slide is unchanged. Custom themes can supply additional fragment effects using
validated class names without gaining arbitrary markup access.

Advanced settings remain in post content, participate in revisions, and are
portable with the deck. Presenter exposes a small public Reveal boundary
without exposing its internal loader or silently accepting unregistered plugin
IDs. Automated coverage exercises editor save/reload, static and dynamic
fragments, shared forward/backward steps, auto-animate events, print/PDF mode,
speaker notes, and both server and browser extension contracts.

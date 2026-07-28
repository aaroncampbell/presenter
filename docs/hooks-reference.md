# Hooks reference

This reference lists Presenter-owned public hooks in the 2.0 codebase. WordPress
Core hooks consumed internally are not repeated here.

## Native Presenter 2.0 hooks

### `presenter_theme_registry`

Filters registered themes.

```php
array $themes, \Presenter\Theme_Registry $registry
```

Return an array keyed by stable ID containing matching `Presenter\Theme`
objects. Invalid or ambiguous registries fail closed.

### `presenter_default_theme_id`

Filters the site-default stable theme ID.

```php
string $theme_id, array $themes
```

### `presenter_reveal_config`

Filters native Reveal settings for one post before validation and encoding.

```php
array $settings, WP_Post $post
```

Supported settings are width, height, margin, controls, progress, hash, center,
keyboard, RTL, transition, and background transition. Presenter Deck settings
are added through this same filter.

### `presenter_reveal_plugins`

Filters ordered native Reveal plugin IDs.

```php
array $plugin_ids, WP_Post $post
```

The incoming list is feature-aware: Search, Notes, and Zoom form the baseline;
Markdown is included for `data-markdown`, and Highlight is included for
Markdown or rendered `code` elements. The filtered list is authoritative, so
extensions may append or remove registered IDs.

IDs must be registered by Presenter's front-end plugin loader and use lowercase
slug syntax. Unknown or malformed values are rejected.

Extension scripts register their plugin object before initialization through
`window.presenterReveal.registerPlugin( id, plugin )`. Their WordPress script
handle must depend on `presenter-frontend`.

### `presenter_editor_preview_footer`

Runs while Presenter captures trusted persistent markup for editor and navigator
previews. It receives no arguments.

### `presenter_migration_slide_blocks`

Allows an extension to replace one complete legacy Slide with parsed blocks.

```php
array|null $blocks, string $content, array $slide, int $position
```

Return `null` to leave the lossless fallback untouched. Returned blocks must be
complete, non-empty, named parsed blocks that serialize and parse exactly.

## Presentation actions retained from Presenter 1.x

### `presenter-head`

Runs in the legacy presentation `<head>`.

### `presenter-reveal-footer`

Runs inside the Reveal root after the Slides container. Presenter 2.0 also uses
it as the native persistent presentation-footer compatibility action.

### `presenter-footer`

Runs near the end of the legacy presentation body.

## Presenter 1.x compatibility filters

These hooks remain for stored legacy decks and companion plugins. Their
hyphenated names are intentional public compatibility contracts.

| Hook | Values | Purpose |
| --- | --- | --- |
| `presenter-init-object` | `object $settings` | Filters the Reveal initialization object. Native rendering accepts only Presenter's supported scalar keys; legacy plugin expressions are restricted to valid JavaScript identifiers. |
| `presenter-theme-directories` | `array $directories` | Adds directories scanned for legacy CSS themes. |
| `presenter-themes` | `array $themes`, legacy Presenter instance | Filters the legacy theme map. The legacy implementation intersects the result with discovered themes, so this is not the preferred 2.0 registration API. |
| `presenter-default-theme` | `string $path_or_url` | Filters the legacy default theme and remains a native site-default compatibility seam. |
| `presenter-theme` | `string $stylesheet_url` | Adapts the final stylesheet URL for legacy and native presentations. |
| `presenter-reveal-js-dependencies` | `array $handles` | Filters legacy Reveal.js script dependencies. |
| `presenter-reveal-css-dependencies` | `array $handles` | Filters legacy Reveal.js style dependencies. |

## Extension rules

- Do not write post content or migration metadata from read-only filters.
- Keep callbacks deterministic; migration planning may run more than once.
- Preserve the input type and documented return shape.
- Use stable IDs rather than filesystem paths for new theme selections.
- Treat authored Slide HTML, notes, URLs, and metadata as untrusted input.
- Do not log migration backups, signing material, or raw slide content.

# Theme API

Presenter 2.0 identifies themes by stable IDs. A theme supplies a human-readable
label, a public stylesheet URL, and optional historical aliases used to resolve
stored Presenter 1.x theme paths during migration.

## Register a theme

Use `presenter_theme_registry` after the built-in themes are registered on
`init` priority 40:

```php
add_filter(
	'presenter_theme_registry',
	static function ( array $themes ): array {
		$themes['my-brand'] = new \Presenter\Theme(
			'my-brand',
			__( 'My Brand', 'my-plugin' ),
			plugins_url( 'assets/my-brand.css', __FILE__ ),
			array(
				'/plugins/my-presenter-theme/legacy/my-brand.css',
			)
		);

		return $themes;
	}
);
```

Theme IDs must be lowercase slugs. Registry entries must be keyed by the same
ID returned by the `Theme` object. Stylesheet URLs and aliases must be non-empty.
Aliases must be unique across the complete registry; ambiguous legacy paths
fail closed instead of silently selecting a theme.

The stylesheet URL must be reachable by both the public presentation and the
authenticated block editor. Presenter fetches and scopes it for editor previews,
so use an HTTP or HTTPS URL and return appropriate same-origin/CORS headers.

## Select the site default

Choose a registered theme ID with `presenter_default_theme_id`:

```php
add_filter(
	'presenter_default_theme_id',
	static fn (): string => 'my-brand'
);
```

If the requested ID is unavailable, Presenter falls back to `black`, then to
the first valid registered theme. The registry must never be empty.

## CSS scope

Write themes against Reveal's normal `.reveal` structure. Keep rules scoped so
they do not style the surrounding WordPress page or editor chrome. Native Slide
content is WordPress block markup inside Reveal `<section>` elements.

Editor previews use the authored width, height, centering, Slide backgrounds,
and selected theme. Retained legacy HTML is rendered in a script-disabled,
no-referrer isolated preview. A theme must not require JavaScript merely to show
its basic typography, colors, backgrounds, or layout.

## Persistent preview and presentation markup

Companion plugins can add trusted, persistent theme markup to native
presentations with `presenter-reveal-footer`. To show matching trusted markup in
editor and navigator previews, also use `presenter_editor_preview_footer`.

```php
function my_presenter_footer(): void {
	echo '<p class="my-presenter-footer">Example Organization</p>';
}

add_action( 'presenter-reveal-footer', 'my_presenter_footer' );
add_action( 'presenter_editor_preview_footer', 'my_presenter_footer' );
```

Only emit markup controlled by trusted plugin code. Preview markup is copied
into isolated documents; scripts are not executed.

## Reveal configuration and plugins

Use `presenter_reveal_config` and `presenter_reveal_plugins` for native decks.
Configuration keys are validated by Presenter, so unsupported keys or invalid
types fail rather than entering the browser runtime.

The plugin filter receives a feature-aware built-in list. Search, Notes, and
Zoom are the baseline; Markdown is added for `data-markdown`, and Highlight is
added for Markdown or rendered `code` elements. Extensions can treat that list
as authoritative input and append or remove their own registered IDs.

The older hyphenated theme and dependency filters remain compatibility APIs for
Presenter 1.x decks. New code should prefer the stable registry and modern
native filters.

An extension that supplies a Reveal plugin must enqueue a script depending on
`presenter-frontend`, register the plugin before DOM ready, and return the same
stable ID through `presenter_reveal_plugins`:

```js
window.presenterReveal.registerPlugin( 'my-reveal-plugin', pluginObject );
```

Registration closes when Presenter begins resolving plugins. Duplicate, late,
missing, or malformed registrations stop initialization instead of silently
changing the deck's runtime.

## Migration converters

A theme or companion plugin can claim a complete legacy Slide through
`presenter_migration_slide_blocks`. Return `null` when the content is not an
exact recognized shape. A non-null result must be a complete, canonical parsed
block list that round-trips through `serialize_blocks()` and `parse_blocks()`.

Never execute legacy JavaScript during conversion. Never return a partial result
that separates a script, target element, note, fragment group, or other coupled
content. Presenter retains the lossless Custom HTML fallback when a converter
does not safely claim the whole Slide.

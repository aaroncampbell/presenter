<?php
/**
 * Presenter theme registry.
 *
 * @package Presenter
 */

namespace Presenter;

use Throwable;
use UnexpectedValueException;

/**
 * Registers stable built-in theme identities and extension seams.
 */
final class Theme_Registry implements Hook_Provider, Legacy_Theme_Resolver {
	/**
	 * Default built-in theme ID.
	 *
	 * @var string
	 */
	private const DEFAULT_THEME_ID = 'black';

	/**
	 * Plugin context.
	 *
	 * @var Plugin_Context
	 */
	private Plugin_Context $context;

	/**
	 * Registered themes keyed by stable ID.
	 *
	 * @var array<string, Theme>
	 */
	private array $themes = array();

	/**
	 * Create the theme registry.
	 *
	 * @param Plugin_Context $context Plugin context.
	 */
	public function __construct( Plugin_Context $context ) {
		$this->context = $context;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_builtin_themes' ), 40 );
	}

	/**
	 * Register the themes distributed with Reveal.js 6.
	 */
	public function register_builtin_themes(): void {
		$labels = array(
			'beige'          => __( 'Beige', 'presenter' ),
			'black'          => __( 'Black', 'presenter' ),
			'black-contrast' => __( 'Black Contrast', 'presenter' ),
			'blood'          => __( 'Blood', 'presenter' ),
			'dracula'        => __( 'Dracula', 'presenter' ),
			'league'         => __( 'League', 'presenter' ),
			'moon'           => __( 'Moon', 'presenter' ),
			'night'          => __( 'Night', 'presenter' ),
			'serif'          => __( 'Serif', 'presenter' ),
			'simple'         => __( 'Simple', 'presenter' ),
			'sky'            => __( 'Sky', 'presenter' ),
			'solarized'      => __( 'Solarized', 'presenter' ),
			'white'          => __( 'White', 'presenter' ),
			'white-contrast' => __( 'White Contrast', 'presenter' ),
		);

		foreach ( $labels as $id => $label ) {
			$legacy_base = '/plugins/' . dirname( plugin_basename( $this->context->plugin_file() ) ) . '/reveal.js';
			$this->register_theme(
				new Theme(
					$id,
					$label,
					$this->context->url() . "build/reveal/theme/{$id}.css",
					array(
						"{$legacy_base}/dist/theme/{$id}.css",
						"{$legacy_base}/css/theme/{$id}.css",
					)
				)
			);
		}
	}

	/**
	 * Register or replace one theme by stable ID.
	 *
	 * @param Theme $theme Theme to register.
	 */
	public function register_theme( Theme $theme ): void {
		$this->themes[ $theme->id() ] = $theme;
	}

	/**
	 * Get registered themes after third-party filtering.
	 *
	 * @return array<string, Theme> Themes keyed by stable ID.
	 * @throws UnexpectedValueException When an extension returns an invalid registry.
	 */
	public function all(): array {
		if ( ! isset( $this->themes[ self::DEFAULT_THEME_ID ] ) ) {
			$this->register_builtin_themes();
		}

		$themes = apply_filters( 'presenter_theme_registry', $this->themes, $this );

		if ( ! is_array( $themes ) ) {
			throw new UnexpectedValueException( 'The Presenter theme registry filter must return an array.' );
		}

		foreach ( $themes as $id => $theme ) {
			if ( ! is_string( $id ) || ! $theme instanceof Theme || $id !== $theme->id() ) {
				throw new UnexpectedValueException( 'Presenter theme registry entries must be keyed Theme objects.' );
			}
		}

		return $themes;
	}

	/**
	 * Resolve the configured default theme.
	 *
	 * @return Theme Default theme.
	 * @throws UnexpectedValueException When the default filter is invalid or no theme remains.
	 */
	public function default_theme(): Theme {
		$themes = $this->all();

		return $this->default_theme_from( $themes );
	}

	/**
	 * Resolve the configured default from one already-validated registry.
	 *
	 * @param array<string, Theme> $themes Validated themes keyed by stable ID.
	 * @return Theme Default theme.
	 * @throws UnexpectedValueException When the default filter is invalid or no theme remains.
	 */
	private function default_theme_from( array $themes ): Theme {
		$id = apply_filters( 'presenter_default_theme_id', self::DEFAULT_THEME_ID, $themes );

		if ( ! is_string( $id ) ) {
			throw new UnexpectedValueException( 'The Presenter default theme ID filter must return a string.' );
		}

		if ( isset( $themes[ $id ] ) ) {
			return $themes[ $id ];
		}

		if ( isset( $themes[ self::DEFAULT_THEME_ID ] ) ) {
			return $themes[ self::DEFAULT_THEME_ID ];
		}

		$fallback = reset( $themes );
		if ( ! $fallback instanceof Theme ) {
			throw new UnexpectedValueException( 'The Presenter theme registry must contain at least one theme.' );
		}

		return $fallback;
	}

	/**
	 * Resolve the native default while honoring the Presenter 1.x companion seam.
	 *
	 * @param string|null $theme_id Stored stable theme ID, or null for the site default.
	 * @return string Public stylesheet URL.
	 */
	public function presentation_stylesheet_url( ?string $theme_id = null ): string {
		try {
			$themes = $this->all();
			$theme  = null !== $theme_id && isset( $themes[ $theme_id ] )
				? $themes[ $theme_id ]
				: $this->default_theme();

			$stylesheet_url = $theme->stylesheet_url();
			$legacy_default = null === $theme_id
				? apply_filters( 'presenter-default-theme', '' ) // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Public Presenter 1.x compatibility hook.
				: '';

			if ( is_string( $legacy_default ) && '' !== $legacy_default ) {
				$stylesheet_url = wp_http_validate_url( $legacy_default )
					? $legacy_default
					: content_url( $legacy_default );
			}

			return $this->adapt_legacy_stylesheet_url( $stylesheet_url );
		} catch ( Throwable $error ) {
			$this->report_presentation_recovery( $error );

			return $this->unfiltered_stylesheet_url( $theme_id );
		}
	}

	/**
	 * Resolve a registered stylesheet without running extension filters.
	 *
	 * This last-resort public-render fallback retains an explicitly selected
	 * registered theme when possible and otherwise uses bundled Black.
	 *
	 * @param string|null $theme_id Stored stable theme ID, or null.
	 * @return string Public stylesheet URL.
	 */
	private function unfiltered_stylesheet_url( ?string $theme_id ): string {
		if ( empty( $this->themes ) ) {
			$this->register_builtin_themes();
		}

		if ( null !== $theme_id && isset( $this->themes[ $theme_id ] ) ) {
			return $this->themes[ $theme_id ]->stylesheet_url();
		}

		return $this->themes[ self::DEFAULT_THEME_ID ]->stylesheet_url();
	}

	/**
	 * Report extension input discarded at the public theme boundary.
	 *
	 * @param Throwable $error Registry or filter failure.
	 */
	private function report_presentation_recovery( Throwable $error ): void {
		_doing_it_wrong(
			__METHOD__,
			sprintf(
				/* translators: %s: PHP exception class. */
				esc_html__( 'Presenter discarded invalid filter-supplied theme data and used a safe registered theme (%s).', 'presenter' ),
				esc_html( get_class( $error ) )
			),
			'2.0.0'
		);
	}

	/**
	 * Resolve a stored Presenter 1.x theme value to a stable registered ID.
	 *
	 * Extensions own the aliases for themes they register. Unknown values remain
	 * unresolved so migration can report them instead of silently substituting a
	 * different theme.
	 *
	 * @param string $legacy_theme Stored stable ID, stylesheet path, or URL.
	 * @return string|null Stable theme ID, or null when no theme owns the value.
	 * @throws UnexpectedValueException When multiple themes claim one alias.
	 */
	public function resolve_legacy_theme_id( string $legacy_theme ): ?string {
		$themes = $this->all();

		if ( isset( $themes[ $legacy_theme ] ) ) {
			return $legacy_theme;
		}

		$resolved_id = null;
		foreach ( $themes as $theme ) {
			if ( ! in_array( $legacy_theme, $theme->legacy_aliases(), true ) ) {
				continue;
			}

			if ( null !== $resolved_id && $resolved_id !== $theme->id() ) {
				throw new UnexpectedValueException( 'Presenter theme legacy aliases must be unique.' );
			}

			$resolved_id = $theme->id();
		}

		return $resolved_id;
	}

	/**
	 * Serialize the ordered theme registry for trusted editor configuration.
	 *
	 * @return array<int, array{id: string, label: string, stylesheetUrl: string}> Editor theme data.
	 */
	public function editor_themes(): array {
		return $this->editor_configuration()['themes'];
	}

	/**
	 * Serialize the resolved site default for trusted editor configuration.
	 *
	 * The identity and label come from the modern registry. The stylesheet URL
	 * follows the same modern and Presenter 1.x compatibility path as a deck
	 * which has not selected an explicit theme.
	 *
	 * @return array{id: string, label: string, stylesheetUrl: string} Default theme data.
	 */
	public function editor_default_theme(): array {
		return $this->editor_configuration()['defaultTheme'];
	}

	/**
	 * Serialize one internally consistent, failure-tolerant editor theme configuration.
	 *
	 * Strict registry and migration APIs continue to throw. The interactive
	 * editor instead reports invalid extension input and falls back to the
	 * unfiltered registered themes so one plugin cannot take down authoring.
	 *
	 * @return array{themes: array<int, array{id: string, label: string, stylesheetUrl: string}>, defaultTheme: array{id: string, label: string, stylesheetUrl: string}}
	 */
	public function editor_configuration(): array {
		try {
			$themes        = $this->all();
			$default_theme = $this->default_theme_from( $themes );

			return $this->serialize_editor_configuration( $themes, $default_theme, true );
		} catch ( Throwable $error ) {
			$this->report_editor_recovery( $error );
		}

		$themes = $this->unfiltered_themes();

		return $this->serialize_editor_configuration( $themes, $themes[ self::DEFAULT_THEME_ID ], false );
	}

	/**
	 * Serialize themes and their resolved site default for the editor.
	 *
	 * @param array<string, Theme> $themes          Themes keyed by stable ID.
	 * @param Theme                $default_theme   Resolved default theme.
	 * @param bool                 $apply_url_seams Whether compatibility URL filters remain safe to apply.
	 * @return array{themes: array<int, array{id: string, label: string, stylesheetUrl: string}>, defaultTheme: array{id: string, label: string, stylesheetUrl: string}}
	 */
	private function serialize_editor_configuration( array $themes, Theme $default_theme, bool $apply_url_seams ): array {
		$editor_themes = array();

		foreach ( $themes as $theme ) {
			$editor_themes[] = $apply_url_seams
				? $this->editor_theme( $theme, $theme->id() )
				: $this->unfiltered_editor_theme( $theme );
		}

		return array(
			'themes'       => $editor_themes,
			'defaultTheme' => $apply_url_seams
				? $this->editor_theme( $default_theme, null )
				: $this->unfiltered_editor_theme( $default_theme ),
		);
	}

	/**
	 * Return registered themes without invoking extension filters.
	 *
	 * @return array<string, Theme> Internally registered themes.
	 */
	private function unfiltered_themes(): array {
		if ( ! isset( $this->themes[ self::DEFAULT_THEME_ID ] ) ) {
			$this->register_builtin_themes();
		}

		return $this->themes;
	}

	/**
	 * Serialize one registered theme without invoking compatibility URL filters.
	 *
	 * @param Theme $theme Registered theme.
	 * @return array{id: string, label: string, stylesheetUrl: string} Editor theme data.
	 */
	private function unfiltered_editor_theme( Theme $theme ): array {
		return array(
			'id'            => $theme->id(),
			'label'         => $theme->label(),
			'stylesheetUrl' => esc_url_raw( $theme->stylesheet_url() ),
		);
	}

	/**
	 * Report extension input discarded at the interactive editor boundary.
	 *
	 * @param Throwable $error Registry or default-filter failure.
	 */
	private function report_editor_recovery( Throwable $error ): void {
		_doing_it_wrong(
			__METHOD__,
			sprintf(
				/* translators: %s: PHP exception class. */
				esc_html__( 'Presenter discarded invalid filter-supplied editor theme data and used safe registered themes (%s).', 'presenter' ),
				esc_html( get_class( $error ) )
			),
			'2.0.0'
		);
	}

	/**
	 * Serialize one theme with its presentation stylesheet resolution applied.
	 *
	 * @param Theme       $theme    Registered theme.
	 * @param string|null $theme_id Explicit stable ID, or null for the site default.
	 * @return array{id: string, label: string, stylesheetUrl: string} Editor theme data.
	 */
	private function editor_theme( Theme $theme, ?string $theme_id ): array {
		return array(
			'id'            => $theme->id(),
			'label'         => $theme->label(),
			'stylesheetUrl' => esc_url_raw( $this->presentation_stylesheet_url( $theme_id ) ),
		);
	}

	/**
	 * Apply the Presenter 1.x stylesheet URL adapter for an unmigrated deck.
	 *
	 * @param string $stylesheet_url Legacy stylesheet URL.
	 * @return string Filtered stylesheet URL.
	 * @throws UnexpectedValueException When the public filter returns a non-string value.
	 */
	public function adapt_legacy_stylesheet_url( string $stylesheet_url ): string {
		$filtered_url = apply_filters( 'presenter-theme', $stylesheet_url ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Public Presenter 1.x compatibility hook.

		if ( ! is_string( $filtered_url ) ) {
			throw new UnexpectedValueException( 'The Presenter legacy theme filter must return a string.' );
		}

		return $filtered_url;
	}
}

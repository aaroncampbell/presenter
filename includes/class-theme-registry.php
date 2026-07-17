<?php
/**
 * Presenter theme registry.
 *
 * @package Presenter
 */

namespace Presenter;

use UnexpectedValueException;

/**
 * Registers stable built-in theme identities and extension seams.
 */
final class Theme_Registry implements Hook_Provider {
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
			$this->register_theme(
				new Theme(
					$id,
					$label,
					$this->context->url() . "build/reveal/theme/{$id}.css"
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
		if ( empty( $this->themes ) ) {
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
	 * @throws UnexpectedValueException When no theme remains after filtering.
	 */
	public function default_theme(): Theme {
		$themes = $this->all();
		$id     = (string) apply_filters( 'presenter_default_theme_id', self::DEFAULT_THEME_ID, $themes );

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
	}

	/**
	 * Serialize the ordered theme registry for trusted editor configuration.
	 *
	 * @return array<int, array{id: string, label: string, stylesheetUrl: string}> Editor theme data.
	 */
	public function editor_themes(): array {
		$editor_themes = array();

		foreach ( $this->all() as $theme ) {
			$editor_themes[] = $this->editor_theme( $theme, $theme->id() );
		}

		return $editor_themes;
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
		return $this->editor_theme( $this->default_theme(), null );
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
	 */
	public function adapt_legacy_stylesheet_url( string $stylesheet_url ): string {
		return (string) apply_filters( 'presenter-theme', $stylesheet_url ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Public Presenter 1.x compatibility hook.
	}
}

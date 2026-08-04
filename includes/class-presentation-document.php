<?php
/**
 * Presentation document isolation.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Keeps the active site theme from restyling Presenter's standalone document.
 */
final class Presentation_Document {
	/** Run after normal theme and plugin enqueue callbacks. */
	private const ISOLATION_PRIORITY = PHP_INT_MAX;

	/** Run immediately before WordPress prints head styles. */
	private const HEAD_ISOLATION_PRIORITY = 7;

	/** Run immediately before WordPress prints footer scripts. */
	private const FOOTER_ISOLATION_PRIORITY = 19;

	/**
	 * Whether isolation is active for the current document.
	 *
	 * @var bool
	 */
	private static bool $active = false;

	/**
	 * Active-theme callbacks removed for this document.
	 *
	 * @var array<int, array{hook: string, callback: callable, priority: int, accepted_args: int}>
	 */
	private static array $removed_callbacks = array();

	/**
	 * Begin presentation-scoped asset isolation.
	 */
	public static function begin(): void {
		if ( self::$active ) {
			return;
		}

		self::$active = true;
		self::remove_theme_callbacks();
		add_action( 'wp_enqueue_scripts', array( self::class, 'isolate_assets' ), self::ISOLATION_PRIORITY );
		add_action( 'wp_head', array( self::class, 'isolate_assets' ), self::HEAD_ISOLATION_PRIORITY );
		add_action( 'wp_footer', array( self::class, 'isolate_assets' ), self::FOOTER_ISOLATION_PRIORITY );
		self::isolate_assets();
	}

	/**
	 * Restore the shared hook registry after the standalone document is complete.
	 */
	public static function end(): void {
		if ( ! self::$active ) {
			return;
		}

		remove_action( 'wp_enqueue_scripts', array( self::class, 'isolate_assets' ), self::ISOLATION_PRIORITY );
		remove_action( 'wp_head', array( self::class, 'isolate_assets' ), self::HEAD_ISOLATION_PRIORITY );
		remove_action( 'wp_footer', array( self::class, 'isolate_assets' ), self::FOOTER_ISOLATION_PRIORITY );

		foreach ( self::$removed_callbacks as $removed ) {
			add_action( $removed['hook'], $removed['callback'], $removed['priority'], $removed['accepted_args'] );
		}

		self::$removed_callbacks = array();
		self::$active            = false;
	}

	/**
	 * Dequeue active-site-theme assets while retaining core and plugin assets.
	 */
	public static function isolate_assets(): void {
		$allowed_styles  = self::allowed_handles( 'style' );
		$allowed_scripts = self::allowed_handles( 'script' );

		foreach ( array( 'global-styles', 'classic-theme-styles', 'wp-block-library-theme' ) as $handle ) {
			if ( ! in_array( $handle, $allowed_styles, true ) ) {
				wp_dequeue_style( $handle );
			}
		}
		foreach ( wp_styles()->queue as $handle ) {
			if (
				str_starts_with( $handle, 'wp-block-' )
				&& str_ends_with( $handle, '-theme' )
				&& ! in_array( $handle, $allowed_styles, true )
			) {
				wp_dequeue_style( $handle );
			}
		}

		self::dequeue_theme_assets( wp_styles(), $allowed_styles );
		self::dequeue_theme_assets( wp_scripts(), $allowed_scripts );
	}

	/**
	 * Read an explicit integration allowlist.
	 *
	 * @param string $type Asset type, either style or script.
	 * @return array<int, string> Allowed registered handles.
	 */
	private static function allowed_handles( string $type ): array {
		if ( 'style' === $type ) {
			/**
			 * Allow selected active-theme styles in standalone presentations.
			 *
			 * @param array<int, string> $handles Registered style handles.
			 */
			$handles = apply_filters( 'presenter_presentation_allowed_theme_style_handles', array() );
		} else {
			/**
			 * Allow selected active-theme scripts in standalone presentations.
			 *
			 * @param array<int, string> $handles Registered script handles.
			 */
			$handles = apply_filters( 'presenter_presentation_allowed_theme_script_handles', array() );
		}

		if ( ! is_array( $handles ) ) {
			return array();
		}

		return array_values(
			array_unique(
				array_filter( $handles, 'is_string' )
			)
		);
	}

	/**
	 * Remove callbacks implemented by the active site theme from document hooks.
	 */
	private static function remove_theme_callbacks(): void {
		global $wp_filter;

		$hooks = array(
			'wp_enqueue_scripts',
			'wp_head',
			'wp_body_open',
			'wp_footer',
			'wp_print_footer_scripts',
		);

		foreach ( $hooks as $hook_name ) {
			$hook = $wp_filter[ $hook_name ] ?? null;
			if ( ! $hook instanceof \WP_Hook ) {
				continue;
			}

			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					if ( ! is_callable( $function ) || ! self::is_theme_callback( $function ) ) {
						continue;
					}

					self::$removed_callbacks[] = array(
						'accepted_args' => (int) $callback['accepted_args'],
						'callback'      => $function,
						'hook'          => $hook_name,
						'priority'      => (int) $priority,
					);
					remove_action( $hook_name, $function, (int) $priority );
				}
			}
		}
	}

	/**
	 * Determine whether a callback's implementation belongs to a site theme.
	 *
	 * @param callable $callback Registered WordPress callback.
	 */
	private static function is_theme_callback( callable $callback ): bool {
		try {
			if ( is_array( $callback ) ) {
				$reflection = new \ReflectionMethod( $callback[0], $callback[1] );
			} elseif ( is_string( $callback ) && str_contains( $callback, '::' ) ) {
				$reflection = new \ReflectionMethod( ...explode( '::', $callback, 2 ) );
			} elseif ( is_object( $callback ) && ! $callback instanceof \Closure ) {
				$reflection = new \ReflectionMethod( $callback, '__invoke' );
			} else {
				$reflection = new \ReflectionFunction( $callback );
			}
		} catch ( \ReflectionException ) {
			return false;
		}

		$file = $reflection->getFileName();

		if ( false === $file ) {
			return false;
		}

		$file_realpath = realpath( $file );
		$file_path     = wp_normalize_path( false === $file_realpath ? $file : $file_realpath );
		$directories   = array(
			get_stylesheet_directory(),
			get_template_directory(),
		);

		foreach ( array_unique( $directories ) as $directory ) {
			$directory_realpath = realpath( $directory );
			if ( false === $directory_realpath ) {
				continue;
			}

			$directory_path = trailingslashit( wp_normalize_path( $directory_realpath ) );
			if ( str_starts_with( $file_path, $directory_path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Remove queued assets served from the active theme root.
	 *
	 * @param \WP_Dependencies   $dependencies Registered dependency collection.
	 * @param array<int, string> $allowed      Explicitly allowed handles.
	 */
	private static function dequeue_theme_assets( \WP_Dependencies $dependencies, array $allowed ): void {
		foreach ( $dependencies->queue as $handle ) {
			if ( in_array( $handle, $allowed, true ) ) {
				continue;
			}

			$asset = $dependencies->query( $handle, 'registered' );
			if ( $asset instanceof \_WP_Dependency && self::is_theme_url( $asset->src ) ) {
				$dependencies->dequeue( $handle );
			}
		}
	}

	/**
	 * Determine whether an asset URL lives beneath WordPress's theme root.
	 *
	 * @param mixed $source Registered asset source.
	 */
	private static function is_theme_url( mixed $source ): bool {
		if ( ! is_string( $source ) || '' === $source ) {
			return false;
		}

		$theme_root_path = wp_parse_url( get_theme_root_uri(), PHP_URL_PATH );
		$source_path     = wp_parse_url( $source, PHP_URL_PATH );

		if ( ! is_string( $theme_root_path ) || ! is_string( $source_path ) ) {
			return false;
		}

		return str_starts_with(
			trailingslashit( $source_path ),
			trailingslashit( $theme_root_path )
		);
	}
}

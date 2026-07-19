<?php
/**
 * Reveal configuration normalization and encoding.
 *
 * @package Presenter
 */

namespace Presenter;

use InvalidArgumentException;
use RuntimeException;

/**
 * Builds the non-executable JSON configuration consumed by the front-end.
 */
final class Reveal_Config {
	/**
	 * Built-in Reveal plugins enabled by default.
	 *
	 * @var array<int, string>
	 */
	private const DEFAULT_PLUGINS = array(
		'markdown',
		'search',
		'notes',
		'zoom',
		'highlight',
	);

	/**
	 * Default presentation settings shared with the front-end module.
	 *
	 * @var array<string, bool|float|int|string>
	 */
	private const DEFAULT_SETTINGS = array(
		'width'                => 1280,
		'height'               => 720,
		'margin'               => 0.04,
		'controls'             => true,
		'progress'             => true,
		'hash'                 => true,
		'center'               => true,
		'keyboard'             => true,
		'transition'           => 'slide',
		'backgroundTransition' => 'fade',
	);

	/**
	 * Reveal transition names supported by Presenter settings.
	 *
	 * @var array<int, string>
	 */
	private const TRANSITIONS = array( 'none', 'fade', 'slide', 'convex', 'concave', 'zoom' );

	/**
	 * Create a validated configuration envelope.
	 *
	 * Unknown Reveal settings are rejected instead of being copied into the
	 * browser runtime. New settings should be added here with an explicit type
	 * and range contract.
	 *
	 * @param array<string, mixed>    $settings Reveal settings.
	 * @param array<int, string>|null $plugins  Registered plugin IDs, or null for defaults.
	 * @return array{reveal: array<string, bool|float|int|string>, plugins: array<int, string>}
	 * @throws InvalidArgumentException When a value is outside the supported contract.
	 */
	public function envelope( array $settings = array(), ?array $plugins = null ): array {
		$unknown_settings = array_diff_key( $settings, self::DEFAULT_SETTINGS );

		if ( array() !== $unknown_settings ) {
			throw new InvalidArgumentException( 'Presenter received an unsupported Reveal setting.' );
		}

		$normalized = self::DEFAULT_SETTINGS;

		foreach ( $settings as $name => $value ) {
			$normalized[ $name ] = $this->normalize_setting( $name, $value );
		}

		return array(
			'reveal'  => $normalized,
			'plugins' => $this->normalize_plugins( $plugins ?? self::DEFAULT_PLUGINS ),
		);
	}

	/**
	 * Apply Presenter 1.x's public configuration seam to modern Reveal settings.
	 *
	 * The compatibility object contains only supported scalar settings. Unknown
	 * properties added by an extension are ignored; supported values are
	 * validated again when the final envelope is built.
	 *
	 * @param array<string, mixed> $settings Modern Reveal settings.
	 * @return array<string, mixed> Settings after legacy compatibility filters.
	 * @throws InvalidArgumentException When the legacy filter breaks its object contract.
	 */
	public function apply_legacy_settings_filter( array $settings ): array {
		$compatibility_settings = (object) self::DEFAULT_SETTINGS;
		$filtered               = apply_filters( 'presenter-init-object', $compatibility_settings ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Public Presenter 1.x compatibility hook.

		if ( ! is_object( $filtered ) ) {
			throw new InvalidArgumentException( 'The Presenter legacy Reveal settings filter must return an object.' );
		}

		$legacy_defaults = array_intersect_key( get_object_vars( $filtered ), self::DEFAULT_SETTINGS );

		return array_merge( $legacy_defaults, $settings );
	}

	/**
	 * Encode an envelope for use as application/json script text.
	 *
	 * The JSON_HEX flags ensure user-controlled strings cannot terminate the
	 * script element or introduce executable markup.
	 *
	 * @param array{reveal: array<string, bool|float|int|string>, plugins: array<int, string>} $envelope Configuration envelope.
	 * @return string Script-safe JSON.
	 * @throws RuntimeException When WordPress cannot encode the envelope.
	 */
	public function encode( array $envelope ): string {
		$json = wp_json_encode(
			$envelope,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);

		if ( false === $json ) {
			throw new RuntimeException( 'Presenter could not encode the Reveal configuration.' );
		}

		return $json;
	}

	/**
	 * Normalize one supported Reveal setting.
	 *
	 * @param string $name  Setting name.
	 * @param mixed  $value Setting value.
	 * @return bool|float|int|string Normalized value.
	 * @throws InvalidArgumentException When the setting value is invalid.
	 */
	private function normalize_setting( string $name, mixed $value ): bool|float|int|string {
		if ( in_array( $name, array( 'controls', 'progress', 'hash', 'center', 'keyboard' ), true ) ) {
			if ( ! is_bool( $value ) ) {
				throw new InvalidArgumentException( 'Presenter Reveal boolean settings must be boolean values.' );
			}

			return $value;
		}

		if ( in_array( $name, array( 'width', 'height' ), true ) ) {
			if ( ! is_int( $value ) || $value < 1 || $value > 10000 ) {
				throw new InvalidArgumentException( 'Presenter Reveal dimensions must be integers from 1 through 10000.' );
			}

			return $value;
		}

		if ( 'margin' === $name ) {
			if ( ! is_float( $value ) && ! is_int( $value ) ) {
				throw new InvalidArgumentException( 'The Presenter Reveal margin must be numeric.' );
			}

			$margin = (float) $value;

			if ( $margin < 0 || $margin >= 1 ) {
				throw new InvalidArgumentException( 'The Presenter Reveal margin must be at least zero and less than one.' );
			}

			return $margin;
		}

		if ( ! is_string( $value ) || ! in_array( $value, self::TRANSITIONS, true ) ) {
			throw new InvalidArgumentException( 'Presenter received an unsupported Reveal transition.' );
		}

		return $value;
	}

	/**
	 * Normalize registered plugin IDs while retaining their configured order.
	 *
	 * @param array<int, mixed> $plugins Plugin IDs.
	 * @return array<int, string> Unique plugin IDs.
	 * @throws InvalidArgumentException When a plugin ID is invalid.
	 */
	private function normalize_plugins( array $plugins ): array {
		$normalized = array();

		foreach ( $plugins as $plugin ) {
			if ( ! is_string( $plugin ) || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $plugin ) ) {
				throw new InvalidArgumentException( 'Presenter Reveal plugin IDs must be lowercase slugs.' );
			}

			$normalized[ $plugin ] = $plugin;
		}

		return array_values( $normalized );
	}
}

<?php
/**
 * Presenter Slide advanced-attribute validation.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Validates Slide wrapper classes and generic Reveal data attributes.
 */
final class Slide_Attribute_Validator {
	/**
	 * Reveal attributes owned by typed Slide settings.
	 *
	 * @var array<int, string>
	 */
	private const TYPED_DATA_ATTRIBUTES = array(
		'data-auto-animate',
		'data-auto-animate-id',
		'data-auto-animate-restart',
		'data-background-color',
		'data-background-image',
		'data-background-opacity',
		'data-background-position',
		'data-background-repeat',
		'data-background-size',
		'data-background-transition',
		'data-transition',
		'data-visibility',
	);

	/**
	 * Generic Reveal attributes which load presentation resources.
	 *
	 * @var array<int, string>
	 */
	private const URL_DATA_ATTRIBUTES = array(
		'data-background-iframe',
		'data-background-video',
	);

	/**
	 * Validate authored Slide wrapper classes without rewriting them.
	 *
	 * @param mixed $value Candidate class list.
	 * @return string|null Valid original value, or null when invalid.
	 */
	public function classes( mixed $value ): ?string {
		if ( ! is_string( $value ) || strlen( $value ) > 512 ) {
			return null;
		}

		if ( '' === $value ) {
			return '';
		}

		$tokens = preg_split( '/\s+/', $value );
		if ( ! is_array( $tokens ) || count( $tokens ) > 20 || count( $tokens ) !== count( array_unique( $tokens ) ) ) {
			return null;
		}

		foreach ( $tokens as $token ) {
			if ( strlen( $token ) > 64 || 1 !== preg_match( '/^-?[A-Za-z_][A-Za-z0-9_-]*$/', $token ) ) {
				return null;
			}
		}

		return $value;
	}

	/**
	 * Validate an ordered generic Reveal data-attribute list atomically.
	 *
	 * @param mixed $value Candidate ordered name/value records.
	 * @return array<string, string>|null Valid attributes keyed in source order.
	 */
	public function reveal_data( mixed $value ): ?array {
		if ( ! is_array( $value ) || count( $value ) > 100 ) {
			return null;
		}

		$attributes = array();
		foreach ( $value as $item ) {
			if (
				! is_array( $item )
				|| 2 !== count( $item )
				|| ! array_key_exists( 'name', $item )
				|| ! array_key_exists( 'value', $item )
				|| ! is_string( $item['name'] )
				|| ! is_string( $item['value'] )
			) {
				return null;
			}

			$name = $item['name'];
			if (
				strlen( $name ) > 64
				|| 1 !== preg_match( '/^data-[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $name )
				|| str_starts_with( $name, 'data-presenter-' )
				|| in_array( $name, self::TYPED_DATA_ATTRIBUTES, true )
				|| array_key_exists( $name, $attributes )
				|| strlen( $item['value'] ) > 65535
				|| ! $this->valid_url( $name, $item['value'] )
			) {
				return null;
			}

			$attributes[ $name ] = $item['value'];
		}

		return $attributes;
	}

	/**
	 * Validate a Reveal/CSS background-size value supported by Presenter.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool Whether the exact value is safe.
	 */
	public function background_size( mixed $value ): bool {
		return is_string( $value )
			&& 1 === preg_match( '/^(?:auto|cover|contain)(?:\s+(?:auto|(?:100|[1-9]?\d)%))?$/', $value );
	}

	/**
	 * Validate an exact HTTP(S) or root-relative presentation resource URL.
	 *
	 * @param string $value Candidate URL.
	 * @return bool Whether the exact value is safe.
	 */
	public function resource_url( string $value ): bool {
		if ( str_starts_with( $value, '//' ) ) {
			$host = wp_parse_url( 'https:' . $value, PHP_URL_HOST );

			return is_string( $host )
				&& '' !== $host
				&& 0 === preg_match( '/[\x00-\x1F\x7F]/', $value );
		}

		if ( str_starts_with( $value, '/' ) ) {
			return 0 === preg_match( '/[\x00-\x1F\x7F]/', $value );
		}

		$scheme = wp_parse_url( $value, PHP_URL_SCHEME );

		return is_string( $scheme )
			&& in_array( strtolower( $scheme ), array( 'http', 'https' ), true )
			&& esc_url_raw( $value, array( 'http', 'https' ) ) === $value;
	}

	/**
	 * Validate URL-bearing Reveal attributes without rewriting them.
	 *
	 * @param string $name  Attribute name.
	 * @param string $value Attribute value.
	 * @return bool Whether the value is safe to emit.
	 */
	private function valid_url( string $name, string $value ): bool {
		if ( ! in_array( $name, self::URL_DATA_ATTRIBUTES, true ) ) {
			return true;
		}

		$urls = 'data-background-video' === $name ? explode( ',', $value ) : array( $value );
		foreach ( $urls as $url ) {
			$url = trim( $url );
			if ( '' === $url || ! $this->resource_url( $url ) ) {
				return false;
			}
		}

		return true;
	}
}

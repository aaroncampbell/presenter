<?php
/**
 * Canonical migration value encoding.
 *
 * @package Presenter
 */

namespace Presenter;

use InvalidArgumentException;
use JsonException;

/**
 * Projects PHP values into a typed, ordered, deterministic JSON representation.
 */
final class Migration_Value_Encoder {
	/**
	 * Encode a value without losing PHP type or array/property order.
	 *
	 * @param mixed $value Value to encode.
	 * @return string Canonical JSON.
	 * @throws InvalidArgumentException|JsonException When the value is unsupported or JSON encoding fails.
	 */
	public static function encode( mixed $value ): string {
		$encoded = wp_json_encode( self::project( $value ), JSON_UNESCAPED_SLASHES );
		if ( false === $encoded ) {
			throw new JsonException( 'Migration value encoding failed.' );
		}

		return $encoded;
	}

	/**
	 * Project one PHP value into tagged JSON-safe nodes.
	 *
	 * @param mixed $value Value to project.
	 * @return array<string, mixed> Tagged node.
	 * @throws InvalidArgumentException When the value type is unsupported.
	 */
	private static function project( mixed $value ): array {
		if ( null === $value ) {
			return array( 'type' => 'null' );
		}

		if ( is_bool( $value ) ) {
			return array(
				'type'  => 'bool',
				'value' => $value,
			);
		}

		if ( is_int( $value ) ) {
			return array(
				'type'  => 'int',
				'value' => (string) $value,
			);
		}

		if ( is_float( $value ) ) {
			return array(
				'type'  => 'float',
				'value' => self::encode_float( $value ),
			);
		}

		if ( is_string( $value ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary-safe canonical representation, not executable-code obfuscation.
			$encoded_value = base64_encode( $value );

			return array(
				'type'  => 'string',
				'value' => $encoded_value,
			);
		}

		if ( is_array( $value ) ) {
			$entries = array();
			foreach ( $value as $key => $item ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary-safe canonical key representation, not executable-code obfuscation.
				$encoded_key = is_int( $key ) ? (string) $key : base64_encode( $key );

				$entries[] = array(
					'keyType' => is_int( $key ) ? 'int' : 'string',
					'key'     => $encoded_key,
					'value'   => self::project( $item ),
				);
			}

			return array(
				'type'    => 'array',
				'entries' => $entries,
			);
		}

		if ( is_object( $value ) ) {
			$properties = array();
			foreach ( get_object_vars( $value ) as $name => $property ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary-safe canonical property representation, not executable-code obfuscation.
				$encoded_name = base64_encode( $name );
				$properties[] = array(
					'name'  => $encoded_name,
					'value' => self::project( $property ),
				);
			}

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary-safe canonical class representation, not executable-code obfuscation.
			$encoded_class = base64_encode( get_class( $value ) );

			return array(
				'type'       => 'object',
				'class'      => $encoded_class,
				'properties' => $properties,
			);
		}

		throw new InvalidArgumentException( 'Migration values cannot contain resources.' );
	}

	/**
	 * Encode a float deterministically, including non-finite and negative zero.
	 *
	 * @param float $value Float value.
	 * @return string Stable float representation.
	 * @throws JsonException When a finite float cannot be encoded.
	 */
	private static function encode_float( float $value ): string {
		if ( is_nan( $value ) ) {
			return 'nan';
		}

		if ( is_infinite( $value ) ) {
			return 0 < $value ? 'infinity' : '-infinity';
		}

		$encoded = wp_json_encode( $value, JSON_PRESERVE_ZERO_FRACTION );
		if ( false === $encoded ) {
			throw new JsonException( 'Migration float encoding failed.' );
		}

		return $encoded;
	}
}

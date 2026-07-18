<?php
/**
 * Exact retained Presenter 1.x metadata payload.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Captures rollback metadata without collapsing rows or coercing values.
 */
final class Legacy_Meta_Payload {
	/** Presenter 1.x repeated slide metadata key. */
	private const SLIDES_KEY = '_presenter_slides';

	/** Presenter 1.x theme metadata key. */
	private const THEME_KEY = '_presenter-theme';

	/** Presenter short URL metadata key. */
	private const SHORT_URL_KEY = '_presenter-short-url';

	/**
	 * Create a retained payload.
	 *
	 * @param array{exists: bool, values: array<int, mixed>} $slides    Slide rows.
	 * @param array{exists: bool, values: array<int, mixed>} $theme     Theme rows.
	 * @param array{exists: bool, values: array<int, mixed>} $short_url Short URL rows.
	 */
	private function __construct(
		private array $slides,
		private array $theme,
		private array $short_url
	) {}

	/**
	 * Capture all retained legacy rows for one post.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return self|null Payload, or null for an invalid post ID.
	 */
	public static function capture( int $post_id ): ?self {
		if ( $post_id < 1 ) {
			return null;
		}

		return new self(
			self::capture_key( $post_id, self::SLIDES_KEY ),
			self::capture_key( $post_id, self::THEME_KEY ),
			self::capture_key( $post_id, self::SHORT_URL_KEY )
		);
	}

	/**
	 * Get an isolated copy of the legacy slide rows.
	 *
	 * @return array{exists: bool, values: array<int, mixed>} Slide payload.
	 */
	public function slides(): array {
		return self::copy_entry( $this->slides );
	}

	/**
	 * Get an isolated copy of the legacy theme rows.
	 *
	 * @return array{exists: bool, values: array<int, mixed>} Theme payload.
	 */
	public function theme(): array {
		return self::copy_entry( $this->theme );
	}

	/**
	 * Get an isolated copy of the legacy short URL rows.
	 *
	 * @return array{exists: bool, values: array<int, mixed>} Short URL payload.
	 */
	public function short_url(): array {
		return self::copy_entry( $this->short_url );
	}

	/**
	 * Get the complete version-independent hashing/backup representation.
	 *
	 * @return array<string, array{exists: bool, values: array<int, mixed>}> Payload.
	 */
	public function to_array(): array {
		return array(
			'slides'   => $this->slides(),
			'theme'    => $this->theme(),
			'shortUrl' => $this->short_url(),
		);
	}

	/**
	 * Capture one metadata key with explicit existence.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Metadata key.
	 * @return array{exists: bool, values: array<int, mixed>} Captured entry.
	 */
	private static function capture_key( int $post_id, string $meta_key ): array {
		$values = get_post_meta( $post_id, $meta_key, false );

		return array(
			'exists' => metadata_exists( 'post', $post_id, $meta_key ),
			'values' => is_array( $values ) ? self::copy_values( array_values( $values ) ) : array(),
		);
	}

	/**
	 * Copy one payload entry.
	 *
	 * @param array{exists: bool, values: array<int, mixed>} $entry Payload entry.
	 * @return array{exists: bool, values: array<int, mixed>} Isolated entry.
	 */
	private static function copy_entry( array $entry ): array {
		return array(
			'exists' => $entry['exists'],
			'values' => self::copy_values( $entry['values'] ),
		);
	}

	/**
	 * Recursively isolate values returned to callers.
	 *
	 * @param array<array-key, mixed> $values Values to copy.
	 * @return array<array-key, mixed> Isolated values.
	 */
	private static function copy_values( array $values ): array {
		foreach ( $values as $key => $value ) {
			if ( is_array( $value ) ) {
				$values[ $key ] = self::copy_values( $value );
			} elseif ( is_object( $value ) ) {
				$copy = clone $value;
				foreach ( get_object_vars( $copy ) as $property => $property_value ) {
					$copy->{$property} = is_array( $property_value )
						? self::copy_values( $property_value )
						: ( is_object( $property_value ) ? self::copy_values( array( $property_value ) )[0] : $property_value );
				}
				$values[ $key ] = $copy;
			}
		}

		return $values;
	}
}

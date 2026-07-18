<?php
/**
 * Legacy deck snapshot capture service.
 *
 * @package Presenter
 */

namespace Presenter;

use WP_Post;

/**
 * Captures legacy migration inputs without writing to WordPress.
 */
final class Legacy_Deck_Snapshotter {
	/**
	 * Create the snapshotter.
	 *
	 * @param Legacy_Slide_Source $slides Legacy slide source.
	 */
	public function __construct( private Legacy_Slide_Source $slides ) {}

	/**
	 * Capture one legacy slideshow.
	 *
	 * @param int $post_id Candidate slideshow post ID.
	 * @return Legacy_Deck_Snapshot|null Snapshot, or null when ineligible.
	 */
	public function capture( int $post_id ): ?Legacy_Deck_Snapshot {
		if ( $post_id < 1 || ! $this->slides->has_slides( $post_id ) ) {
			return null;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'slideshow' !== $post->post_type ) {
			return null;
		}

		$raw_slides = $this->slides->read_slides( $post_id );
		if ( array() === $raw_slides ) {
			return null;
		}

		$legacy_meta = Legacy_Meta_Payload::capture( $post_id );
		if ( null === $legacy_meta ) {
			return null;
		}

		$warnings      = array();
		$theme_entry   = $legacy_meta->theme();
		$short_entry   = $legacy_meta->short_url();
		$raw_theme     = $theme_entry['values'][0] ?? '';
		$raw_short_url = $short_entry['values'][0] ?? '';
		$theme         = $this->string_meta( $raw_theme, 'invalid_legacy_theme_meta', $warnings );
		$short_url     = $this->string_meta( $raw_short_url, 'invalid_legacy_short_url_meta', $warnings );
		$source        = array(
			'post'        => array(
				'id'           => $post->ID,
				'post_type'    => $post->post_type,
				'slug'         => $post->post_name,
				'title'        => $post->post_title,
				'excerpt'      => $post->post_excerpt,
				'menu_order'   => $post->menu_order,
				'status'       => $post->post_status,
				'password'     => $post->post_password,
				'post_content' => $post->post_content,
			),
			'legacy_meta' => array(
				'slides'   => array(
					'exists' => true,
					'values' => array_values( $raw_slides ),
				),
				'theme'    => $theme_entry,
				'shortUrl' => $short_entry,
			),
		);
		$fingerprint   = hash_hmac( 'sha256', maybe_serialize( $source ), wp_salt( 'auth' ) );

		return new Legacy_Deck_Snapshot(
			$post->ID,
			$post->post_name,
			$post->post_title,
			$post->post_excerpt,
			$post->menu_order,
			$post->post_status,
			$post->post_password,
			$post->post_content,
			$theme,
			$short_url,
			$raw_slides,
			$fingerprint,
			$warnings
		);
	}

	/**
	 * Read string metadata without coercing arrays or objects.
	 *
	 * @param mixed              $value    Raw metadata value.
	 * @param string             $warning  Warning for a malformed value.
	 * @param array<int, string> $warnings Capture warning codes.
	 * @return string Metadata value or an empty string.
	 */
	private function string_meta( mixed $value, string $warning, array &$warnings ): string {
		if ( is_string( $value ) ) {
			return $value;
		}

		$warnings[] = $warning;

		return '';
	}
}

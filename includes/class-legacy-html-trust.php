<?php
/**
 * Content-bound trust for legacy Presenter HTML.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Distinguishes explicitly saved administrator HTML from unreviewed metadata.
 */
final class Legacy_HTML_Trust {
	/** Private site-keyed fingerprint for one exact legacy slide set. */
	private const META_KEY = '_presenter_legacy_html_trust_v1';

	/**
	 * Determine whether exactly one valid trust fingerprint is stored.
	 *
	 * @param int               $post_id Slideshow post ID.
	 * @param array<int, mixed> $slides  Raw legacy slide values.
	 * @return bool Whether this exact slide set was saved by a trusted user.
	 */
	public function is_trusted( int $post_id, array $slides ): bool {
		$stored = $post_id > 0 ? get_post_meta( $post_id, self::META_KEY, false ) : array();
		if ( 1 !== count( $stored ) || ! is_string( $stored[0] ) ) {
			return false;
		}

		return hash_equals( $this->fingerprint( $post_id, $slides ), $stored[0] );
	}

	/**
	 * Synchronize trust after a complete authorized legacy save.
	 *
	 * Users without unfiltered_html can only store KSES-filtered HTML, so their
	 * saves deliberately clear any prior raw-HTML trust. Trusted saves bind the
	 * marker to the exact values WordPress persisted.
	 *
	 * @param int               $post_id             Slideshow post ID.
	 * @param array<int, mixed> $slides              Persisted legacy slide values.
	 * @param bool              $unfiltered_html_user Whether the saver may author raw HTML.
	 * @return void
	 */
	public function synchronize( int $post_id, array $slides, bool $unfiltered_html_user ): void {
		delete_post_meta( $post_id, self::META_KEY );

		if ( $post_id > 0 && $unfiltered_html_user && array() !== $slides && metadata_exists( 'post', $post_id, '_presenter_slides' ) ) {
			add_post_meta( $post_id, self::META_KEY, $this->fingerprint( $post_id, $slides ), true );
		}
	}

	/**
	 * Apply the safe default for untrusted legacy content.
	 *
	 * @param string $html Stored legacy HTML.
	 * @return string WordPress post-HTML allow-list output.
	 */
	public function sanitize( string $html ): string {
		return wp_kses_post( $html );
	}

	/**
	 * Create a site-keyed fingerprint for one post and exact slide sequence.
	 *
	 * @param int               $post_id Slideshow post ID.
	 * @param array<int, mixed> $slides  Raw legacy slide values.
	 * @return string Site-keyed SHA-256 fingerprint.
	 */
	private function fingerprint( int $post_id, array $slides ): string {
		return hash_hmac(
			'sha256',
			maybe_serialize(
				array(
					'postId' => $post_id,
					'slides' => array_values( $slides ),
				)
			),
			wp_salt( 'auth' )
		);
	}
}

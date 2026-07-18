<?php
/**
 * Presenter deck storage-mode resolution.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Resolves whether a slideshow uses the retained legacy or native storage path.
 */
final class Deck_Mode {
	/**
	 * Private cutover marker written only after native content is verified.
	 */
	public const META_KEY = '_presenter_deck_mode';

	/**
	 * Legacy compatibility mode.
	 */
	public const LEGACY = 'legacy';

	/**
	 * Native block mode.
	 */
	public const NATIVE = 'native';

	/**
	 * Read-only legacy slide source.
	 *
	 * @var Legacy_Slide_Source
	 */
	private Legacy_Slide_Source $legacy_slides;

	/**
	 * Create the deck-mode resolver.
	 *
	 * @param Legacy_Slide_Source $legacy_slides Read-only legacy source.
	 */
	public function __construct( Legacy_Slide_Source $legacy_slides ) {
		$this->legacy_slides = $legacy_slides;
	}

	/**
	 * Resolve the authoritative storage mode for a slideshow.
	 *
	 * A verified native cutover is the only state allowed to override retained
	 * legacy metadata. Missing or unrecognized markers fail safely to legacy.
	 * Posts without legacy metadata remain eligible for normal native routing.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return string One of the class mode constants.
	 */
	public function mode( int $post_id ): string {
		if ( $this->has_native_cutover( $post_id ) ) {
			return self::NATIVE;
		}

		return $this->legacy_slides->has_slides( $post_id ) ? self::LEGACY : self::NATIVE;
	}

	/**
	 * Determine whether exactly one valid native cutover marker is stored.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return bool Whether migration has explicitly cut this deck over.
	 */
	public function has_native_cutover( int $post_id ): bool {
		$stored_modes = $post_id > 0 ? get_post_meta( $post_id, self::META_KEY, false ) : array();

		return array( self::NATIVE ) === $stored_modes;
	}

	/**
	 * Determine whether the legacy compatibility path is authoritative.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return bool Whether legacy rendering and editing should be used.
	 */
	public function uses_legacy_runtime( int $post_id ): bool {
		return self::LEGACY === $this->mode( $post_id );
	}

	/**
	 * Determine whether the post is eligible for native block routing.
	 *
	 * The native router still validates the stored block tree before selecting a
	 * presentation template.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return bool Whether legacy storage does not own the route.
	 */
	public function uses_native_runtime( int $post_id ): bool {
		return self::NATIVE === $this->mode( $post_id );
	}
}

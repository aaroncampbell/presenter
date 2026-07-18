<?php
/**
 * Slideshow metadata registration.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Registers publishing metadata used by both legacy and native decks.
 */
final class Meta implements Hook_Provider {
	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ), 20 );
	}

	/**
	 * Register slideshow metadata.
	 */
	public function register(): void {
		register_post_meta(
			'slideshow',
			Deck_Mode::META_KEY,
			array(
				'auth_callback'     => array( $this, 'can_edit' ),
				'default'           => '',
				'revisions_enabled' => true,
				'sanitize_callback' => array( $this, 'sanitize_deck_mode' ),
				'show_in_rest'      => false,
				'single'            => true,
				'type'              => 'string',
			)
		);

		register_post_meta(
			'slideshow',
			'_presenter-short-url',
			array(
				'auth_callback'     => array( $this, 'can_edit' ),
				'default'           => '',
				'revisions_enabled' => true,
				'sanitize_callback' => array( $this, 'sanitize_short_url' ),
				'show_in_rest'      => array(
					'schema' => array(
						'format' => 'uri',
						'type'   => 'string',
					),
				),
				'single'            => true,
				'type'              => 'string',
			)
		);
	}

	/**
	 * Accept only the migration-owned native cutover value.
	 *
	 * Unknown values remain empty so they can never bypass legacy mode.
	 *
	 * @param mixed $value Submitted metadata value.
	 * @return string Valid native mode or an empty string.
	 */
	public function sanitize_deck_mode( mixed $value ): string {
		return Deck_Mode::NATIVE === $value ? Deck_Mode::NATIVE : '';
	}

	/**
	 * Sanitize a presentation short URL.
	 *
	 * @param mixed $value Submitted metadata value.
	 * @return string Sanitized HTTP(S) URL or an empty string.
	 */
	public function sanitize_short_url( mixed $value ): string {
		return sanitize_url( (string) $value, array( 'http', 'https' ) );
	}

	/**
	 * Authorize writes through the post's normal edit capability.
	 *
	 * @param bool   $allowed   Existing authorization result.
	 * @param string $meta_key  Registered metadata key.
	 * @param int    $object_id Slideshow post ID.
	 * @return bool Whether the current user can edit the slideshow.
	 */
	public function can_edit( bool $allowed, string $meta_key, int $object_id ): bool {
		unset( $allowed, $meta_key );

		return current_user_can( 'edit_post', $object_id );
	}
}

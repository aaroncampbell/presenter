<?php
/**
 * Presenter migration lock ownership handle.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Identifies one time-bounded migration lock owner.
 */
final class Migration_Lock_Handle {
	/**
	 * Create an ownership handle.
	 *
	 * @param int    $post_id    Locked slideshow post ID.
	 * @param string $token      Private owner token.
	 * @param int    $expires_at Unix expiry timestamp.
	 */
	public function __construct(
		private int $post_id,
		private string $token,
		private int $expires_at
	) {}

	/** Get the locked slideshow post ID. */
	public function post_id(): int {
		return $this->post_id;
	}

	/** Get the private owner token. */
	public function token(): string {
		return $this->token;
	}

	/** Get the Unix expiry timestamp. */
	public function expires_at(): int {
		return $this->expires_at;
	}
}

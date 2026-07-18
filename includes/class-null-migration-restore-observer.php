<?php
/**
 * No-op Presenter migration restore observer.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Keeps production restore orchestration free from test fault injection.
 */
final class Null_Migration_Restore_Observer implements Migration_Restore_Observer {
	/**
	 * Observe no production phase side effects.
	 *
	 * @param string $phase   Content-free phase name.
	 * @param int    $post_id Slideshow post ID.
	 */
	public function checkpoint( string $phase, int $post_id ): void {
		unset( $phase, $post_id );
	}
}

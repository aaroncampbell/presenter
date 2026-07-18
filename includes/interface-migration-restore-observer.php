<?php
/**
 * Presenter migration restore phase observer.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Provides deterministic restore race and failure checkpoints for tests.
 */
interface Migration_Restore_Observer {
	/**
	 * Observe one content-free restore phase.
	 *
	 * @param string $phase   Stable phase name.
	 * @param int    $post_id Slideshow post ID.
	 */
	public function checkpoint( string $phase, int $post_id ): void;
}

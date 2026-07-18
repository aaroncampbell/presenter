<?php
/**
 * Presenter migration apply phase observer.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Provides deterministic race and failure checkpoints without exposing data.
 */
interface Migration_Apply_Observer {
	/**
	 * Observe one content-free apply phase.
	 *
	 * Production uses a no-op implementation. Tests may mutate WordPress state or
	 * throw to prove compensation at exact transaction boundaries.
	 *
	 * @param string $phase   Stable content-free phase name.
	 * @param int    $post_id Slideshow post ID.
	 */
	public function checkpoint( string $phase, int $post_id ): void;
}

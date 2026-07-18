<?php
/**
 * Deterministic migration restore observer for integration tests.
 *
 * @package Presenter
 */

use Presenter\Migration_Restore_Observer;

/**
 * Invokes one injected callback at every restore phase.
 */
final class Presenter_Test_Migration_Restore_Observer implements Migration_Restore_Observer {
	/**
	 * Injected phase callback.
	 *
	 * @var Closure(string, int): void
	 */
	private Closure $callback;

	/**
	 * Create the observer.
	 *
	 * @param Closure(string, int): void|null $callback Optional phase callback.
	 */
	public function __construct( ?Closure $callback = null ) {
		$this->callback = $callback ?? static function (): void {};
	}

	/**
	 * Invoke the deterministic phase callback.
	 *
	 * @param string $phase   Stable restore phase.
	 * @param int    $post_id Slideshow post ID.
	 */
	public function checkpoint( string $phase, int $post_id ): void {
		( $this->callback )( $phase, $post_id );
	}
}

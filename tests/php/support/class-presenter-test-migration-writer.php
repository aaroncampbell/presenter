<?php
/**
 * Instrumented migration writer for integration tests.
 *
 * @package Presenter
 */

use Presenter\Atomic_Migration_Post_Content_Writer;
use Presenter\Migration_Post_Content_Writer;

/**
 * Observes calls around the production atomic content primitive.
 */
final class Presenter_Test_Migration_Writer implements Migration_Post_Content_Writer {
	/**
	 * Number of attempted content swaps.
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * Callback invoked immediately before each swap.
	 *
	 * @var Closure(int, int, array<string, mixed>, string): void
	 */
	private Closure $before_call;

	/**
	 * Create the writer.
	 *
	 * @param Closure(int, int, array<string, mixed>, string): void|null $before_call Optional call observer.
	 */
	public function __construct( ?Closure $before_call = null ) {
		$this->before_call = $before_call ?? static function (): void {};
	}

	/**
	 * Observe then perform the real atomic content swap.
	 *
	 * @param int                  $post_id            Slideshow post ID.
	 * @param array<string, mixed> $expected_post       Exact expected post fields.
	 * @param string               $replacement_content Replacement content.
	 */
	public function compare_and_swap( int $post_id, array $expected_post, string $replacement_content ): bool {
		++$this->calls;
		( $this->before_call )( $this->calls, $post_id, $expected_post, $replacement_content );

		return ( new Atomic_Migration_Post_Content_Writer() )->compare_and_swap( $post_id, $expected_post, $replacement_content );
	}
}

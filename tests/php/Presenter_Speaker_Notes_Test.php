<?php
/**
 * Presenter speaker notes policy tests.
 *
 * @package Presenter
 */

use Presenter\Speaker_Notes;

require_once dirname( __DIR__, 2 ) . '/includes/class-speaker-notes.php';

/**
 * Verify HTML notes sanitization and lossless-migration behavior.
 */
class Presenter_Speaker_Notes_Test extends Presenter_Test_Case {
	/**
	 * Allowed HTML survives sanitization exactly.
	 */
	public function test_allowed_html_round_trips_without_changes(): void {
		$notes  = '<p>Remember <strong>why</strong> this matters.<br><a href="https://example.test/notes" rel="noopener">Source</a></p><footer><cite>Speaker guide</cite></footer><ul><li>First</li></ul>';
		$policy = new Speaker_Notes();

		$this->assertSame( $notes, $policy->sanitize_html( $notes ) );
		$this->assertTrue( $policy->allows_lossless_html( $notes ) );
	}

	/**
	 * HTML detection distinguishes markup from angle brackets in prose.
	 */
	public function test_contains_html_uses_the_wordpress_html_parser(): void {
		$policy = new Speaker_Notes();

		$this->assertTrue( $policy->contains_html( '<p>Actual HTML</p>' ) );
		$this->assertFalse( $policy->contains_html( 'Keep x < y and y > z.' ) );
		$this->assertFalse( $policy->contains_html( 'Return Array<string, int>.' ) );
	}

	/**
	 * Unsafe tags, attributes, and URL protocols fail the lossless contract.
	 */
	public function test_unsafe_html_cannot_be_represented_losslessly(): void {
		$notes  = '<p id="reveal-hook" style="color:red">Note<script>alert(1)</script><a href="javascript:alert(2)">link</a></p>';
		$policy = new Speaker_Notes();

		$this->assertFalse( $policy->allows_lossless_html( $notes ) );
		$this->assertSame(
			'<p>Notealert(1)<a href="alert(2)">link</a></p>',
			$policy->sanitize_html( $notes )
		);
	}

	/**
	 * Rendering sanitizes HTML and keeps the existing text formats inert.
	 */
	public function test_render_applies_the_selected_format_policy(): void {
		$policy = new Speaker_Notes();

		$this->assertSame(
			'<aside class="notes"><p><em>Allowed</em> removed</p></aside>',
			$policy->render( '<p><em>Allowed</em> <iframe>removed</iframe></p>', 'html' )
		);
		$this->assertSame(
			'<aside class="notes" data-markdown=""><p><em>Allowed</em></p></aside>',
			$policy->render( '<p><em>Allowed</em></p>', 'markdown-html' )
		);
		$this->assertSame(
			'<aside class="notes" data-markdown="">**Bold** &lt;script&gt;</aside>',
			$policy->render( '**Bold** <script>', 'markdown' )
		);
		$this->assertSame(
			'<aside class="notes">Plain &amp; &lt;strong&gt;inert&lt;/strong&gt;</aside>',
			$policy->render( 'Plain & <strong>inert</strong>', 'plain' )
		);
	}
}

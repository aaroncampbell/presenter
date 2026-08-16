<?php
/**
 * Explicit legacy HTML trust administration tests.
 *
 * @package Presenter
 */

use Presenter\Deck_Mode;
use Presenter\Legacy_Deck_Inventory;
use Presenter\Legacy_HTML_Trust;
use Presenter\Legacy_HTML_Trust_Admin;
use Presenter\WordPress_Legacy_Slide_Source;

/** Verify that raw legacy HTML trust requires explicit, bounded authority. */
final class Presenter_Legacy_HTML_Trust_Admin_Test extends Presenter_Test_Case {
	/** Preserve request globals around each test. */
	public function set_up(): void {
		parent::set_up();
		$_GET                      = array();
		$_POST                     = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$administrator_id          = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator_id );
	}

	/** Restore request globals and identity after each test. */
	public function tear_down(): void {
		$_GET                      = array();
		$_POST                     = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** One selected request trusts only the exact current legacy slide sequence. */
	public function test_selected_trust_is_content_bound_and_invalidates_after_change(): void {
		$post_id = $this->legacy_slideshow( 'Explicit trust' );
		$trust   = new Legacy_HTML_Trust();
		$slides  = new WordPress_Legacy_Slide_Source();
		$admin   = $this->admin( $trust, $slides );

		$this->post_request( array( $post_id ) );
		$this->assertSame( 1, $admin->process_trust_request() );
		$this->assertTrue( $trust->is_trusted( $post_id, $slides->read_slides( $post_id ) ) );

		add_post_meta(
			$post_id,
			'_presenter_slides',
			(object) array(
				'number'  => 2,
				'content' => '<script>window.changed = true;</script>',
			)
		);

		$this->assertFalse( $trust->is_trusted( $post_id, $slides->read_slides( $post_id ) ) );
	}

	/** A selected page-bounded request can explicitly trust multiple editable decks. */
	public function test_selected_batch_trusts_each_exact_current_deck(): void {
		$first  = $this->legacy_slideshow( 'First selected deck' );
		$second = $this->legacy_slideshow( 'Second selected deck' );
		$trust  = new Legacy_HTML_Trust();
		$slides = new WordPress_Legacy_Slide_Source();
		$admin  = $this->admin( $trust, $slides );

		$this->post_request( array( $first, $second ) );

		$this->assertSame( 2, $admin->process_trust_request() );
		$this->assertTrue( $trust->is_trusted( $first, $slides->read_slides( $first ) ) );
		$this->assertTrue( $trust->is_trusted( $second, $slides->read_slides( $second ) ) );
	}

	/** One explicit all-decks request trusts every eligible exact current deck. */
	public function test_all_scope_trusts_every_eligible_deck_without_posted_ids(): void {
		$first  = $this->legacy_slideshow( 'First eligible deck' );
		$second = $this->legacy_slideshow( 'Second eligible deck' );
		$native = $this->legacy_slideshow( 'Native ineligible deck' );
		add_post_meta( $native, Deck_Mode::META_KEY, Deck_Mode::NATIVE, true );
		$trust  = new Legacy_HTML_Trust();
		$slides = new WordPress_Legacy_Slide_Source();
		$admin  = $this->admin( $trust, $slides );

		$this->post_request( array(), 'all' );

		$this->assertSame( 2, $admin->process_trust_request() );
		$this->assertTrue( $trust->is_trusted( $first, $slides->read_slides( $first ) ) );
		$this->assertTrue( $trust->is_trusted( $second, $slides->read_slides( $second ) ) );
		$this->assertFalse( $trust->is_trusted( $native, $slides->read_slides( $native ) ) );
	}

	/** The screen offers native per-page selection and a counted all-decks action. */
	public function test_screen_renders_select_all_and_counted_trust_all_controls(): void {
		$this->legacy_slideshow( 'First trust control deck' );
		$this->legacy_slideshow( 'Second trust control deck' );

		ob_start();
		$this->admin()->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="cb-select-all-1"', $output );
		$this->assertStringContainsString( 'Select all eligible decks on this page', $output );
		$this->assertStringContainsString( 'name="trust_scope" value="selected"', $output );
		$this->assertStringContainsString( 'name="trust_scope" value="all"', $output );
		$this->assertStringContainsString( 'Trust all 2 decks', $output );
	}

	/** A page without eligible rows does not show an inert Select All control. */
	public function test_screen_hides_select_all_when_page_has_no_eligible_decks(): void {
		$post_id = $this->legacy_slideshow( 'Already trusted control deck' );
		$trust   = new Legacy_HTML_Trust();
		$slides  = new WordPress_Legacy_Slide_Source();
		$trust->synchronize( $post_id, $slides->read_slides( $post_id ), true );

		ob_start();
		$this->admin( $trust, $slides )->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'id="cb-select-all-1"', $output );
		$this->assertStringNotContainsString( 'Select all eligible decks on this page', $output );
	}

	/** Eligible untrusted decks sort before already trusted decks regardless of ID. */
	public function test_screen_orders_eligible_untrusted_decks_first(): void {
		$trusted_id = $this->legacy_slideshow( 'Already trusted deck' );
		$this->legacy_slideshow( 'Still untrusted deck' );
		$trust  = new Legacy_HTML_Trust();
		$slides = new WordPress_Legacy_Slide_Source();
		$trust->synchronize( $trusted_id, $slides->read_slides( $trusted_id ), true );

		ob_start();
		$this->admin( $trust, $slides )->render_page();
		$output = (string) ob_get_clean();

		$this->assertLessThan(
			strpos( $output, 'Already trusted deck' ),
			strpos( $output, 'Still untrusted deck' )
		);
		$this->assertStringContainsString( 'Trust all 1 deck', $output );
	}

	/** An eligible deck remains on page one even when lower-ID trusted decks fill a page. */
	public function test_untrusted_first_ordering_applies_before_pagination(): void {
		$trust  = new Legacy_HTML_Trust();
		$slides = new WordPress_Legacy_Slide_Source();
		for ( $index = 1; $index <= 20; ++$index ) {
			$post_id = $this->legacy_slideshow( 'Trusted page filler ' . $index );
			$trust->synchronize( $post_id, $slides->read_slides( $post_id ), true );
		}
		$this->legacy_slideshow( 'Untrusted deck from page two' );

		ob_start();
		$this->admin( $trust, $slides )->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Untrusted deck from page two', $output );
		$this->assertStringNotContainsString( 'Trusted page filler 20', $output );
	}

	/** The inventory shows status but cannot select retained metadata after native cutover. */
	public function test_native_cutover_decks_are_visible_but_not_selectable(): void {
		$post_id = $this->legacy_slideshow( 'Native cutover deck' );
		add_post_meta( $post_id, Deck_Mode::META_KEY, Deck_Mode::NATIVE, true );

		ob_start();
		$this->admin()->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Native cutover deck', $output );
		$this->assertStringContainsString( 'Native blocks', $output );
		$this->assertStringNotContainsString( 'value="' . $post_id . '"', $output );
	}

	/** Users lacking administrator and raw-HTML authority cannot open the screen. */
	public function test_screen_rejects_users_without_both_capabilities(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		try {
			$this->admin()->render_page();
			$this->fail( 'Expected the trust screen to reject this user.' );
		} catch ( WPDieException $exception ) {
			$this->assertStringContainsString( 'not allowed to trust', $exception->getMessage() );
		}
	}

	/**
	 * Create a legacy slideshow with raw script content.
	 *
	 * @param string $title Slideshow title.
	 */
	private function legacy_slideshow( string $title ): int {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array( 'post_title' => $title )
		);
		add_post_meta(
			$post_id,
			'_presenter_slides',
			(object) array(
				'number'  => 1,
				'title'   => $title,
				'content' => '<script>window.presenterTrusted = true;</script>',
				'class'   => '',
			)
		);

		return $post_id;
	}

	/**
	 * Build the independently testable admin adapter.
	 *
	 * @param Legacy_HTML_Trust|null             $trust  Optional shared trust policy.
	 * @param WordPress_Legacy_Slide_Source|null $slides Optional shared slide source.
	 */
	private function admin(
		?Legacy_HTML_Trust $trust = null,
		?WordPress_Legacy_Slide_Source $slides = null
	): Legacy_HTML_Trust_Admin {
		$trust  = $trust ?? new Legacy_HTML_Trust();
		$slides = $slides ?? new WordPress_Legacy_Slide_Source();

		return new Legacy_HTML_Trust_Admin(
			new Legacy_Deck_Inventory(),
			$slides,
			$trust,
			new Deck_Mode( $slides )
		);
	}

	/**
	 * Populate one authenticated selected-deck POST request.
	 *
	 * @param array<int, int> $post_ids Selected slideshow IDs.
	 * @param string          $scope    Trust scope.
	 */
	private function post_request( array $post_ids, string $scope = 'selected' ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'_wpnonce'          => wp_create_nonce( Legacy_HTML_Trust_Admin::TRUST_ACTION ),
			'post_ids'          => array_map( 'strval', $post_ids ),
			'presenter_confirm' => 'trust',
			'return_page'       => '1',
			'trust_scope'       => $scope,
		);
		$_REQUEST                  = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test fixture mirrors the authenticated form request for check_admin_referer().
	}
}

<?php
/**
 * Legacy slideshow save characterization tests.
 *
 * @package Presenter
 */

/**
 * Characterize and protect Presenter 1.x save behavior during modernization.
 */
class Presenter_Legacy_Save_Test extends Presenter_Test_Case {
	/**
	 * Original request data restored after every test.
	 *
	 * @var array<string, mixed>
	 */
	private $original_post_data;

	/**
	 * Preserve request globals.
	 */
	public function set_up(): void {
		parent::set_up();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Preserving the test harness request state, not processing a request.
		$this->original_post_data = $_POST;
	}

	/**
	 * Restore request globals and the anonymous user.
	 */
	public function tear_down(): void {
		$_POST = $this->original_post_data;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Programmatic saves without legacy editor fields preserve slide metadata.
	 */
	public function test_programmatic_save_without_nonce_preserves_legacy_metadata(): void {
		$post_id  = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'   => 'slideshow',
				'post_status' => 'publish',
			)
		);
		$sentinel = (object) array(
			'number'  => 1,
			'title'   => 'Existing',
			'content' => 'EXISTING-SLIDE',
		);
		add_post_meta( $post_id, '_presenter_slides', $sentinel );
		update_post_meta( $post_id, '_presenter-theme', '/existing/theme.css' );
		update_post_meta( $post_id, '_presenter-short-url', 'https://example.test/existing' );
		$_POST = array();

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Programmatic title update',
			)
		);

		$this->assertEquals( array( $sentinel ), get_post_meta( $post_id, '_presenter_slides', false ) );
		$this->assertSame( '/existing/theme.css', get_post_meta( $post_id, '_presenter-theme', true ) );
		$this->assertSame( 'https://example.test/existing', get_post_meta( $post_id, '_presenter-short-url', true ) );
	}

	/**
	 * An authorized classic-editor save rebuilds and renumbers posted slides.
	 */
	public function test_authorized_legacy_save_rebuilds_slides_in_posted_order(): void {
		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator_id );
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_author' => $administrator_id,
				'post_type'   => 'slideshow',
				'post_status' => 'publish',
			)
		);
		$_POST   = $this->legacy_editor_request();

		presenter::get_instance()->save_post_slideshow( $post_id, get_post( $post_id ), true );
		$slides = get_post_meta( $post_id, '_presenter_slides', false );

		$this->assertCount( 2, $slides );
		$this->assertSame( 1, $slides[0]->number );
		$this->assertSame( 'First', $slides[0]->title );
		$this->assertTrue( $slides[0]->notes['markdown'] );
		$this->assertSame( 'background-color', $slides[0]->data[0]->name );
		$this->assertSame( '#442266', $slides[0]->data[0]->value );
		$this->assertSame( 2, $slides[1]->number );
		$this->assertSame( 'Second', $slides[1]->title );
		$this->assertFalse( $slides[1]->notes['markdown'] );
		$this->assertSame( 'https://example.com/slides', get_post_meta( $post_id, '_presenter-short-url', true ) );
	}

	/**
	 * A valid nonce cannot bypass the post edit capability check.
	 */
	public function test_unauthorized_legacy_save_preserves_existing_slides(): void {
		$post_id  = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'   => 'slideshow',
				'post_status' => 'publish',
			)
		);
		$sentinel = (object) array(
			'number'  => 1,
			'title'   => 'Existing',
			'content' => 'EXISTING-SLIDE',
		);
		add_post_meta( $post_id, '_presenter_slides', $sentinel );
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );
		$_POST = $this->legacy_editor_request();

		presenter::get_instance()->save_post_slideshow( $post_id, get_post( $post_id ), true );

		$this->assertEquals( array( $sentinel ), get_post_meta( $post_id, '_presenter_slides', false ) );
	}

	/**
	 * Retained rollback metadata cannot reactivate writes after native cutover.
	 */
	public function test_native_cutover_ignores_legacy_editor_save_payload(): void {
		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator_id );
		$post_id  = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_author' => $administrator_id,
				'post_type'   => 'slideshow',
				'post_status' => 'publish',
			)
		);
		$sentinel = (object) array(
			'number'  => 1,
			'title'   => 'Retained rollback slide',
			'content' => 'RETAINED-ROLLBACK-SENTINEL',
		);
		add_post_meta( $post_id, '_presenter_slides', $sentinel );
		update_post_meta( $post_id, '_presenter-theme', '/retained/theme.css' );
		update_post_meta( $post_id, \Presenter\Deck_Mode::META_KEY, \Presenter\Deck_Mode::NATIVE );
		$_POST = $this->legacy_editor_request();

		presenter::get_instance()->save_post_slideshow( $post_id, get_post( $post_id ), true );

		$this->assertEquals( array( $sentinel ), get_post_meta( $post_id, '_presenter_slides', false ) );
		$this->assertSame( '/retained/theme.css', get_post_meta( $post_id, '_presenter-theme', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_presenter-short-url', true ) );
	}

	/**
	 * Create a complete classic-editor request payload for two slides.
	 *
	 * @return array<string, mixed>
	 */
	private function legacy_editor_request(): array {
		return array(
			'_presenter_nonce'    => wp_create_nonce( 'presenter_save_slideshow' ),
			'presenter_theme'     => '',
			'presenter_short_url' => 'https://example.com/slides',
			'slide-title'         => array(
				'4'       => 'First',
				'__new__' => '',
				'9'       => 'Second',
			),
			'slide-content'       => array(
				'4' => '<p>First content</p>',
				'9' => '<p>Second content</p>',
			),
			'slide-notes'         => array(
				'4' => array(
					'notes'    => 'Markdown note',
					'markdown' => 'true',
				),
				'9' => array( 'notes' => 'Plain note' ),
			),
			'slide-classes'       => array(
				'4' => 'first-class',
				'9' => '',
			),
			'slide-data'          => array(
				'4' => array( 'background-color' ),
			),
			'slide-data-value'    => array(
				'4' => array( '#442266' ),
			),
		);
	}
}

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
		$this->add_legacy_slide_fixture( $post_id );
		$_POST = $this->legacy_editor_request( $post_id );

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
		$_POST = $this->legacy_editor_request( $post_id );

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
		$_POST = $this->legacy_editor_request( $post_id );

		presenter::get_instance()->save_post_slideshow( $post_id, get_post( $post_id ), true );

		$this->assertEquals( array( $sentinel ), get_post_meta( $post_id, '_presenter_slides', false ) );
		$this->assertSame( '/retained/theme.css', get_post_meta( $post_id, '_presenter-theme', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_presenter-short-url', true ) );
	}

	/**
	 * A nonce created for one legacy deck cannot authorize another deck.
	 */
	public function test_legacy_save_nonce_cannot_be_replayed_across_posts(): void {
		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator_id );
		$source_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_author' => $administrator_id,
				'post_status' => 'publish',
			)
		);
		$target_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_author' => $administrator_id,
				'post_status' => 'publish',
			)
		);
		$this->add_legacy_slide_fixture( $source_id );
		$this->add_legacy_slide_fixture( $target_id );
		$before = get_post_meta( $target_id, '_presenter_slides', false );

		$_POST = $this->legacy_editor_request( $source_id );
		presenter::get_instance()->save_post_slideshow( $target_id, get_post( $target_id ), true );

		$this->assertSame( maybe_serialize( $before ), maybe_serialize( get_post_meta( $target_id, '_presenter_slides', false ) ) );
	}

	/**
	 * The legacy attributes box emits a nonce bound to its own slideshow.
	 */
	public function test_legacy_attributes_nonce_is_scoped_to_its_post(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array( 'post_status' => 'publish' )
		);
		$this->add_legacy_slide_fixture( $post_id );

		ob_start();
		presenter::get_instance()->slideshow_attributes_meta_box( get_post( $post_id ) );
		$output = ob_get_clean();
		$this->assertIsString( $output );
		$this->assertSame( 1, preg_match( '/name="_presenter_nonce" value="([^"]+)"/', $output, $matches ) );
		$this->assertSame( 1, wp_verify_nonce( $matches[1], 'presenter_save_slideshow:' . $post_id ) );
		$this->assertFalse( wp_verify_nonce( $matches[1], 'presenter_save_slideshow:' . ( $post_id + 1 ) ) );
	}

	/**
	 * A native block deck without a cutover marker cannot be downgraded.
	 */
	public function test_native_deck_without_legacy_metadata_rejects_legacy_payload(): void {
		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator_id );
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_author'  => $administrator_id,
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:presenter/deck --><!-- wp:presenter/slide /--><!-- /wp:presenter/deck -->',
			)
		);
		$_POST   = $this->legacy_editor_request( $post_id );

		presenter::get_instance()->save_post_slideshow( $post_id, get_post( $post_id ), true );

		$this->assertFalse( metadata_exists( 'post', $post_id, '_presenter_slides' ) );
		$this->assertSame( \Presenter\Deck_Mode::NATIVE, presenter_get_runtime()->deck_mode()->mode( $post_id ) );
	}

	/**
	 * Users without unfiltered_html receive core-equivalent KSES protection.
	 */
	public function test_author_legacy_save_strips_executable_html_from_slides_and_notes(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author_id );
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_author' => $author_id,
				'post_status' => 'publish',
			)
		);
		$this->add_legacy_slide_fixture( $post_id );
		$_POST                              = $this->legacy_editor_request( $post_id );
		$_POST['slide-content']['4']        = '<script>window.slideExploit = true;</script><img src="x" onerror="window.eventExploit=true">';
		$_POST['slide-notes']['4']['notes'] = '<script>window.notesExploit = true;</script><p onclick="window.notesClick=true">Safe note</p>';

		presenter::get_instance()->save_post_slideshow( $post_id, get_post( $post_id ), true );

		$slides = get_post_meta( $post_id, '_presenter_slides', false );
		$this->assertCount( 2, $slides );
		$this->assertStringNotContainsString( '<script', $slides[0]->content );
		$this->assertStringNotContainsString( 'onerror', $slides[0]->content );
		$this->assertStringContainsString( '<img src="x">', $slides[0]->content );
		$this->assertStringNotContainsString( '<script', $slides[0]->notes['notes'] );
		$this->assertStringNotContainsString( 'onclick', $slides[0]->notes['notes'] );
		$this->assertStringContainsString( '<p>Safe note</p>', $slides[0]->notes['notes'] );
	}

	/**
	 * Administrators retain intentionally authored executable legacy HTML.
	 */
	public function test_administrator_legacy_save_preserves_unfiltered_html(): void {
		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator_id );
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_author' => $administrator_id,
				'post_status' => 'publish',
			)
		);
		$this->add_legacy_slide_fixture( $post_id );
		$trusted_content                    = '<script>window.trustedSlide = true;</script>';
		$trusted_notes                      = '<script>window.trustedNotes = true;</script>';
		$_POST                              = $this->legacy_editor_request( $post_id );
		$_POST['slide-content']['4']        = $trusted_content;
		$_POST['slide-notes']['4']['notes'] = $trusted_notes;

		presenter::get_instance()->save_post_slideshow( $post_id, get_post( $post_id ), true );

		$slides = get_post_meta( $post_id, '_presenter_slides', false );
		$this->assertSame( $trusted_content, $slides[0]->content );
		$this->assertSame( $trusted_notes, $slides[0]->notes['notes'] );
	}

	/**
	 * Malformed payloads do not partially erase or update existing metadata.
	 */
	public function test_malformed_legacy_payload_preserves_all_existing_metadata(): void {
		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator_id );
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_author' => $administrator_id,
				'post_status' => 'publish',
			)
		);
		$this->add_legacy_slide_fixture( $post_id );
		update_post_meta( $post_id, '_presenter-theme', '/existing/theme.css' );
		update_post_meta( $post_id, '_presenter-short-url', 'https://example.test/existing' );
		$slides_before = get_post_meta( $post_id, '_presenter_slides', false );
		$_POST         = $this->legacy_editor_request( $post_id );
		unset( $_POST['slide-data-value']['4'] );

		presenter::get_instance()->save_post_slideshow( $post_id, get_post( $post_id ), true );

		$this->assertSame( maybe_serialize( $slides_before ), maybe_serialize( get_post_meta( $post_id, '_presenter_slides', false ) ) );
		$this->assertSame( '/existing/theme.css', get_post_meta( $post_id, '_presenter-theme', true ) );
		$this->assertSame( 'https://example.test/existing', get_post_meta( $post_id, '_presenter-short-url', true ) );
	}

	/**
	 * Create a complete classic-editor request payload for two slides.
	 *
	 * @param int $post_id Slideshow post ID used to scope the save nonce.
	 * @return array<string, mixed>
	 */
	private function legacy_editor_request( int $post_id ): array {
		return array(
			'_presenter_nonce'    => wp_create_nonce( 'presenter_save_slideshow:' . $post_id ),
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

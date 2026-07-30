<?php
/**
 * Retained Presenter 1.x data-upgrade tests.
 *
 * @package Presenter
 */

/**
 * Verify that historical upgrades are bounded, resumable, and locked.
 */
final class Presenter_Legacy_Upgrade_Test extends Presenter_Test_Case {
	private const FINAL_DATA_VERSION = 20170706;

	private const LOCK_OPTION = 'presenter_upgrade_lock';

	private const POST_CURSOR_OPTION = 'presenter_upgrade_20150406_cursor';

	private const META_CURSOR_OPTION = 'presenter_upgrade_20170706_cursor';

	/** Reset persistent upgrade state before each assertion. */
	public function set_up(): void {
		parent::set_up();

		set_current_screen( 'dashboard' );
		$this->delete_upgrade_options();
	}

	/** Restore global and persistent upgrade state after each assertion. */
	public function tear_down(): void {
		$this->delete_upgrade_options();
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/** Historical upgrades run only from the admin-init lifecycle. */
	public function test_upgrade_check_is_not_registered_on_every_request(): void {
		$presenter = presenter::get_instance();

		$this->assertFalse( has_action( 'plugins_loaded', array( $presenter, 'upgrade_check' ) ) );
		$this->assertIsInt( has_action( 'admin_init', array( $presenter, 'upgrade_check' ) ) );
	}

	/** The 2015 conversion advances its cursor across bounded requests. */
	public function test_2015_upgrade_is_bounded_and_resumable(): void {
		$post_ids = array();
		for ( $index = 1; $index <= 21; ++$index ) {
			$post_ids[] = $this->create_slideshow_without_legacy_editor_post_data(
				array(
					'post_content' => '<p>Prelude ' . $index . '</p><section><h2>Slide ' . $index . '</h2></section>',
				)
			);
		}

		presenter::get_instance()->upgrade_check();

		foreach ( array_slice( $post_ids, 0, 20 ) as $post_id ) {
			$this->assertCount( 1, get_post_meta( $post_id, '_presenter_slides', false ) );
		}
		$this->assertSame( array(), get_post_meta( $post_ids[20], '_presenter_slides', false ) );
		$this->assertSame( 0, (int) get_site_option( 'presenter_version', 0 ) );
		$this->assertSame( $post_ids[19], (int) get_site_option( self::POST_CURSOR_OPTION, 0 ) );
		$this->assertFalse( get_option( self::LOCK_OPTION ) );

		presenter::get_instance()->upgrade_check();

		$this->assertSame( self::FINAL_DATA_VERSION, (int) get_site_option( 'presenter_version', 0 ) );
		$this->assertFalse( get_site_option( self::POST_CURSOR_OPTION ) );
		$this->assertFalse( get_site_option( self::META_CURSOR_OPTION ) );
		$this->assertFalse( get_option( self::LOCK_OPTION ) );

		foreach ( $post_ids as $index => $post_id ) {
			$slides = get_post_meta( $post_id, '_presenter_slides', false );
			$post   = get_post( $post_id );

			$this->assertCount( 1, $slides );
			$this->assertStringContainsString( '<section>', $slides[0]->content );
			$this->assertStringContainsString( 'Slide ' . ( $index + 1 ), $slides[0]->content );
			$this->assertStringContainsString( 'Prelude ' . ( $index + 1 ), $post->post_content );
			$this->assertStringNotContainsString( '<section>', $post->post_content );
		}
	}

	/** The 2017 note extraction advances its cursor across bounded requests. */
	public function test_2017_upgrade_is_bounded_and_resumable(): void {
		$post_id  = $this->create_slideshow_without_legacy_editor_post_data();
		$meta_ids = array();
		update_site_option( 'presenter_version', 20150406 );

		for ( $index = 1; $index <= 21; ++$index ) {
			$meta_ids[] = add_post_meta(
				$post_id,
				'_presenter_slides',
				(object) array(
					'number'  => $index,
					'content' => '<p>Body ' . $index . '</p><aside class="notes" data-markdown><strong>Note ' . $index . '</strong></aside>',
				)
			);
		}

		presenter::get_instance()->upgrade_check();

		$this->assertSame( 20150406, (int) get_site_option( 'presenter_version', 0 ) );
		$this->assertSame( $meta_ids[19], (int) get_site_option( self::META_CURSOR_OPTION, 0 ) );
		$this->assertFalse( get_option( self::LOCK_OPTION ) );

		$slides = get_post_meta( $post_id, '_presenter_slides', false );
		$this->assertStringNotContainsString( '<aside', $slides[19]->content );
		$this->assertStringContainsString( '<aside', $slides[20]->content );

		presenter::get_instance()->upgrade_check();

		$this->assertSame( self::FINAL_DATA_VERSION, (int) get_site_option( 'presenter_version', 0 ) );
		$this->assertFalse( get_site_option( self::META_CURSOR_OPTION ) );
		$this->assertFalse( get_option( self::LOCK_OPTION ) );

		foreach ( get_post_meta( $post_id, '_presenter_slides', false ) as $index => $slide ) {
			$this->assertStringContainsString( 'Body ' . ( $index + 1 ), $slide->content );
			$this->assertStringNotContainsString( '<aside', $slide->content );
			$this->assertTrue( $slide->notes['markdown'] );
			$this->assertStringContainsString( 'Note ' . ( $index + 1 ), $slide->notes['notes'] );
		}
	}

	/** An active lock prevents a concurrent request from entering the upgrade. */
	public function test_active_upgrade_lock_prevents_concurrent_conversion(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array( 'post_content' => '<section><h2>Locked</h2></section>' )
		);
		$lock    = array(
			'token'      => wp_generate_uuid4(),
			'expires_at' => time() + 60,
		);
		add_option( self::LOCK_OPTION, $lock, '', false );

		presenter::get_instance()->upgrade_check();

		$this->assertSame( array(), get_post_meta( $post_id, '_presenter_slides', false ) );
		$this->assertSame( 0, (int) get_site_option( 'presenter_version', 0 ) );
		$this->assertSame( $lock, get_option( self::LOCK_OPTION ) );
	}

	/** An abandoned lock expires and does not permanently strand the upgrade. */
	public function test_expired_upgrade_lock_is_recovered(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array( 'post_content' => '<section><h2>Recovered</h2></section>' )
		);
		add_option(
			self::LOCK_OPTION,
			array(
				'token'      => wp_generate_uuid4(),
				'expires_at' => time() - 1,
			),
			'',
			false
		);

		presenter::get_instance()->upgrade_check();

		$this->assertCount( 1, get_post_meta( $post_id, '_presenter_slides', false ) );
		$this->assertSame( self::FINAL_DATA_VERSION, (int) get_site_option( 'presenter_version', 0 ) );
		$this->assertFalse( get_option( self::LOCK_OPTION ) );
	}

	/** Remove every site option owned by the retained upgrade runner. */
	private function delete_upgrade_options(): void {
		delete_site_option( 'presenter_version' );
		delete_option( self::LOCK_OPTION );
		delete_site_option( self::POST_CURSOR_OPTION );
		delete_site_option( self::META_CURSOR_OPTION );
	}
}

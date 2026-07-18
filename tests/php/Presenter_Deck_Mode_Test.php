<?php
/**
 * Explicit Presenter deck-mode resolver tests.
 *
 * @package Presenter
 */

use Presenter\Deck_Mode;
use Presenter\WordPress_Legacy_Slide_Source;

/**
 * Verify the storage cutover marker resolves one unambiguous runtime.
 */
final class Presenter_Deck_Mode_Test extends Presenter_Test_Case {
	/**
	 * Resolver under test.
	 *
	 * @var Deck_Mode
	 */
	private Deck_Mode $deck_mode;

	/**
	 * Create a resolver backed by real WordPress post metadata.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->deck_mode = new Deck_Mode( new WordPress_Legacy_Slide_Source() );
	}

	/**
	 * An explicit native cutover wins while legacy data is retained for safety.
	 */
	public function test_explicit_native_marker_wins_over_retained_legacy_slides(): void {
		$post_id = self::factory()->post->create();
		$this->add_legacy_slide_fixture( $post_id );
		update_post_meta( $post_id, Deck_Mode::META_KEY, Deck_Mode::NATIVE );

		$this->assertSame( Deck_Mode::NATIVE, $this->deck_mode->mode( $post_id ) );
		$this->assertTrue( $this->deck_mode->has_native_cutover( $post_id ) );
		$this->assertTrue( $this->deck_mode->uses_native_runtime( $post_id ) );
		$this->assertFalse( $this->deck_mode->uses_legacy_runtime( $post_id ) );
	}

	/**
	 * Legacy metadata remains authoritative until an exact native cutover.
	 */
	public function test_legacy_slides_without_a_cutover_marker_use_legacy_runtime(): void {
		$post_id = self::factory()->post->create();
		$this->add_legacy_slide_fixture( $post_id );

		$this->assertSame( Deck_Mode::LEGACY, $this->deck_mode->mode( $post_id ) );
		$this->assertFalse( $this->deck_mode->has_native_cutover( $post_id ) );
		$this->assertTrue( $this->deck_mode->uses_legacy_runtime( $post_id ) );
		$this->assertFalse( $this->deck_mode->uses_native_runtime( $post_id ) );
	}

	/**
	 * A post without legacy storage remains eligible for native block routing.
	 */
	public function test_post_without_marker_or_legacy_slides_is_native_eligible(): void {
		$post_id = self::factory()->post->create();

		$this->assertSame( Deck_Mode::NATIVE, $this->deck_mode->mode( $post_id ) );
		$this->assertFalse( $this->deck_mode->has_native_cutover( $post_id ) );
		$this->assertFalse( $this->deck_mode->uses_legacy_runtime( $post_id ) );
		$this->assertTrue( $this->deck_mode->uses_native_runtime( $post_id ) );
	}

	/**
	 * An invalid marker cannot bypass retained legacy data.
	 */
	public function test_invalid_marker_does_not_bypass_legacy_slides(): void {
		$post_id = self::factory()->post->create();
		$this->add_legacy_slide_fixture( $post_id );
		update_post_meta( $post_id, Deck_Mode::META_KEY, ' native ' );

		$this->assertSame( Deck_Mode::LEGACY, $this->deck_mode->mode( $post_id ) );
		$this->assertFalse( $this->deck_mode->has_native_cutover( $post_id ) );
		$this->assertTrue( $this->deck_mode->uses_legacy_runtime( $post_id ) );
		$this->assertFalse( $this->deck_mode->uses_native_runtime( $post_id ) );
	}

	/**
	 * An invalid marker alone does not prevent normal native eligibility.
	 */
	public function test_invalid_marker_without_legacy_slides_is_native_eligible(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Deck_Mode::META_KEY, 'future-mode' );

		$this->assertSame( Deck_Mode::NATIVE, $this->deck_mode->mode( $post_id ) );
		$this->assertFalse( $this->deck_mode->has_native_cutover( $post_id ) );
		$this->assertFalse( $this->deck_mode->uses_legacy_runtime( $post_id ) );
		$this->assertTrue( $this->deck_mode->uses_native_runtime( $post_id ) );
	}

	/**
	 * Duplicate native markers fail safe while legacy data remains retained.
	 */
	public function test_duplicate_native_markers_do_not_bypass_legacy_slides(): void {
		$post_id = self::factory()->post->create();
		$this->add_legacy_slide_fixture( $post_id );
		add_post_meta( $post_id, Deck_Mode::META_KEY, Deck_Mode::NATIVE );
		add_post_meta( $post_id, Deck_Mode::META_KEY, Deck_Mode::NATIVE );

		$this->assertSame( Deck_Mode::LEGACY, $this->deck_mode->mode( $post_id ) );
		$this->assertFalse( $this->deck_mode->has_native_cutover( $post_id ) );
		$this->assertTrue( $this->deck_mode->uses_legacy_runtime( $post_id ) );
		$this->assertFalse( $this->deck_mode->uses_native_runtime( $post_id ) );
	}
}

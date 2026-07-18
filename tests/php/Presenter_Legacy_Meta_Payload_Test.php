<?php
/**
 * Retained legacy metadata payload tests.
 *
 * @package Presenter
 */

use Presenter\Legacy_Meta_Payload;

require_once dirname( __DIR__, 2 ) . '/includes/class-legacy-meta-payload.php';

/**
 * Verify complete, isolated capture of metadata retained through migration.
 */
final class Presenter_Legacy_Meta_Payload_Test extends Presenter_Test_Case {
	/** All rows retain their stored order, duplicate values, and PHP types. */
	public function test_capture_preserves_order_duplicates_and_types(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_title' => 'Retained metadata payload fixture',
			)
		);

		$first_slide = (object) array(
			'number'  => 2,
			'content' => '<p>First stored row.</p>',
			'nested'  => array( 'enabled' => true ),
		);
		$last_slide  = array(
			'number'  => '02',
			'content' => '<p>Last stored row.</p>',
		);

		add_post_meta( $post_id, '_presenter_slides', $first_slide );
		add_post_meta( $post_id, '_presenter_slides', $first_slide );
		add_post_meta( $post_id, '_presenter_slides', $last_slide );
		add_post_meta( $post_id, '_presenter-theme', '' );
		add_post_meta( $post_id, '_presenter-theme', '/themes/second.css' );
		add_post_meta( $post_id, '_presenter-short-url', 'https://example.test/first' );
		add_post_meta( $post_id, '_presenter-short-url', 'https://example.test/first' );

		$payload = Legacy_Meta_Payload::capture( $post_id );

		$captured_slides = $payload->slides();
		$this->assertTrue( $captured_slides['exists'] );
		$this->assertCount( 3, $captured_slides['values'] );
		$this->assertEquals( $first_slide, $captured_slides['values'][0] );
		$this->assertEquals( $first_slide, $captured_slides['values'][1] );
		$this->assertSame( $last_slide, $captured_slides['values'][2] );
		$this->assertSame(
			array(
				'exists' => true,
				'values' => array( '', '/themes/second.css' ),
			),
			$payload->theme()
		);
		$this->assertSame(
			array(
				'exists' => true,
				'values' => array( 'https://example.test/first', 'https://example.test/first' ),
			),
			$payload->short_url()
		);
	}

	/** An absent key remains distinguishable from one stored empty row. */
	public function test_capture_distinguishes_absent_metadata_from_empty_metadata(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_title' => 'Absent metadata payload fixture',
			)
		);

		add_post_meta( $post_id, '_presenter-theme', '' );

		$payload = Legacy_Meta_Payload::capture( $post_id );

		$this->assertSame(
			array(
				'exists' => false,
				'values' => array(),
			),
			$payload->slides()
		);
		$this->assertSame(
			array(
				'exists' => true,
				'values' => array( '' ),
			),
			$payload->theme()
		);
		$this->assertSame(
			array(
				'exists' => false,
				'values' => array(),
			),
			$payload->short_url()
		);
	}

	/** Accessors cannot mutate the payload or another accessor result. */
	public function test_accessors_return_recursively_isolated_copies(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_title' => 'Isolated metadata payload fixture',
			)
		);
		$slide   = (object) array(
			'content' => '<p>Original.</p>',
			'nested'  => (object) array(
				'items' => array( 'one', 'two' ),
			),
		);
		add_post_meta( $post_id, '_presenter_slides', $slide );

		$payload = Legacy_Meta_Payload::capture( $post_id );
		$copy    = $payload->slides();

		$copy['exists']                      = false;
		$copy['values'][0]->content          = '<p>Changed.</p>';
		$copy['values'][0]->nested->items[0] = 'changed';
		$copy['values'][]                    = 'extra';

		$fresh = $payload->slides();

		$this->assertTrue( $fresh['exists'] );
		$this->assertCount( 1, $fresh['values'] );
		$this->assertSame( '<p>Original.</p>', $fresh['values'][0]->content );
		$this->assertSame( array( 'one', 'two' ), $fresh['values'][0]->nested->items );
	}
}

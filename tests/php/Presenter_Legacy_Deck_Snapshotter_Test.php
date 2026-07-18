<?php
/**
 * Legacy deck snapshot tests.
 *
 * @package Presenter
 */

use Presenter\Legacy_Deck_Snapshotter;
use Presenter\Legacy_Slide_Source;

require_once dirname( __DIR__, 2 ) . '/includes/interface-legacy-slide-source.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-legacy-deck-snapshot.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-legacy-deck-snapshotter.php';

/**
 * Verify read-only capture of complete legacy migration inputs.
 */
class Presenter_Legacy_Deck_Snapshotter_Test extends Presenter_Test_Case {
	/** Snapshot capture preserves source order, post state, and private copies. */
	public function test_capture_preserves_complete_source_without_exposing_mutable_state(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'     => 'slideshow',
				'post_name'     => 'snapshot-deck',
				'post_title'    => 'Snapshot deck',
				'post_excerpt'  => 'Snapshot excerpt',
				'post_content'  => '<p>Legacy leftover content</p>',
				'post_status'   => 'publish',
				'post_password' => 'local-password',
				'menu_order'    => 17,
			)
		);
		$slides  = array(
			(object) array(
				'number' => 9,
				'title'  => 'Stored first',
			),
			array(
				'number' => 1,
				'title'  => 'Stored second',
			),
		);
		update_post_meta( $post_id, '_presenter-theme', '/plugins/private/aaron-purple.css' );
		update_post_meta( $post_id, '_presenter-short-url', 'https://example.test/talk' );

		$snapshot = ( new Legacy_Deck_Snapshotter( $this->source( $post_id, $slides ) ) )->capture( $post_id );

		$this->assertNotNull( $snapshot );
		$this->assertSame( $post_id, $snapshot->post_id() );
		$this->assertSame( 'slideshow', $snapshot->post_type() );
		$this->assertSame( 'snapshot-deck', $snapshot->slug() );
		$this->assertSame( 'Snapshot deck', $snapshot->title() );
		$this->assertSame( 'Snapshot excerpt', $snapshot->excerpt() );
		$this->assertSame( 17, $snapshot->menu_order() );
		$this->assertSame( 'publish', $snapshot->status() );
		$this->assertSame( 'local-password', $snapshot->password() );
		$this->assertTrue( $snapshot->is_password_protected() );
		$this->assertSame( '<p>Legacy leftover content</p>', $snapshot->post_content() );
		$this->assertSame( '/plugins/private/aaron-purple.css', $snapshot->theme() );
		$this->assertSame( 'https://example.test/talk', $snapshot->short_url() );
		$this->assertSame( 2, $snapshot->slide_count() );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $snapshot->fingerprint() );
		$this->assertSame( 'Stored first', $snapshot->raw_slides()[0]->title );
		$this->assertSame( 'Stored second', $snapshot->raw_slides()[1]['title'] );

		$copy           = $snapshot->raw_slides();
		$copy[0]->title = 'Changed by caller';
		$this->assertSame( 'Stored first', $snapshot->raw_slides()[0]->title );
	}

	/** Fingerprints are deterministic and include deck-level migration inputs. */
	public function test_fingerprint_is_deterministic_and_changes_with_source(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'    => 'slideshow',
				'post_title'   => 'Fingerprint',
				'post_content' => 'Original content',
			)
		);
		$slides  = array(
			(object) array(
				'number'  => 1,
				'content' => 'Slide',
			),
		);
		$service = new Legacy_Deck_Snapshotter( $this->source( $post_id, $slides ) );

		$first  = $service->capture( $post_id );
		$second = $service->capture( $post_id );
		$this->assertNotNull( $first );
		$this->assertNotNull( $second );
		$this->assertSame( $first->fingerprint(), $second->fingerprint() );

		update_post_meta( $post_id, '_presenter-short-url', 'https://example.test/changed' );
		$changed = $service->capture( $post_id );
		$this->assertNotNull( $changed );
		$this->assertNotSame( $first->fingerprint(), $changed->fingerprint() );

		add_post_meta( $post_id, '_presenter-short-url', 'https://example.test/duplicate-row' );
		$duplicate_row = $service->capture( $post_id );
		$this->assertNotNull( $duplicate_row );
		$this->assertNotSame( $changed->fingerprint(), $duplicate_row->fingerprint() );
	}

	/** Malformed deck metadata is preserved in the fingerprint and reported. */
	public function test_capture_reports_non_string_legacy_metadata(): void {
		global $wpdb;

		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array( 'post_type' => 'slideshow' )
		);
		$slides  = array( (object) array( 'number' => 1 ) );
		$service = new Legacy_Deck_Snapshotter( $this->source( $post_id, $slides ) );

		update_post_meta( $post_id, '_presenter-theme', array( 'unexpected-theme-shape' ) );
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $post_id,
				'meta_key'   => '_presenter-short-url',
				'meta_value' => maybe_serialize( (object) array( 'unexpected' => true ) ),
			)
		);
		wp_cache_delete( $post_id, 'post_meta' );
		$snapshot = $service->capture( $post_id );

		$this->assertNotNull( $snapshot );
		$this->assertSame( '', $snapshot->theme() );
		$this->assertSame( '', $snapshot->short_url() );
		$this->assertEqualsCanonicalizing(
			array( 'invalid_legacy_theme_meta', 'invalid_legacy_short_url_meta' ),
			$snapshot->warnings()
		);
	}

	/** Ineligible posts and empty legacy sources do not produce snapshots. */
	public function test_capture_rejects_non_slideshows_and_empty_sources(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$this->assertNull( ( new Legacy_Deck_Snapshotter( $this->source( $post_id, array( 'record' ) ) ) )->capture( $post_id ) );

		$slideshow_id = $this->create_slideshow_without_legacy_editor_post_data( array( 'post_type' => 'slideshow' ) );
		$this->assertNull( ( new Legacy_Deck_Snapshotter( $this->source( $slideshow_id, array() ) ) )->capture( $slideshow_id ) );
		$this->assertNull( ( new Legacy_Deck_Snapshotter( $this->source( $slideshow_id, array(), false ) ) )->capture( $slideshow_id ) );
	}

	/**
	 * Build a deterministic legacy source test double.
	 *
	 * @param int               $post_id Post ID containing slides.
	 * @param array<int, mixed> $slides  Source slide values.
	 * @param bool              $exists  Whether legacy metadata exists.
	 * @return Legacy_Slide_Source
	 */
	private function source( int $post_id, array $slides, bool $exists = true ): Legacy_Slide_Source {
		return new class( $post_id, $slides, $exists ) implements Legacy_Slide_Source {
			/**
			 * Create the source double.
			 *
			 * @param int               $post_id Post ID containing slides.
			 * @param array<int, mixed> $slides  Source slide values.
			 * @param bool              $exists  Whether legacy metadata exists.
			 */
			public function __construct( private int $post_id, private array $slides, private bool $exists ) {}

			/**
			 * Determine whether the requested post has legacy slides.
			 *
			 * @param int $post_id Requested post ID.
			 * @return bool Whether slides exist.
			 */
			public function has_slides( int $post_id ): bool {
				return $this->exists && $this->post_id === $post_id;
			}

			/**
			 * Read the requested post's legacy slides.
			 *
			 * @param int $post_id Requested post ID.
			 * @return array<int, mixed> Legacy slides.
			 */
			public function read_slides( int $post_id ): array {
				return $this->post_id === $post_id ? $this->slides : array();
			}
		};
	}
}

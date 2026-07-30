<?php
/**
 * Presenter migration apply failure and race tests.
 *
 * @package Presenter
 */

use Presenter\Deck_Mode;
use Presenter\Legacy_Deck_Snapshotter;
use Presenter\Legacy_HTML_Trust;
use Presenter\Legacy_Section_Validator;
use Presenter\Legacy_Slide_Attribute_Mapper;
use Presenter\Legacy_Slide_Normalizer;
use Presenter\Migration_Applier;
use Presenter\Migration_Apply_Observer;
use Presenter\Migration_Backup_Store;
use Presenter\Migration_Context_Builder;
use Presenter\Migration_Deck_Mode_Store;
use Presenter\Migration_Journal;
use Presenter\Migration_Lock;
use Presenter\Migration_Planner;
use Presenter\Migration_Preparer;
use Presenter\Migration_Revision;
use Presenter\Migration_Secret;
use Presenter\Migration_Status_Service;
use Presenter\Native_Deck_Structure;
use Presenter\Slide_Attribute_Validator;
use Presenter\Speaker_Notes;
use Presenter\WordPress_Legacy_Slide_Source;

require_once __DIR__ . '/support/class-presenter-test-migration-writer.php';
require_once __DIR__ . '/support/class-presenter-test-migration-apply-observer.php';

/**
 * Verify apply failures preserve or recover the authoritative representation.
 */
final class Presenter_Migration_Applier_Failure_Test extends Presenter_Test_Case {
	/** Reset global migration state before each integration test. */
	public function set_up(): void {
		parent::set_up();
		delete_option( Migration_Secret::OPTION_NAME );
		wp_set_current_user( 0 );
	}

	/** Restore global migration state after each integration test. */
	public function tear_down(): void {
		delete_option( Migration_Secret::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** An active WordPress edit lock prevents the first content mutation. */
	public function test_edit_lock_fails_before_any_apply_write(): void {
		$writer   = new Presenter_Test_Migration_Writer();
		$prepared = $this->prepared_fixture( $writer );
		$owner    = self::factory()->user->create();
		update_post_meta( $prepared['postId'], '_edit_lock', time() . ':' . $owner );
		$before = $this->migration_footprint( $prepared['postId'] );

		$result = $prepared['applier']->apply( $prepared['postId'] );

		$this->assertContains( 'edit_lock_active', $result['codes'], (string) wp_json_encode( $result ) );
		$this->assertSame( 0, $writer->calls );
		$this->assertSame( $before, $this->migration_footprint( $prepared['postId'] ) );
		$this->assertSame( '', get_post( $prepared['postId'] )->post_content );
		$this->assertSame( Migration_Journal::STATE_APPLY_PREPARED, $this->journal_state( $prepared['postId'] ) );
		$this->assert_redacted_result( $result, $prepared['postId'] );
	}

	/** A source race at the final pre-write checkpoint cannot be overwritten. */
	public function test_prewrite_source_race_causes_zero_apply_writes(): void {
		$writer   = new Presenter_Test_Migration_Writer();
		$observer = new Presenter_Test_Migration_Apply_Observer(
			static function ( string $phase, int $post_id ): void {
				if ( Migration_Applier::PHASE_BEFORE_CONTENT_WRITE === $phase ) {
					wp_update_post(
						array(
							'ID'         => $post_id,
							'post_title' => 'private-racing-title-sentinel',
						)
					);
				}
			}
		);
		$prepared = $this->prepared_fixture( $writer, $observer );
		$events   = count( get_post_meta( $prepared['postId'], Migration_Journal::META_KEY, false ) );
		$backups  = count( get_post_meta( $prepared['postId'], Migration_Backup_Store::META_KEY, false ) );

		$result = $prepared['applier']->apply( $prepared['postId'] );

		$this->assertContains( 'prepared_source_changed', $result['codes'] );
		$this->assertSame( 0, $writer->calls );
		$this->assertSame( '', get_post( $prepared['postId'] )->post_content );
		$this->assertSame( array(), get_post_meta( $prepared['postId'], Deck_Mode::META_KEY, false ) );
		$this->assertSame( $events, count( get_post_meta( $prepared['postId'], Migration_Journal::META_KEY, false ) ) );
		$this->assertSame( $backups, count( get_post_meta( $prepared['postId'], Migration_Backup_Store::META_KEY, false ) ) );
		$this->assert_redacted_result( $result, $prepared['postId'] );
	}

	/** A trust-state change after preparation invalidates the authorized source. */
	public function test_legacy_html_trust_change_after_prepare_prevents_apply(): void {
		$writer   = new Presenter_Test_Migration_Writer();
		$prepared = $this->prepared_fixture( $writer );
		$slides   = get_post_meta( $prepared['postId'], '_presenter_slides', false );
		( new Legacy_HTML_Trust() )->synchronize( $prepared['postId'], $slides, true );

		$result = $prepared['applier']->apply( $prepared['postId'] );

		$this->assertContains( 'prepared_source_changed', $result['codes'] );
		$this->assertSame( 0, $writer->calls );
		$this->assertSame( '', get_post( $prepared['postId'] )->post_content );
		$this->assertSame( array(), get_post_meta( $prepared['postId'], Deck_Mode::META_KEY, false ) );
		$this->assert_redacted_result( $result, $prepared['postId'] );
	}

	/** An exception after the content write restores original content and records rollback. */
	public function test_after_content_exception_compensates_to_original_legacy(): void {
		$writer   = new Presenter_Test_Migration_Writer();
		$observer = $this->throwing_observer( Migration_Applier::PHASE_AFTER_CONTENT_WRITE );
		$prepared = $this->prepared_fixture( $writer, $observer );

		$result = $prepared['applier']->apply( $prepared['postId'] );

		$this->assertContains( 'apply_rolled_back', $result['codes'] );
		$this->assertSame( 2, $writer->calls );
		$this->assert_rolled_back_original_legacy( $prepared['postId'] );
		$this->assert_redacted_result( $result, $prepared['postId'] );
	}

	/** An exception after cutover removes the owned marker before restoring content. */
	public function test_after_cutover_exception_removes_owned_marker_then_rolls_back(): void {
		$writer        = new Presenter_Test_Migration_Writer();
		$cutover_state = null;
		$observer      = new Presenter_Test_Migration_Apply_Observer(
			static function ( string $phase, int $post_id ) use ( &$cutover_state ): void {
				if ( Migration_Applier::PHASE_AFTER_CUTOVER === $phase ) {
					$cutover_state = get_post_meta( $post_id, Deck_Mode::META_KEY, false );
					throw new RuntimeException( 'private-after-cutover-exception-sentinel' );
				}
			}
		);
		$prepared      = $this->prepared_fixture( $writer, $observer );

		$result = $prepared['applier']->apply( $prepared['postId'] );

		$this->assertSame( array( Deck_Mode::NATIVE ), $cutover_state );
		$this->assertContains( 'apply_rolled_back', $result['codes'] );
		$this->assertSame( 2, $writer->calls );
		$this->assert_rolled_back_original_legacy( $prepared['postId'] );
		$this->assert_redacted_result( $result, $prepared['postId'] );
	}

	/** Third-party content replacing the target during compensation requires recovery. */
	public function test_third_party_content_during_compensation_records_recovery_required(): void {
		$writer   = new Presenter_Test_Migration_Writer(
			static function ( int $call, int $post_id ): void {
				if ( 2 === $call ) {
					wp_update_post(
						array(
							'ID'           => $post_id,
							'post_content' => '<p>private-third-party-content-sentinel</p>',
						)
					);
				}
			}
		);
		$prepared = $this->prepared_fixture(
			$writer,
			$this->throwing_observer( Migration_Applier::PHASE_AFTER_CONTENT_WRITE )
		);

		$result = $prepared['applier']->apply( $prepared['postId'] );

		$this->assertContains( 'recovery_required', $result['codes'] );
		$this->assertSame( 2, $writer->calls );
		$this->assertSame( '<p>private-third-party-content-sentinel</p>', get_post( $prepared['postId'] )->post_content );
		$this->assertSame( array(), get_post_meta( $prepared['postId'], Deck_Mode::META_KEY, false ) );
		$this->assertSame( Migration_Journal::STATE_RECOVERY_REQUIRED, $this->journal_state( $prepared['postId'] ) );
		$this->assert_redacted_result( $result, $prepared['postId'] );
	}

	/**
	 * A marker not owned by the apply attempt is never changed or trusted.
	 *
	 * @dataProvider preexisting_markers
	 *
	 * @param string $marker Preexisting marker value.
	 */
	public function test_preexisting_marker_fails_closed_without_content_write( string $marker ): void {
		$writer   = new Presenter_Test_Migration_Writer();
		$prepared = $this->prepared_fixture( $writer );
		$this->store_marker_fixture( $prepared['postId'], $marker );

		$result = $prepared['applier']->apply( $prepared['postId'] );

		$this->assertContains( 'recovery_required', $result['codes'] );
		$this->assertSame( 0, $writer->calls );
		$this->assertSame( '', get_post( $prepared['postId'] )->post_content );
		$this->assertSame( array( $marker ), get_post_meta( $prepared['postId'], Deck_Mode::META_KEY, false ) );
		$this->assertSame( Migration_Journal::STATE_RECOVERY_REQUIRED, $this->journal_state( $prepared['postId'] ) );
		$this->assert_redacted_result( $result, $prepared['postId'] );
	}

	/** Provide malformed and prematurely native marker values. */
	public function preexisting_markers(): array {
		return array(
			'malformed marker' => array( 'private-malformed-marker-sentinel' ),
			'native marker'    => array( Deck_Mode::NATIVE ),
		);
	}

	/**
	 * Store exact marker bytes, including values rejected by sanitization.
	 *
	 * @param int    $post_id Slideshow post ID.
	 * @param string $marker  Exact raw marker.
	 */
	private function store_marker_fixture( int $post_id, string $marker ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Corrupt stored metadata cannot be created through the sanitizing metadata API.
		$inserted = $wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $post_id,
				'meta_key'   => Deck_Mode::META_KEY,
				'meta_value' => $marker,
			),
			array( '%d', '%s', '%s' )
		);
		$this->assertSame( 1, $inserted );
		wp_cache_delete( $post_id, 'post_meta' );
	}

	/**
	 * Build and prepare one real legacy deck around injected apply test seams.
	 *
	 * @param Presenter_Test_Migration_Writer $writer   Instrumented atomic writer.
	 * @param Migration_Apply_Observer|null   $observer Optional phase observer.
	 * @return array<string, mixed> Prepared post and applier.
	 */
	private function prepared_fixture(
		Presenter_Test_Migration_Writer $writer,
		?Migration_Apply_Observer $observer = null
	): array {
		$legacy      = new WordPress_Legacy_Slide_Source();
		$snapshotter = new Legacy_Deck_Snapshotter( $legacy );
		$planner     = new Migration_Planner(
			new Legacy_Slide_Normalizer(),
			new Legacy_Slide_Attribute_Mapper( new Slide_Attribute_Validator() ),
			new Legacy_Section_Validator(),
			new Speaker_Notes(),
			presenter_get_runtime()->themes()
		);
		$secret      = new Migration_Secret();
		$lock        = new Migration_Lock();
		$revision    = new Migration_Revision();
		$deck_mode   = new Deck_Mode( $legacy );
		$mode_store  = new Migration_Deck_Mode_Store();
		$structure   = new Native_Deck_Structure();
		$builder     = new Migration_Context_Builder( $snapshotter, $planner, $mode_store );
		$status      = new Migration_Status_Service( $snapshotter, $planner, $secret, $lock, $revision, $deck_mode, $mode_store );
		$preparer    = new Migration_Preparer( $builder, $secret, $lock, $revision, $status, $deck_mode, $mode_store );
		$post_id     = $this->create_ready_deck();

		$prepared = $preparer->prepare( $post_id );
		$this->assertContains( 'prepared', $prepared['codes'] );

		return array(
			'postId'  => $post_id,
			'applier' => new Migration_Applier(
				$builder,
				$secret,
				$lock,
				$revision,
				$status,
				$mode_store,
				$structure,
				$writer,
				$observer ?? new Presenter_Test_Migration_Apply_Observer()
			),
		);
	}

	/** Create one losslessly plannable legacy slideshow. */
	private function create_ready_deck(): int {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'    => 'slideshow',
				'post_status'  => 'publish',
				'post_title'   => 'private-applier-title-sentinel',
				'post_excerpt' => 'private-applier-excerpt-sentinel',
				'post_content' => '',
			)
		);
		add_post_meta(
			$post_id,
			'_presenter_slides',
			(object) array(
				'number'  => 1,
				'title'   => 'private-applier-slide-title-sentinel',
				'content' => '<p>private-applier-slide-content-sentinel</p>',
				'class'   => '',
			)
		);

		return $post_id;
	}

	/**
	 * Create an observer that throws at one exact apply phase.
	 *
	 * @param string $throw_phase Apply phase that should throw.
	 */
	private function throwing_observer( string $throw_phase ): Migration_Apply_Observer {
		return new Presenter_Test_Migration_Apply_Observer(
			static function ( string $phase ) use ( $throw_phase ): void {
				if ( $throw_phase === $phase ) {
					throw new RuntimeException( 'private-observer-exception-sentinel' );
				}
			}
		);
	}

	/**
	 * Capture state whose exact equality proves the edit-lock path was read-only.
	 *
	 * @param int $post_id Slideshow post ID.
	 */
	private function migration_footprint( int $post_id ): string {
		$post = get_post( $post_id );

		return hash(
			'sha256',
			maybe_serialize(
				array(
					'content' => $post->post_content,
					'mode'    => get_post_meta( $post_id, Deck_Mode::META_KEY, false ),
					'backup'  => get_post_meta( $post_id, Migration_Backup_Store::META_KEY, false ),
					'journal' => get_post_meta( $post_id, Migration_Journal::META_KEY, false ),
				)
			)
		);
	}

	/**
	 * Assert successful compensation restored legacy representation and journaled it.
	 *
	 * @param int $post_id Slideshow post ID.
	 */
	private function assert_rolled_back_original_legacy( int $post_id ): void {
		$this->assertSame( '', get_post( $post_id )->post_content );
		$this->assertSame( array(), get_post_meta( $post_id, Deck_Mode::META_KEY, false ) );
		$this->assertSame( Migration_Journal::STATE_APPLY_ROLLED_BACK, $this->journal_state( $post_id ) );
	}

	/**
	 * Read the verified journal state without exposing its context.
	 *
	 * @param int $post_id Slideshow post ID.
	 */
	private function journal_state( int $post_id ): ?string {
		$secret = ( new Migration_Secret() )->read();
		$this->assertIsString( $secret );

		return ( new Migration_Journal( new Presenter\Migration_Hasher( $secret ) ) )->inspect( $post_id )['state'];
	}

	/**
	 * Assert every apply response remains content-free and reference-free.
	 *
	 * @param array<string, mixed> $result  Public apply result.
	 * @param int                  $post_id Slideshow post ID.
	 */
	private function assert_redacted_result( array $result, int $post_id ): void {
		$json = wp_json_encode( $result );
		$this->assertIsString( $json );
		foreach (
			array(
				'private-applier-title-sentinel',
				'private-applier-excerpt-sentinel',
				'private-applier-slide-title-sentinel',
				'private-applier-slide-content-sentinel',
				'private-racing-title-sentinel',
				'private-third-party-content-sentinel',
				'private-malformed-marker-sentinel',
				'private-observer-exception-sentinel',
				'private-after-cutover-exception-sentinel',
			) as $sentinel
		) {
			$this->assertStringNotContainsString( $sentinel, $json );
		}

		$backup = get_post_meta( $post_id, Migration_Backup_Store::META_KEY, true );
		if ( is_array( $backup ) ) {
			$this->assertStringNotContainsString( $backup['backupId'], $json );
			$this->assertStringNotContainsString( $backup['envelopeHash'], $json );
		}
		$this->assert_redacted_keys( $result );
	}

	/**
	 * Recursively reject private storage and integrity field names.
	 *
	 * @param array<array-key, mixed> $values Public result subtree.
	 */
	private function assert_redacted_keys( array $values ): void {
		foreach ( $values as $key => $value ) {
			if ( is_string( $key ) && ! in_array( $key, array( 'postId', 'attemptId' ), true ) ) {
				$this->assertDoesNotMatchRegularExpression( '/(?:hash|reference|token|optionName|backupId|revisionId|eventId|payload|context)$/i', $key );
			}
			if ( is_array( $value ) ) {
				$this->assert_redacted_keys( $value );
			}
		}
	}
}

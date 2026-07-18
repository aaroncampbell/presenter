<?php
/**
 * Presenter migration restore failure and race tests.
 *
 * @package Presenter
 */

use Presenter\Atomic_Migration_Post_Content_Writer;
use Presenter\Deck_Mode;
use Presenter\Legacy_Deck_Snapshotter;
use Presenter\Legacy_Section_Validator;
use Presenter\Legacy_Slide_Attribute_Mapper;
use Presenter\Legacy_Slide_Normalizer;
use Presenter\Migration_Applier;
use Presenter\Migration_Backup_Store;
use Presenter\Migration_Context_Builder;
use Presenter\Migration_Deck_Mode_Store;
use Presenter\Migration_Hasher;
use Presenter\Migration_Journal;
use Presenter\Migration_Lock;
use Presenter\Migration_Planner;
use Presenter\Migration_Preparer;
use Presenter\Migration_Restorer;
use Presenter\Migration_Restore_Observer;
use Presenter\Migration_Revision;
use Presenter\Migration_Secret;
use Presenter\Migration_Status_Service;
use Presenter\Native_Deck_Structure;
use Presenter\Null_Migration_Apply_Observer;
use Presenter\Slide_Attribute_Validator;
use Presenter\Speaker_Notes;
use Presenter\WordPress_Legacy_Slide_Source;

require_once __DIR__ . '/support/class-presenter-test-migration-writer.php';
require_once __DIR__ . '/support/class-presenter-test-migration-restore-observer.php';

/**
 * Verify restore failures never overwrite an unverified representation.
 */
final class Presenter_Migration_Restorer_Failure_Test extends Presenter_Test_Case {
	/** Reset global migration state before every integration test. */
	public function set_up(): void {
		parent::set_up();
		delete_option( Migration_Secret::OPTION_NAME );
		wp_set_current_user( 0 );
	}

	/** Restore global migration state after every integration test. */
	public function tear_down(): void {
		delete_option( Migration_Secret::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** A competing migration owner makes restore completely read-only. */
	public function test_active_migration_lock_prevents_restore(): void {
		$fixture = $this->applied_fixture();
		$handle  = $fixture['lock']->acquire( $fixture['postId'] );
		$this->assertNotNull( $handle );
		$before = $this->footprint( $fixture['postId'] );

		$result = $fixture['restorer']->restore( $fixture['postId'] );

		$this->assertContains( 'lock_unavailable', $result['codes'] );
		$this->assertSame( $before, $this->footprint( $fixture['postId'] ) );
		$this->assertTrue( $fixture['lock']->release( $handle ) );
		$this->assert_redacted_result( $result, $fixture['postId'] );
	}

	/** An active WordPress edit lock prevents restore intent or content writes. */
	public function test_edit_lock_prevents_restore(): void {
		$fixture = $this->applied_fixture();
		$owner   = self::factory()->user->create();
		update_post_meta( $fixture['postId'], '_edit_lock', time() . ':' . $owner );
		$before = $this->footprint( $fixture['postId'] );

		$result = $fixture['restorer']->restore( $fixture['postId'] );

		$this->assertContains( 'edit_lock_active', $result['codes'] );
		$this->assertSame( $before, $this->footprint( $fixture['postId'] ) );
		$this->assertSame( Migration_Journal::STATE_APPLIED, $this->journal_state( $fixture['postId'] ) );
		$this->assert_redacted_result( $result, $fixture['postId'] );
	}

	/** Failure before durable restore intent leaves the healthy native deck intact. */
	public function test_observer_failure_before_restore_prepared_event_is_read_only(): void {
		$fixture = $this->applied_fixture( null, $this->throwing_observer( Migration_Restorer::PHASE_BEFORE_RESTORE_PREPARED_EVENT ) );
		$before  = $this->footprint( $fixture['postId'] );

		$result = $fixture['restorer']->restore( $fixture['postId'] );

		$this->assertContains( 'restore_prepare_failed', $result['codes'] );
		$this->assertSame( $before, $this->footprint( $fixture['postId'] ) );
		$this->assert_redacted_result( $result, $fixture['postId'] );
	}

	/** Every post-intent observer interruption leaves an exact resumable state. */
	public function test_observer_interruptions_after_restore_intent_are_resumable(): void {
		foreach (
			array(
				Migration_Restorer::PHASE_BEFORE_MARKER_REMOVAL,
				Migration_Restorer::PHASE_AFTER_MARKER_REMOVAL,
				Migration_Restorer::PHASE_BEFORE_CONTENT_RESTORE,
				Migration_Restorer::PHASE_AFTER_CONTENT_RESTORE,
				Migration_Restorer::PHASE_BEFORE_RESTORED_EVENT,
			) as $phase
		) {
			$fixture = $this->applied_fixture( null, $this->throwing_observer( $phase ) );

			$result = $fixture['restorer']->restore( $fixture['postId'] );

			$this->assertContains(
				Migration_Restorer::PHASE_BEFORE_RESTORED_EVENT === $phase ? 'restored_event_failed' : 'restore_interrupted',
				$result['codes'],
				$phase
			);
			$this->assertSame( Migration_Journal::STATE_RESTORE_PREPARED, $this->journal_state( $fixture['postId'] ), $phase );
			$this->assert_redacted_result( $result, $fixture['postId'] );

			$resumed = $this->new_restorer( $fixture, new Presenter_Test_Migration_Writer(), new Presenter_Test_Migration_Restore_Observer() )
				->restore( $fixture['postId'] );

			$this->assertContains( 'restored', $resumed['codes'], $phase );
			$this->assertSame( '', get_post( $fixture['postId'] )->post_content, $phase );
			$this->assertSame( array(), get_post_meta( $fixture['postId'], Deck_Mode::META_KEY, false ), $phase );
			$this->assertSame( Migration_Journal::STATE_RESTORED, $this->journal_state( $fixture['postId'] ), $phase );
		}
	}

	/** Non-content post-field changes invalidate restore before marker removal. */
	public function test_post_field_change_fails_closed(): void {
		$fixture = $this->applied_fixture();
		wp_update_post(
			array(
				'ID'         => $fixture['postId'],
				'post_title' => 'private-restore-racing-title-sentinel',
			)
		);

		$result = $fixture['restorer']->restore( $fixture['postId'] );

		$this->assertContains( 'applied_representation_invalid', $result['codes'] );
		$this->assertSame( array( Deck_Mode::NATIVE ), get_post_meta( $fixture['postId'], Deck_Mode::META_KEY, false ) );
		$this->assertSame( Migration_Journal::STATE_APPLIED, $this->journal_state( $fixture['postId'] ) );
		$this->assert_redacted_result( $result, $fixture['postId'] );
	}

	/** Retained legacy metadata changes invalidate restore before marker removal. */
	public function test_retained_meta_change_fails_closed(): void {
		$fixture = $this->applied_fixture();
		add_post_meta( $fixture['postId'], '_presenter-short-url', 'private-restore-racing-meta-sentinel' );

		$result = $fixture['restorer']->restore( $fixture['postId'] );

		$this->assertContains( 'applied_representation_invalid', $result['codes'] );
		$this->assertSame( array( Deck_Mode::NATIVE ), get_post_meta( $fixture['postId'], Deck_Mode::META_KEY, false ) );
		$this->assertSame( Migration_Journal::STATE_APPLIED, $this->journal_state( $fixture['postId'] ) );
		$this->assert_redacted_result( $result, $fixture['postId'] );
	}

	/** A changed signed backup is rejected without touching the live deck. */
	public function test_tampered_backup_fails_closed(): void {
		$fixture                              = $this->applied_fixture();
		$record                               = get_post_meta( $fixture['postId'], Migration_Backup_Store::META_KEY, true );
		$tampered                             = $record;
		$tampered['payload']['post']['title'] = 'private-restore-tampered-backup-sentinel';
		$this->assertTrue( update_post_meta( $fixture['postId'], Migration_Backup_Store::META_KEY, $tampered, $record ) );

		$result = $fixture['restorer']->restore( $fixture['postId'] );

		$this->assertContains( 'restore_artifacts_invalid', $result['codes'] );
		$this->assertSame( array( Deck_Mode::NATIVE ), get_post_meta( $fixture['postId'], Deck_Mode::META_KEY, false ) );
		$this->assert_redacted_result( $result, $fixture['postId'] );
	}

	/** A missing immutable revision invalidates otherwise signed restore artifacts. */
	public function test_missing_revision_fails_closed(): void {
		$fixture = $this->applied_fixture();
		$context = $this->journal_context( $fixture['postId'] );
		$this->assertNotFalse( wp_delete_post( $context['revisionId'], true ) );

		$result = $fixture['restorer']->restore( $fixture['postId'] );

		$this->assertContains( 'restore_artifacts_invalid', $result['codes'] );
		$this->assertSame( array( Deck_Mode::NATIVE ), get_post_meta( $fixture['postId'], Deck_Mode::META_KEY, false ) );
		$this->assert_redacted_result( $result, $fixture['postId'] );
	}

	/** A changed signed journal event cannot authorize any restore work. */
	public function test_tampered_journal_fails_closed(): void {
		$fixture                                 = $this->applied_fixture();
		$record                                  = get_post_meta( $fixture['postId'], Migration_Journal::META_KEY, true );
		$tampered                                = $record;
		$tampered['context']['preconditionHash'] = str_repeat( '0', 64 );
		$this->assertTrue( update_post_meta( $fixture['postId'], Migration_Journal::META_KEY, $tampered, $record ) );

		$result = $fixture['restorer']->restore( $fixture['postId'] );

		$this->assertContains( 'not_restorable', $result['codes'] );
		$this->assertSame( array( Deck_Mode::NATIVE ), get_post_meta( $fixture['postId'], Deck_Mode::META_KEY, false ) );
		$this->assert_redacted_result( $result, $fixture['postId'] );
	}

	/** Invalid or ambiguous routing markers are never removed. */
	public function test_duplicate_marker_fails_closed(): void {
		$fixture = $this->applied_fixture();
		add_post_meta( $fixture['postId'], Deck_Mode::META_KEY, Deck_Mode::NATIVE );

		$result = $fixture['restorer']->restore( $fixture['postId'] );

		$this->assertContains( 'applied_representation_invalid', $result['codes'] );
		$this->assertSame( array( Deck_Mode::NATIVE, Deck_Mode::NATIVE ), get_post_meta( $fixture['postId'], Deck_Mode::META_KEY, false ) );
		$this->assertSame( Migration_Journal::STATE_APPLIED, $this->journal_state( $fixture['postId'] ) );
		$this->assert_redacted_result( $result, $fixture['postId'] );
	}

	/** A marker race after restore intent is detected without deleting either row. */
	public function test_marker_race_after_restore_intent_fails_closed(): void {
		$observer = new Presenter_Test_Migration_Restore_Observer(
			static function ( string $phase, int $post_id ): void {
				if ( Migration_Restorer::PHASE_BEFORE_MARKER_REMOVAL === $phase ) {
					add_post_meta( $post_id, Deck_Mode::META_KEY, 'private-restore-racing-marker-sentinel' );
				}
			}
		);
		$fixture  = $this->applied_fixture( null, $observer );

		$result = $fixture['restorer']->restore( $fixture['postId'] );

		$this->assertContains( 'restore_precondition_changed', $result['codes'] );
		$this->assertSame(
			array( Deck_Mode::NATIVE, '' ),
			get_post_meta( $fixture['postId'], Deck_Mode::META_KEY, false )
		);
		$this->assertSame( Migration_Journal::STATE_RESTORE_PREPARED, $this->journal_state( $fixture['postId'] ) );
		$this->assert_redacted_result( $result, $fixture['postId'] );
	}

	/** A content race at inverse CAS is preserved and escalated to recovery. */
	public function test_inverse_compare_and_swap_race_records_recovery_required(): void {
		$writer              = new Presenter_Test_Migration_Writer(
			static function ( int $call, int $post_id ): void {
				if ( 1 === $call ) {
					wp_update_post(
						array(
							'ID'           => $post_id,
							'post_content' => '<p>private-restore-third-party-content-sentinel</p>',
						)
					);
				}
			}
		);
		$fixture             = $this->applied_fixture();
		$fixture['restorer'] = $this->new_restorer( $fixture, $writer, new Presenter_Test_Migration_Restore_Observer() );

		$result = $fixture['restorer']->restore( $fixture['postId'] );

		$this->assertContains( 'recovery_required', $result['codes'] );
		$this->assertSame( 1, $writer->calls );
		$this->assertSame( '<p>private-restore-third-party-content-sentinel</p>', get_post( $fixture['postId'] )->post_content );
		$this->assertSame( array(), get_post_meta( $fixture['postId'], Deck_Mode::META_KEY, false ) );
		$this->assertSame( Migration_Journal::STATE_RECOVERY_REQUIRED, $this->journal_state( $fixture['postId'] ) );
		$this->assert_redacted_result( $result, $fixture['postId'] );
	}

	/**
	 * Build, prepare, and apply one real legacy deck.
	 *
	 * @param Presenter_Test_Migration_Writer|null $restore_writer Restore writer seam.
	 * @param Migration_Restore_Observer|null      $observer       Restore observer seam.
	 * @return array<string, mixed> Applied fixture and service graph.
	 */
	private function applied_fixture( ?Presenter_Test_Migration_Writer $restore_writer = null, ?Migration_Restore_Observer $observer = null ): array {
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
		$applier     = new Migration_Applier(
			$builder,
			$secret,
			$lock,
			$revision,
			$status,
			$mode_store,
			$structure,
			new Atomic_Migration_Post_Content_Writer(),
			new Null_Migration_Apply_Observer()
		);

		$this->assertContains( 'prepared', $preparer->prepare( $post_id )['codes'] );
		$this->assertContains( 'applied', $applier->apply( $post_id )['codes'] );

		$fixture             = compact( 'lock', 'mode_store', 'post_id', 'revision', 'secret', 'status', 'structure' );
		$fixture['postId']   = $post_id;
		$fixture['restorer'] = $this->new_restorer(
			$fixture,
			$restore_writer ?? new Presenter_Test_Migration_Writer(),
			$observer ?? new Presenter_Test_Migration_Restore_Observer()
		);

		return $fixture;
	}

	/** Create one losslessly plannable legacy slideshow. */
	private function create_ready_deck(): int {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'     => 'slideshow',
				'post_name'     => 'private-restore-slug-sentinel',
				'post_status'   => 'publish',
				'post_title'    => 'private-restore-title-sentinel',
				'post_excerpt'  => 'private-restore-excerpt-sentinel',
				'post_content'  => '',
				'post_password' => 'private-restore-password-sentinel',
				'menu_order'    => 23,
			)
		);
		add_post_meta(
			$post_id,
			'_presenter_slides',
			(object) array(
				'number'  => 1,
				'title'   => 'private-restore-slide-title-sentinel',
				'content' => '<p>private-restore-slide-content-sentinel</p>',
				'class'   => '',
			)
		);
		add_post_meta( $post_id, '_presenter-short-url', 'private-restore-short-url-sentinel' );

		return $post_id;
	}

	/**
	 * Construct a restorer around the fixture's shared persistent services.
	 *
	 * @param array<string, mixed>            $fixture Fixture service graph.
	 * @param Presenter_Test_Migration_Writer $writer  Instrumented atomic writer.
	 * @param Migration_Restore_Observer      $observer Phase observer.
	 */
	private function new_restorer( array $fixture, Presenter_Test_Migration_Writer $writer, Migration_Restore_Observer $observer ): Migration_Restorer {
		return new Migration_Restorer(
			$fixture['secret'],
			$fixture['lock'],
			$fixture['revision'],
			$fixture['status'],
			$fixture['mode_store'],
			$fixture['structure'],
			$writer,
			$observer
		);
	}

	/**
	 * Create an observer that throws at one exact restore phase.
	 *
	 * @param string $throw_phase Restore phase that should throw.
	 */
	private function throwing_observer( string $throw_phase ): Migration_Restore_Observer {
		return new Presenter_Test_Migration_Restore_Observer(
			static function ( string $phase ) use ( $throw_phase ): void {
				if ( $throw_phase === $phase ) {
					throw new RuntimeException( 'private-restore-observer-exception-sentinel' );
				}
			}
		);
	}

	/**
	 * Capture the exact live representation and durable migration events.
	 *
	 * @param int $post_id Slideshow post ID.
	 */
	private function footprint( int $post_id ): string {
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
	 * Read verified journal context for deliberate artifact mutation.
	 *
	 * @param int $post_id Slideshow post ID.
	 */
	private function journal_context( int $post_id ): array {
		$secret = ( new Migration_Secret() )->read();
		$this->assertIsString( $secret );
		$context = ( new Migration_Journal( new Migration_Hasher( $secret ) ) )->verified_context( $post_id );
		$this->assertIsArray( $context );

		return $context;
	}

	/**
	 * Read the verified journal state without exposing its signed context.
	 *
	 * @param int $post_id Slideshow post ID.
	 */
	private function journal_state( int $post_id ): ?string {
		$secret = ( new Migration_Secret() )->read();
		$this->assertIsString( $secret );

		return ( new Migration_Journal( new Migration_Hasher( $secret ) ) )->inspect( $post_id )['state'];
	}

	/**
	 * Assert restore responses contain no private authored or integrity data.
	 *
	 * @param array<string, mixed> $result  Public restore result.
	 * @param int                  $post_id Slideshow post ID.
	 */
	private function assert_redacted_result( array $result, int $post_id ): void {
		$json = wp_json_encode( $result );
		$this->assertIsString( $json );
		foreach (
			array(
				'private-restore-slug-sentinel',
				'private-restore-title-sentinel',
				'private-restore-excerpt-sentinel',
				'private-restore-password-sentinel',
				'private-restore-slide-title-sentinel',
				'private-restore-slide-content-sentinel',
				'private-restore-short-url-sentinel',
				'private-restore-racing-title-sentinel',
				'private-restore-racing-meta-sentinel',
				'private-restore-tampered-backup-sentinel',
				'private-restore-racing-marker-sentinel',
				'private-restore-third-party-content-sentinel',
				'private-restore-observer-exception-sentinel',
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
	 * @param array<array-key, mixed> $values Public response subtree.
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

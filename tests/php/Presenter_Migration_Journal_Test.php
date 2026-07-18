<?php
/**
 * Immutable migration journal tests.
 *
 * @package Presenter
 */

use Presenter\Migration_Hasher;
use Presenter\Migration_Journal;

require_once dirname( __DIR__, 2 ) . '/includes/class-migration-value-encoder.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-migration-hasher.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-migration-journal.php';

/**
 * Verify hash-chained, append-only migration history and safe status output.
 */
final class Presenter_Migration_Journal_Test extends Presenter_Test_Case {
	/** Stable UUID-v4 fixture for one migration attempt. */
	private const ATTEMPT_ONE = '11111111-1111-4111-8111-111111111111';

	/** Stable UUID-v4 fixture for a later migration attempt. */
	private const ATTEMPT_TWO = '22222222-2222-4222-8222-222222222222';

	/** An empty inspection is valid, content-free, and zero-write. */
	public function test_inspect_reports_empty_journal_without_writing(): void {
		$post_id = $this->create_journal_post();
		$journal = $this->journal();

		$status = $journal->inspect( $post_id );

		$this->assert_content_free_status( $status );
		$this->assertSame( null, $status['state'] );
		$this->assertSame( null, $status['attemptId'] );
		$this->assertSame( 0, $status['sequence'] );
		$this->assertTrue( $status['valid'] );
		$this->assertSame( 'empty', $status['code'] );
		$this->assertFalse( metadata_exists( 'post', $post_id, Migration_Journal::META_KEY ) );
	}

	/** The first event is a complete, signed apply preparation. */
	public function test_first_event_is_apply_prepared_with_audit_fields(): void {
		$post_id    = $this->create_journal_post();
		$attempt_id = self::ATTEMPT_ONE;
		$status     = $this->journal()->append(
			$post_id,
			$attempt_id,
			Migration_Journal::STATE_APPLY_PREPARED,
			array( 'plannerVersion' => 1 )
		);
		$events     = get_post_meta( $post_id, Migration_Journal::META_KEY, false );

		$this->assert_content_free_status( $status );
		$this->assertSame( Migration_Journal::STATE_APPLY_PREPARED, $status['state'] );
		$this->assertSame( $attempt_id, $status['attemptId'] );
		$this->assertSame( 1, $status['sequence'] );
		$this->assertTrue( $status['valid'] );
		$this->assertSame( 'valid', $status['code'] );
		$this->assertCount( 1, $events );

		$event = $events[0];
		$this->assertSame( 1, $event['schemaVersion'] );
		$this->assertTrue( wp_is_uuid( $event['eventId'], 4 ) );
		$this->assertNotFalse( strtotime( $event['occurredAt'] ) );
		$this->assertSame( $attempt_id, $event['attemptId'] );
		$this->assertSame( null, $event['fromState'] );
		$this->assertSame( Migration_Journal::STATE_APPLY_PREPARED, $event['toState'] );
		$this->assertSame( 1, $event['sequence'] );
		$this->assertSame( '', $event['previousEventHash'] );
		$this->assertSame( array( 'plannerVersion' => 1 ), $event['context'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $event['eventHash'] );
	}

	/** A complete apply/restore chain links immutable events across attempts. */
	public function test_complete_chain_links_events_and_restored_can_begin_new_attempt(): void {
		$post_id = $this->create_journal_post();
		$journal = $this->journal();
		$context = array( 'operation' => 'private-context-sentinel' );

		$journal->append( $post_id, self::ATTEMPT_ONE, Migration_Journal::STATE_APPLY_PREPARED, $context );
		$first = get_post_meta( $post_id, Migration_Journal::META_KEY, false )[0];
		$journal->append( $post_id, self::ATTEMPT_ONE, Migration_Journal::STATE_APPLIED, array() );
		$journal->append( $post_id, self::ATTEMPT_ONE, Migration_Journal::STATE_RESTORE_PREPARED, array() );
		$journal->append( $post_id, self::ATTEMPT_ONE, Migration_Journal::STATE_RESTORED, array() );
		$status = $journal->append( $post_id, self::ATTEMPT_TWO, Migration_Journal::STATE_APPLY_PREPARED, array() );
		$events = get_post_meta( $post_id, Migration_Journal::META_KEY, false );

		$this->assertCount( 5, $events );
		$this->assertSame( $first, $events[0], 'Appending must not rewrite an earlier event.' );
		foreach ( $events as $index => $event ) {
			$this->assertSame( $index + 1, $event['sequence'] );
			if ( 0 < $index ) {
				$this->assertSame( $events[ $index - 1 ]['eventHash'], $event['previousEventHash'] );
			}
		}

		$this->assert_content_free_status( $status );
		$this->assertSame( Migration_Journal::STATE_APPLY_PREPARED, $status['state'] );
		$this->assertSame( self::ATTEMPT_TWO, $status['attemptId'] );
		$this->assertSame( 5, $status['sequence'] );
		$this->assertStringNotContainsString( 'private-context-sentinel', wp_json_encode( $status ) );
	}

	/** Retrying the exact latest event is idempotent. */
	public function test_exact_retry_returns_existing_status_without_adding_event(): void {
		$post_id = $this->create_journal_post();
		$journal = $this->journal();
		$context = array( 'sourceFingerprint' => 'fingerprint-sentinel' );

		$first = $journal->append( $post_id, self::ATTEMPT_ONE, Migration_Journal::STATE_APPLY_PREPARED, $context );
		$retry = $journal->append( $post_id, self::ATTEMPT_ONE, Migration_Journal::STATE_APPLY_PREPARED, $context );

		$this->assertSame( $first, $retry );
		$this->assertCount( 1, get_post_meta( $post_id, Migration_Journal::META_KEY, false ) );
	}

	/** Every characterized failure transition is accepted from its valid source. */
	public function test_characterized_failure_transitions_are_accepted(): void {
		$cases = array(
			array( Migration_Journal::STATE_APPLY_PREPARED, Migration_Journal::STATE_APPLY_ROLLED_BACK ),
			array( Migration_Journal::STATE_APPLY_PREPARED, Migration_Journal::STATE_RECOVERY_REQUIRED ),
			array( Migration_Journal::STATE_APPLIED, Migration_Journal::STATE_RECOVERY_REQUIRED ),
			array( Migration_Journal::STATE_RESTORE_PREPARED, Migration_Journal::STATE_RECOVERY_REQUIRED ),
		);

		foreach ( $cases as $case ) {
			$post_id = $this->create_journal_post();
			$journal = $this->journal();
			$journal->append( $post_id, self::ATTEMPT_ONE, Migration_Journal::STATE_APPLY_PREPARED, array() );
			if ( in_array( $case[0], array( Migration_Journal::STATE_APPLIED, Migration_Journal::STATE_RESTORE_PREPARED ), true ) ) {
				$journal->append( $post_id, self::ATTEMPT_ONE, Migration_Journal::STATE_APPLIED, array() );
			}
			if ( Migration_Journal::STATE_RESTORE_PREPARED === $case[0] ) {
				$journal->append( $post_id, self::ATTEMPT_ONE, Migration_Journal::STATE_RESTORE_PREPARED, array() );
			}

			$status = $journal->append( $post_id, self::ATTEMPT_ONE, $case[1], array() );
			$this->assertSame( $case[1], $status['state'] );
			$this->assertTrue( $status['valid'] );
		}
	}

	/** Invalid states, transitions, and attempt changes cannot append rows. */
	public function test_invalid_transitions_are_rejected_without_writing_an_event(): void {
		$post_id = $this->create_journal_post();
		$journal = $this->journal();

		try {
			$journal->append( $post_id, self::ATTEMPT_ONE, Migration_Journal::STATE_APPLIED, array() );
			$this->fail( 'An applied event cannot begin a journal.' );
		} catch ( UnexpectedValueException $error ) {
			$this->assertSame( 0, $this->journal_event_count( $post_id ) );
		}

		$journal->append( $post_id, self::ATTEMPT_ONE, Migration_Journal::STATE_APPLY_PREPARED, array() );
		foreach (
			array(
				array( self::ATTEMPT_TWO, Migration_Journal::STATE_APPLIED ),
				array( self::ATTEMPT_ONE, Migration_Journal::STATE_RESTORE_PREPARED ),
				array( self::ATTEMPT_ONE, 'unknown-state' ),
			) as $invalid
		) {
			try {
				$journal->append( $post_id, $invalid[0], $invalid[1], array() );
				$this->fail( 'The invalid transition should throw.' );
			} catch ( UnexpectedValueException $error ) {
				$this->assertSame( 1, $this->journal_event_count( $post_id ) );
			}
		}
	}

	/** An invalid attempt identifier cannot persist an event that inspection rejects. */
	public function test_non_uuid_attempt_is_rejected_before_writing(): void {
		$post_id = $this->create_journal_post();

		$this->expectException( UnexpectedValueException::class );
		try {
			$this->journal()->append( $post_id, 'not-a-uuid', Migration_Journal::STATE_APPLY_PREPARED, array() );
		} finally {
			$this->assertSame( 0, $this->journal_event_count( $post_id ) );
		}
	}

	/** Rolled-back and recovery states remain terminal. */
	public function test_terminal_states_reject_further_events(): void {
		foreach ( array( Migration_Journal::STATE_APPLY_ROLLED_BACK, Migration_Journal::STATE_RECOVERY_REQUIRED ) as $terminal ) {
			$post_id = $this->create_journal_post();
			$journal = $this->journal();
			$journal->append( $post_id, self::ATTEMPT_ONE, Migration_Journal::STATE_APPLY_PREPARED, array() );
			$journal->append( $post_id, self::ATTEMPT_ONE, $terminal, array() );

			try {
				$journal->append( $post_id, self::ATTEMPT_TWO, Migration_Journal::STATE_APPLY_PREPARED, array() );
				$this->fail( 'A terminal journal must not accept another event.' );
			} catch ( UnexpectedValueException $error ) {
				$this->assertSame( 2, $this->journal_event_count( $post_id ) );
			}
		}
	}

	/** Changing a stored event is detected without exposing its private fields. */
	public function test_inspect_detects_tampered_event_hash(): void {
		$post_id = $this->create_journal_post();
		$journal = $this->journal();
		$journal->append( $post_id, self::ATTEMPT_ONE, Migration_Journal::STATE_APPLY_PREPARED, array( 'private' => 'original' ) );
		$original            = get_post_meta( $post_id, Migration_Journal::META_KEY, false )[0];
		$tampered            = $original;
		$tampered['context'] = array( 'private' => 'changed' );
		update_post_meta( $post_id, Migration_Journal::META_KEY, $tampered, $original );

		$status = $journal->inspect( $post_id );

		$this->assert_content_free_status( $status );
		$this->assertFalse( $status['valid'] );
		$this->assertSame( 'chain_invalid', $status['code'] );
		$this->assertStringNotContainsString( 'original', wp_json_encode( $status ) );
		$this->assertStringNotContainsString( 'changed', wp_json_encode( $status ) );
		$this->assertStringNotContainsString( $original['eventHash'], wp_json_encode( $status ) );
	}

	/** A stored event cannot claim an impossible transition. */
	public function test_inspect_detects_tampered_transition(): void {
		$post_id = $this->create_journal_post();
		$journal = $this->journal();
		$journal->append( $post_id, self::ATTEMPT_ONE, Migration_Journal::STATE_APPLY_PREPARED, array() );
		$original            = get_post_meta( $post_id, Migration_Journal::META_KEY, false )[0];
		$tampered            = $original;
		$tampered['toState'] = Migration_Journal::STATE_APPLIED;
		update_post_meta( $post_id, Migration_Journal::META_KEY, $tampered, $original );

		$status = $journal->inspect( $post_id );

		$this->assert_content_free_status( $status );
		$this->assertFalse( $status['valid'] );
		$this->assertSame( 'transition_invalid', $status['code'] );
	}

	/** A duplicated sequence is rejected even when the copied event hash is intact. */
	public function test_inspect_detects_duplicate_sequence(): void {
		$post_id = $this->create_journal_post();
		$journal = $this->journal();
		$journal->append( $post_id, self::ATTEMPT_ONE, Migration_Journal::STATE_APPLY_PREPARED, array() );
		$journal->append( $post_id, self::ATTEMPT_ONE, Migration_Journal::STATE_APPLIED, array() );
		$events = get_post_meta( $post_id, Migration_Journal::META_KEY, false );
		add_post_meta( $post_id, Migration_Journal::META_KEY, $events[1] );

		$status = $journal->inspect( $post_id );

		$this->assert_content_free_status( $status );
		$this->assertFalse( $status['valid'] );
		$this->assertSame( 'sequence_invalid', $status['code'] );
	}

	/** Build a journal with a deterministic private test secret. */
	private function journal(): Migration_Journal {
		return new Migration_Journal( new Migration_Hasher( 'journal-test-secret' ) );
	}

	/** Create one otherwise ordinary slideshow for journal metadata. */
	private function create_journal_post(): int {
		return $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_title' => 'Migration journal fixture',
			)
		);
	}

	/**
	 * Count stored immutable journal rows.
	 *
	 * @param int $post_id Slideshow post ID.
	 */
	private function journal_event_count( int $post_id ): int {
		return count( get_post_meta( $post_id, Migration_Journal::META_KEY, false ) );
	}

	/**
	 * Assert the exact content-free public status schema.
	 *
	 * @param array<string, mixed> $status Journal status.
	 */
	private function assert_content_free_status( array $status ): void {
		$keys = array_keys( $status );
		sort( $keys, SORT_STRING );

		$this->assertSame( array( 'attemptId', 'code', 'sequence', 'state', 'valid' ), $keys );
		$this->assertArrayNotHasKey( 'eventHash', $status );
		$this->assertArrayNotHasKey( 'previousEventHash', $status );
		$this->assertArrayNotHasKey( 'context', $status );
	}
}

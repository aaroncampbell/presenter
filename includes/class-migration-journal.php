<?php
/**
 * Immutable Presenter migration journal.
 *
 * @package Presenter
 */

namespace Presenter;

use Throwable;
use UnexpectedValueException;
use WP_Post;

/**
 * Persists and verifies a hash-chained sequence of migration state events.
 */
final class Migration_Journal {
	/** Append-only private journal metadata key. */
	public const META_KEY = '_presenter_migration_journal_v1';

	/** Native apply has been prepared but content has not been cut over. */
	public const STATE_APPLY_PREPARED = 'apply_prepared';

	/** Native content has been verified and cut over. */
	public const STATE_APPLIED = 'applied';

	/** A failed apply returned safely to its original content. */
	public const STATE_APPLY_ROLLED_BACK = 'apply_rolled_back';

	/** Restore has been prepared but not completed. */
	public const STATE_RESTORE_PREPARED = 'restore_prepared';

	/** The original content and legacy route have been restored. */
	public const STATE_RESTORED = 'restored';

	/** Automated recovery cannot safely determine the correct representation. */
	public const STATE_RECOVERY_REQUIRED = 'recovery_required';

	/** Journal event schema version. */
	private const SCHEMA_VERSION = 1;

	/**
	 * Create the journal.
	 *
	 * @param Migration_Hasher $hasher Site-keyed migration hasher.
	 */
	public function __construct( private Migration_Hasher $hasher ) {}

	/**
	 * Append one valid transition and return a content-free status.
	 *
	 * An exact retry of the current state and context is idempotent and does not
	 * append a second event.
	 *
	 * @param int                  $post_id   Slideshow post ID.
	 * @param string               $attempt_id Migration attempt UUID.
	 * @param string               $state      Target journal state.
	 * @param array<string, mixed> $context    Internal integrity references.
	 * @return array{state: string|null, attemptId: string|null, sequence: int, valid: bool, code: string} Content-free status.
	 * @throws UnexpectedValueException When input or the requested transition is invalid.
	 */
	public function append( int $post_id, string $attempt_id, string $state, array $context = array() ): array {
		if ( ! $this->is_slideshow( $post_id ) || ! wp_is_uuid( $attempt_id, 4 ) || ! $this->is_state( $state ) ) {
			throw new UnexpectedValueException( 'Invalid migration journal input.' );
		}

		$inspection = $this->inspect_events( $post_id );
		if ( ! $inspection['status']['valid'] ) {
			return $inspection['status'];
		}

		$last = $inspection['last'];
		if (
			is_array( $last ) &&
			$attempt_id === $last['attemptId'] &&
			$state === $last['toState'] &&
			$this->same_context( $context, $last['context'] )
		) {
			return $inspection['status'];
		}

		$from = is_array( $last ) ? $last['toState'] : null;
		if ( ! $this->transition_allowed( $from, $state, $attempt_id, $last ) ) {
			throw new UnexpectedValueException( 'Invalid migration journal transition.' );
		}

		$event = array(
			'schemaVersion'     => self::SCHEMA_VERSION,
			'eventId'           => wp_generate_uuid4(),
			'attemptId'         => $attempt_id,
			'sequence'          => $inspection['status']['sequence'] + 1,
			'fromState'         => $from,
			'toState'           => $state,
			'occurredAt'        => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'context'           => $context,
			'previousEventHash' => is_array( $last ) ? $last['eventHash'] : '',
		);

		try {
			$event['eventHash'] = $this->hasher->hash( 'journal-event', $event );
		} catch ( Throwable ) {
			return $this->status( $from, is_array( $last ) ? $last['attemptId'] : null, $event['sequence'] - 1, false, 'invalid_context' );
		}

		if ( false === add_post_meta( $post_id, self::META_KEY, $event, false ) ) {
			return $this->status( $from, is_array( $last ) ? $last['attemptId'] : null, $event['sequence'] - 1, false, 'write_failed' );
		}

		return $this->inspect( $post_id );
	}

	/**
	 * Verify the complete chain and return only safe state classifications.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return array{state: string|null, attemptId: string|null, sequence: int, valid: bool, code: string} Content-free status.
	 */
	public function inspect( int $post_id ): array {
		if ( ! $this->is_slideshow( $post_id ) ) {
			return $this->status( null, null, 0, false, 'invalid_input' );
		}

		return $this->inspect_events( $post_id )['status'];
	}

	/**
	 * Verify stored events and retain the final event for internal appends.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return array{status: array{state: string|null, attemptId: string|null, sequence: int, valid: bool, code: string}, last: array<string, mixed>|null} Inspection.
	 */
	private function inspect_events( int $post_id ): array {
		$events = get_post_meta( $post_id, self::META_KEY, false );
		if ( array() === $events ) {
			return array(
				'status' => $this->status( null, null, 0, true, 'empty' ),
				'last'   => null,
			);
		}

		$previous = null;
		foreach ( array_values( $events ) as $index => $event ) {
			if ( ! is_array( $event ) || ! $this->valid_event_shape( $event ) ) {
				return $this->invalid_chain_status( $previous, 'chain_invalid' );
			}

			$expected_sequence = $index + 1;
			if ( $expected_sequence !== $event['sequence'] ) {
				return $this->invalid_chain_status( $previous, 'sequence_invalid' );
			}

			$expected_from = is_array( $previous ) ? $previous['toState'] : null;
			$expected_hash = is_array( $previous ) ? $previous['eventHash'] : '';
			if (
				$expected_from !== $event['fromState'] ||
				$expected_hash !== $event['previousEventHash'] ||
				! $this->transition_allowed( $event['fromState'], $event['toState'], $event['attemptId'], $previous )
			) {
				return $this->invalid_chain_status( $previous, 'transition_invalid' );
			}

			$stored_hash = $event['eventHash'];
			unset( $event['eventHash'] );
			try {
				$valid_hash = hash_equals( $stored_hash, $this->hasher->hash( 'journal-event', $event ) );
			} catch ( Throwable ) {
				$valid_hash = false;
			}
			$event['eventHash'] = $stored_hash;

			if ( ! $valid_hash ) {
				return $this->invalid_chain_status( $previous, 'chain_invalid' );
			}

			$previous = $event;
		}

		return array(
			'status' => $this->status( $previous['toState'], $previous['attemptId'], $previous['sequence'], true, 'valid' ),
			'last'   => $previous,
		);
	}

	/**
	 * Validate all event fields before using or hashing them.
	 *
	 * @param array<string, mixed> $event Stored journal event.
	 * @return bool Whether the event has the expected schema.
	 */
	private function valid_event_shape( array $event ): bool {
		return self::SCHEMA_VERSION === ( $event['schemaVersion'] ?? null )
			&& isset( $event['eventId'] )
			&& is_string( $event['eventId'] )
			&& wp_is_uuid( $event['eventId'], 4 )
			&& isset( $event['attemptId'] )
			&& is_string( $event['attemptId'] )
			&& wp_is_uuid( $event['attemptId'], 4 )
			&& isset( $event['sequence'] )
			&& is_int( $event['sequence'] )
			&& array_key_exists( 'fromState', $event )
			&& ( null === $event['fromState'] || is_string( $event['fromState'] ) )
			&& isset( $event['toState'] )
			&& is_string( $event['toState'] )
			&& isset( $event['occurredAt'] )
			&& is_string( $event['occurredAt'] )
			&& isset( $event['context'] )
			&& is_array( $event['context'] )
			&& isset( $event['previousEventHash'] )
			&& is_string( $event['previousEventHash'] )
			&& isset( $event['eventHash'] )
			&& is_string( $event['eventHash'] )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', $event['eventHash'] );
	}

	/**
	 * Determine whether one state transition and attempt identity is valid.
	 *
	 * @param string|null               $from       Previous state.
	 * @param string                    $to         Target state.
	 * @param string                    $attempt_id Target attempt ID.
	 * @param array<string, mixed>|null $previous  Previous event.
	 * @return bool Whether the transition is allowed.
	 */
	private function transition_allowed( ?string $from, string $to, string $attempt_id, ?array $previous ): bool {
		$allowed  = array(
			'none'                       => array( self::STATE_APPLY_PREPARED ),
			self::STATE_APPLY_PREPARED   => array( self::STATE_APPLIED, self::STATE_APPLY_ROLLED_BACK, self::STATE_RECOVERY_REQUIRED ),
			self::STATE_APPLIED          => array( self::STATE_RESTORE_PREPARED, self::STATE_RECOVERY_REQUIRED ),
			self::STATE_RESTORE_PREPARED => array( self::STATE_RESTORED, self::STATE_RECOVERY_REQUIRED ),
			self::STATE_RESTORED         => array( self::STATE_APPLY_PREPARED ),
		);
		$from_key = null === $from ? 'none' : $from;

		if ( ! isset( $allowed[ $from_key ] ) || ! in_array( $to, $allowed[ $from_key ], true ) ) {
			return false;
		}

		if ( ! is_array( $previous ) ) {
			return null === $from;
		}

		return self::STATE_RESTORED === $from
			? $attempt_id !== $previous['attemptId']
			: $attempt_id === $previous['attemptId'];
	}

	/**
	 * Compare contexts with the canonical typed representation.
	 *
	 * @param array<string, mixed> $first  First context.
	 * @param mixed                $second Stored context.
	 * @return bool Whether the values are exactly equivalent.
	 */
	private function same_context( array $first, mixed $second ): bool {
		if ( ! is_array( $second ) ) {
			return false;
		}

		try {
			return Migration_Value_Encoder::encode( $first ) === Migration_Value_Encoder::encode( $second );
		} catch ( Throwable ) {
			return false;
		}
	}

	/**
	 * Determine whether a string is a known state.
	 *
	 * @param string $state Candidate state.
	 * @return bool Whether the state belongs to this schema.
	 */
	private function is_state( string $state ): bool {
		return in_array(
			$state,
			array(
				self::STATE_APPLY_PREPARED,
				self::STATE_APPLIED,
				self::STATE_APPLY_ROLLED_BACK,
				self::STATE_RESTORE_PREPARED,
				self::STATE_RESTORED,
				self::STATE_RECOVERY_REQUIRED,
			),
			true
		);
	}

	/**
	 * Determine whether an ID belongs to a slideshow post.
	 *
	 * @param int $post_id Candidate post ID.
	 * @return bool Whether the post is a slideshow.
	 */
	private function is_slideshow( int $post_id ): bool {
		$post = get_post( $post_id );

		return $post instanceof WP_Post && 'slideshow' === $post->post_type;
	}

	/**
	 * Build a content-free public status.
	 *
	 * @param string|null $state      Current state.
	 * @param string|null $attempt_id Current attempt UUID.
	 * @param int         $sequence   Last verified sequence.
	 * @param bool        $valid      Whether the chain or operation is valid.
	 * @param string      $code       Content-free result code.
	 * @return array{state: string|null, attemptId: string|null, sequence: int, valid: bool, code: string} Status.
	 */
	private function status( ?string $state, ?string $attempt_id, int $sequence, bool $valid, string $code ): array {
		return array(
			'state'     => $state,
			'attemptId' => $attempt_id,
			'sequence'  => $sequence,
			'valid'     => $valid,
			'code'      => $code,
		);
	}

	/**
	 * Build an invalid status without exposing the malformed event.
	 *
	 * @param array<string, mixed>|null $previous Last verified event.
	 * @param string                    $code     Content-free failure code.
	 * @return array{status: array{state: string|null, attemptId: string|null, sequence: int, valid: bool, code: string}, last: array<string, mixed>|null} Inspection.
	 */
	private function invalid_chain_status( ?array $previous, string $code ): array {
		return array(
			'status' => is_array( $previous )
				? $this->status( $previous['toState'], $previous['attemptId'], $previous['sequence'], false, $code )
				: $this->status( null, null, 0, false, $code ),
			'last'   => $previous,
		);
	}
}

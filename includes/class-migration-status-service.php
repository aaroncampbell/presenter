<?php
/**
 * Content-free Presenter migration status.
 *
 * @package Presenter
 */

namespace Presenter;

use WP_Post;

/**
 * Classifies migration readiness without creating or changing any state.
 */
final class Migration_Status_Service {
	/** Status response schema version. */
	private const SCHEMA_VERSION = 1;

	/**
	 * Create the status service.
	 *
	 * @param Legacy_Deck_Snapshotter   $snapshotter Legacy snapshot service.
	 * @param Migration_Planner         $planner     Pure migration planner.
	 * @param Migration_Secret          $secret      Zero-write secret reader.
	 * @param Migration_Lock            $lock        Migration lock service.
	 * @param Migration_Revision        $revision    Revision verifier.
	 * @param Deck_Mode                 $deck_mode   Deck-mode resolver.
	 * @param Migration_Deck_Mode_Store $mode_store Exact private marker storage.
	 */
	public function __construct(
		private Legacy_Deck_Snapshotter $snapshotter,
		private Migration_Planner $planner,
		private Migration_Secret $secret,
		private Migration_Lock $lock,
		private Migration_Revision $revision,
		private Deck_Mode $deck_mode,
		private Migration_Deck_Mode_Store $mode_store
	) {}

	/**
	 * Inspect one slideshow without writing or exposing private migration data.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return array<string, mixed> Content-free status envelope.
	 */
	public function inspect( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'slideshow' !== $post->post_type ) {
			return $this->invalid_status( $post_id );
		}

		$lock_status = $this->lock->inspect( $post_id );
		$deck_mode   = $this->deck_mode->mode( $post_id );
		$mode_state  = $this->mode_store->inspect( $post_id )['state'];
		$snapshot    = $this->snapshotter->capture( $post_id );
		$plan_state  = 'ineligible';
		if ( null !== $snapshot ) {
			$plan_state = $this->planner->plan( $snapshot )->is_ready() ? 'ready' : 'blocked';
		}

		$secret = $this->secret->read();
		if ( null === $secret ) {
			$has_artifacts = metadata_exists( 'post', $post_id, Migration_Journal::META_KEY )
				|| metadata_exists( 'post', $post_id, Migration_Backup_Store::META_KEY );

			return $this->envelope(
				$post_id,
				$lock_status,
				$plan_state,
				array(
					'state'     => null,
					'attemptId' => null,
					'sequence'  => 0,
					'integrity' => $has_artifacts ? 'unavailable' : 'empty',
					'code'      => $has_artifacts ? 'secret_missing' : 'empty',
				),
				array(
					'precondition' => 'untracked',
					'retained'     => 'untracked',
				),
				'untracked',
				array(
					'state'    => 'missing',
					'revision' => 'missing',
				),
				! $has_artifacts && Deck_Mode::LEGACY === $deck_mode && Migration_Deck_Mode_Store::ABSENT === $mode_state && 'ready' === $plan_state && in_array( $lock_status['state'], array( 'unlocked', 'expired' ), true ),
				false,
				false,
				$this->mode_codes( $deck_mode, $mode_state, $has_artifacts ? array( 'secret_missing' ) : array( 'unprepared' ) )
			);
		}

		$hasher         = new Migration_Hasher( $secret );
		$journal        = new Migration_Journal( $hasher );
		$journal_status = $journal->inspect( $post_id );
		$journal_public = array(
			'state'     => $journal_status['state'],
			'attemptId' => $journal_status['attemptId'],
			'sequence'  => $journal_status['sequence'],
			'integrity' => $journal_status['valid'] ? ( 'empty' === $journal_status['code'] ? 'empty' : 'verified' ) : 'invalid',
			'code'      => $journal_status['code'],
		);

		if ( ! $journal_status['valid'] || 'empty' === $journal_status['code'] ) {
			$codes = $journal_status['valid'] ? array( 'unprepared' ) : array( 'journal_invalid' );

			return $this->envelope(
				$post_id,
				$lock_status,
				$plan_state,
				$journal_public,
				array(
					'precondition' => 'untracked',
					'retained'     => 'untracked',
				),
				'untracked',
				array(
					'state'    => 'missing',
					'revision' => 'missing',
				),
				$journal_status['valid'] && Deck_Mode::LEGACY === $deck_mode && Migration_Deck_Mode_Store::ABSENT === $mode_state && 'ready' === $plan_state && in_array( $lock_status['state'], array( 'unlocked', 'expired' ), true ),
				false,
				false,
				$this->mode_codes( $deck_mode, $mode_state, $codes )
			);
		}

		$context = $journal->verified_context( $post_id );
		if ( null === $context || ! $this->valid_context_shape( $context ) ) {
			return $this->envelope(
				$post_id,
				$lock_status,
				$plan_state,
				$journal_public,
				array(
					'precondition' => 'unknown',
					'retained'     => 'unknown',
				),
				'unknown',
				array(
					'state'    => 'invalid',
					'revision' => 'invalid',
				),
				false,
				false,
				false,
				array( 'context_invalid' )
			);
		}

		$builder          = new Migration_Context_Builder( $this->snapshotter, $this->planner, $this->mode_store );
		$current_context  = $builder->build( $post_id, $hasher );
		$precondition     = null !== $current_context && $current_context->matches_preparation( $context ) ? 'match' : 'changed';
		$retained_payload = Legacy_Meta_Payload::capture( $post_id );
		$retained         = null !== $retained_payload
			&& hash_equals( $context['retainedLegacyHash'], $hasher->hash( 'retained-legacy', $retained_payload->to_array() ) )
				? 'match'
				: 'changed';
		$current_content  = $hasher->hash( 'post-content', $post->post_content );
		$content          = hash_equals( $context['originalContentHash'], $current_content )
			? 'original'
			: ( hash_equals( $context['targetContentHash'], $current_content ) ? 'target' : 'modified' );
		$backup_store     = new Migration_Backup_Store( $hasher );
		$backup_reference = $backup_store->find_verified_reference( $post_id, $context['backupReference'] );
		$backup_state     = null !== $backup_reference && $context['backupId'] === $backup_reference['backupId'] ? 'verified' : 'invalid';
		$revision_state   = $this->revision->verify_hash(
			$post_id,
			$context['revisionId'],
			$hasher,
			$context['revisionFieldsHash']
		) ? 'verified' : 'missing';
		$lock_available   = in_array( $lock_status['state'], array( 'unlocked', 'expired' ), true );
		$can_apply        = Migration_Journal::STATE_APPLY_PREPARED === $journal_status['state']
			&& Deck_Mode::LEGACY === $deck_mode
			&& Migration_Deck_Mode_Store::ABSENT === $mode_state
			&& Migration_Planner::VERSION === $context['plannerVersion']
			&& 'match' === $precondition
			&& 'match' === $retained
			&& 'original' === $content
			&& 'verified' === $backup_state
			&& 'verified' === $revision_state
			&& $lock_available;
		$can_restore      = Migration_Journal::STATE_APPLIED === $journal_status['state']
			&& Migration_Deck_Mode_Store::NATIVE === $mode_state
			&& 'match' === $retained
			&& 'target' === $content
			&& 'verified' === $backup_state
			&& 'verified' === $revision_state
			&& $lock_available;
		$can_prepare      = in_array(
			$journal_status['state'],
			array( Migration_Journal::STATE_APPLY_ROLLED_BACK, Migration_Journal::STATE_RESTORED ),
			true
		)
			&& Deck_Mode::LEGACY === $deck_mode
			&& Migration_Deck_Mode_Store::ABSENT === $mode_state
			&& 'ready' === $plan_state
			&& 'original' === $content
			&& 'verified' === $backup_state
			&& 'verified' === $revision_state
			&& $lock_available;
		$expects_native   = in_array(
			$journal_status['state'],
			array( Migration_Journal::STATE_APPLIED, Migration_Journal::STATE_RESTORE_PREPARED ),
			true
		);
		$expects_absent   = in_array(
			$journal_status['state'],
			array( Migration_Journal::STATE_APPLY_PREPARED, Migration_Journal::STATE_APPLY_ROLLED_BACK, Migration_Journal::STATE_RESTORED ),
			true
		);

		$codes = array();
		foreach (
			array(
				'precondition_changed'      => 'match' !== $precondition,
				'retained_changed'          => 'match' !== $retained,
				'content_modified'          => ! in_array( $content, array( 'original', 'target' ), true ),
				'backup_invalid'            => 'verified' !== $backup_state,
				'revision_missing'          => 'verified' !== $revision_state,
				'planner_changed'           => Migration_Planner::VERSION !== $context['plannerVersion'],
				'deck_mode_not_legacy'      => $expects_absent && Deck_Mode::LEGACY !== $deck_mode,
				'deck_mode_storage_invalid' => Migration_Deck_Mode_Store::INVALID === $mode_state,
				'deck_mode_not_native'      => $expects_native && Migration_Deck_Mode_Store::NATIVE !== $mode_state,
				'lock_unavailable'          => ! $lock_available,
			) as $code => $present
		) {
			if ( $present ) {
				$codes[] = $code;
			}
		}

		return $this->envelope(
			$post_id,
			$lock_status,
			$plan_state,
			$journal_public,
			array(
				'precondition' => $precondition,
				'retained'     => $retained,
			),
			$content,
			array(
				'state'    => $backup_state,
				'revision' => $revision_state,
			),
			$can_prepare,
			$can_apply,
			$can_restore,
			$codes
		);
	}

	/**
	 * Add a fail-safe diagnostic when legacy storage no longer owns the deck.
	 *
	 * @param string             $deck_mode Resolved authoritative mode.
	 * @param string             $mode_state Exact marker-storage state.
	 * @param array<int, string> $codes     Existing diagnostic codes.
	 * @return array<int, string> Content-free diagnostic codes.
	 */
	private function mode_codes( string $deck_mode, string $mode_state, array $codes ): array {
		if ( Deck_Mode::LEGACY !== $deck_mode ) {
			$codes[] = 'deck_mode_not_legacy';
		}
		if ( Migration_Deck_Mode_Store::INVALID === $mode_state ) {
			$codes[] = 'deck_mode_storage_invalid';
		}

		return $codes;
	}

	/**
	 * Validate the private prepared-context schema before comparing values.
	 *
	 * @param array<string, mixed> $context Candidate context.
	 * @return bool Whether required typed fields exist.
	 */
	private function valid_context_shape( array $context ): bool {
		$hash_keys   = array( 'preparationReference', 'backupReference', 'preconditionHash', 'retainedLegacyHash', 'deckModeHash', 'originalContentHash', 'targetContentHash', 'revisionFieldsHash' );
		$keys        = array_merge( $hash_keys, array( 'backupId', 'plannerVersion', 'revisionId' ) );
		$actual_keys = array_keys( $context );
		sort( $keys, SORT_STRING );
		sort( $actual_keys, SORT_STRING );
		if ( $keys !== $actual_keys ) {
			return false;
		}

		foreach ( $hash_keys as $key ) {
			if ( ! is_string( $context[ $key ] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $context[ $key ] ) ) {
				return false;
			}
		}

		return is_string( $context['backupId'] )
			&& wp_is_uuid( $context['backupId'], 4 )
			&& is_int( $context['plannerVersion'] )
			&& 0 < $context['plannerVersion']
			&& is_int( $context['revisionId'] )
			&& 0 < $context['revisionId'];
	}

	/**
	 * Build the stable content-free status envelope.
	 *
	 * @param int                  $post_id     Slideshow post ID.
	 * @param array<string, mixed> $lock        Redacted lock status.
	 * @param string               $plan_state  Plan classification.
	 * @param array<string, mixed> $journal     Redacted journal status.
	 * @param array<string, mixed> $source      Source classifications.
	 * @param string               $content     Content classification.
	 * @param array<string, mixed> $backup      Backup classifications.
	 * @param bool                 $can_prepare Whether preparation is safe.
	 * @param bool                 $can_apply   Whether apply is safe.
	 * @param bool                 $can_restore Whether restore is safe.
	 * @param array<int, string>   $codes       Content-free diagnostic codes.
	 * @return array<string, mixed> Status envelope.
	 */
	private function envelope( int $post_id, array $lock, string $plan_state, array $journal, array $source, string $content, array $backup, bool $can_prepare, bool $can_apply, bool $can_restore, array $codes ): array {
		sort( $codes, SORT_STRING );

		return array(
			'schemaVersion' => self::SCHEMA_VERSION,
			'mode'          => 'status',
			'postId'        => $post_id,
			'deckMode'      => $this->deck_mode->mode( $post_id ),
			'journal'       => $journal,
			'lock'          => $lock,
			'source'        => $source,
			'content'       => array( 'classification' => $content ),
			'backup'        => $backup,
			'plan'          => array(
				'state'          => $plan_state,
				'plannerVersion' => Migration_Planner::VERSION,
			),
			'capabilities'  => array(
				'canPrepare' => $can_prepare,
				'canApply'   => $can_apply,
				'canRestore' => $can_restore,
			),
			'codes'         => array_values( array_unique( $codes ) ),
		);
	}

	/**
	 * Build invalid input status without consulting migration storage.
	 *
	 * @param int $post_id Invalid candidate post ID.
	 * @return array<string, mixed> Invalid status envelope.
	 */
	private function invalid_status( int $post_id ): array {
		return array(
			'schemaVersion' => self::SCHEMA_VERSION,
			'mode'          => 'status',
			'postId'        => $post_id,
			'deckMode'      => null,
			'journal'       => array(
				'state'     => null,
				'attemptId' => null,
				'sequence'  => 0,
				'integrity' => 'invalid',
				'code'      => 'invalid_post',
			),
			'lock'          => array(
				'state'            => 'invalid',
				'secondsRemaining' => 0,
			),
			'source'        => array(
				'precondition' => 'unknown',
				'retained'     => 'unknown',
			),
			'content'       => array( 'classification' => 'unknown' ),
			'backup'        => array(
				'state'    => 'missing',
				'revision' => 'missing',
			),
			'plan'          => array(
				'state'          => 'ineligible',
				'plannerVersion' => Migration_Planner::VERSION,
			),
			'capabilities'  => array(
				'canPrepare' => false,
				'canApply'   => false,
				'canRestore' => false,
			),
			'codes'         => array( 'invalid_post' ),
		);
	}
}

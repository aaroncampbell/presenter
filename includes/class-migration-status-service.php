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
	 * @param Legacy_Deck_Snapshotter $snapshotter Legacy snapshot service.
	 * @param Migration_Planner       $planner     Pure migration planner.
	 * @param Migration_Secret        $secret      Zero-write secret reader.
	 * @param Migration_Lock          $lock        Migration lock service.
	 * @param Migration_Revision      $revision    Revision verifier.
	 * @param Deck_Mode               $deck_mode   Deck-mode resolver.
	 */
	public function __construct(
		private Legacy_Deck_Snapshotter $snapshotter,
		private Migration_Planner $planner,
		private Migration_Secret $secret,
		private Migration_Lock $lock,
		private Migration_Revision $revision,
		private Deck_Mode $deck_mode
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
				! $has_artifacts && 'ready' === $plan_state && in_array( $lock_status['state'], array( 'unlocked', 'expired' ), true ),
				false,
				false,
				$has_artifacts ? array( 'secret_missing' ) : array( 'unprepared' )
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
				$journal_status['valid'] && 'ready' === $plan_state && in_array( $lock_status['state'], array( 'unlocked', 'expired' ), true ),
				false,
				false,
				$codes
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

		$builder          = new Migration_Context_Builder( $this->snapshotter, $this->planner );
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
			&& Migration_Planner::VERSION === $context['plannerVersion']
			&& 'match' === $precondition
			&& 'match' === $retained
			&& 'original' === $content
			&& 'verified' === $backup_state
			&& 'verified' === $revision_state
			&& $lock_available;
		$can_restore      = Migration_Journal::STATE_APPLIED === $journal_status['state']
			&& 'match' === $retained
			&& 'target' === $content
			&& 'verified' === $backup_state
			&& $lock_available;

		$codes = array();
		foreach (
			array(
				'precondition_changed' => 'match' !== $precondition,
				'retained_changed'     => 'match' !== $retained,
				'content_modified'     => ! in_array( $content, array( 'original', 'target' ), true ),
				'backup_invalid'       => 'verified' !== $backup_state,
				'revision_missing'     => 'verified' !== $revision_state,
				'planner_changed'      => Migration_Planner::VERSION !== $context['plannerVersion'],
				'lock_unavailable'     => ! $lock_available,
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
			false,
			$can_apply,
			$can_restore,
			$codes
		);
	}

	/**
	 * Validate the private prepared-context schema before comparing values.
	 *
	 * @param array<string, mixed> $context Candidate context.
	 * @return bool Whether required typed fields exist.
	 */
	private function valid_context_shape( array $context ): bool {
		foreach ( array( 'preparationReference', 'backupReference', 'preconditionHash', 'retainedLegacyHash', 'originalContentHash', 'targetContentHash', 'revisionFieldsHash', 'backupId' ) as $key ) {
			if ( ! isset( $context[ $key ] ) || ! is_string( $context[ $key ] ) ) {
				return false;
			}
		}

		return isset( $context['plannerVersion'], $context['revisionId'] )
			&& is_int( $context['plannerVersion'] )
			&& is_int( $context['revisionId'] );
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

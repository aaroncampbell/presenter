<?php
/**
 * Explicit Presenter migration apply orchestration.
 *
 * @package Presenter
 */

namespace Presenter;

use Throwable;
use WP_Post;

/**
 * Writes and verifies prepared native content before the final routing cutover.
 */
final class Migration_Applier {
	public const PHASE_BEFORE_CONTENT_WRITE = 'before_content_write';
	public const PHASE_AFTER_CONTENT_WRITE  = 'after_content_write';
	public const PHASE_AFTER_CUTOVER        = 'after_cutover';
	public const PHASE_BEFORE_APPLIED_EVENT = 'before_applied_event';

	/**
	 * Create the apply service.
	 *
	 * @param Migration_Context_Builder     $builder    Prepared-source rebuilder.
	 * @param Migration_Secret              $secret     Signing-secret reader.
	 * @param Migration_Lock                $lock       Per-deck lock.
	 * @param Migration_Revision            $revision   Revision verifier.
	 * @param Migration_Status_Service      $status     Content-free status.
	 * @param Migration_Deck_Mode_Store     $mode_store Exact marker storage.
	 * @param Native_Deck_Structure         $structure  Native structure validator.
	 * @param Migration_Post_Content_Writer $writer     Atomic content writer.
	 * @param Migration_Apply_Observer      $observer   Phase observer.
	 */
	public function __construct(
		private Migration_Context_Builder $builder,
		private Migration_Secret $secret,
		private Migration_Lock $lock,
		private Migration_Revision $revision,
		private Migration_Status_Service $status,
		private Migration_Deck_Mode_Store $mode_store,
		private Native_Deck_Structure $structure,
		private Migration_Post_Content_Writer $writer,
		private Migration_Apply_Observer $observer
	) {}

	/**
	 * Apply one explicitly prepared slideshow.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return array<string, mixed> Content-free apply status.
	 */
	public function apply( int $post_id ): array {
		$preflight = $this->status->inspect( $post_id );
		if ( Migration_Journal::STATE_APPLIED === $preflight['journal']['state'] ) {
			return $this->as_result(
				$preflight,
				$preflight['capabilities']['canRestore'] ? 'already_applied' : 'applied_invalid'
			);
		}

		if ( Migration_Journal::STATE_APPLY_PREPARED !== $preflight['journal']['state'] ) {
			return $this->as_result( $preflight, 'not_applicable' );
		}

		$handle = $this->lock->acquire( $post_id );
		if ( null === $handle ) {
			return $this->as_result( $this->status->inspect( $post_id ), 'lock_unavailable' );
		}

		try {
			$code = $this->apply_locked( $post_id, $handle );
		} catch ( Throwable ) {
			$code = 'apply_failed';
		}

		$released = $this->lock->release( $handle );
		$result   = $this->as_result( $this->status->inspect( $post_id ), $code );
		if ( ! $released ) {
			$result['codes'][] = 'lock_release_failed';
			sort( $result['codes'], SORT_STRING );
			$result['codes'] = array_values( array_unique( $result['codes'] ) );
		}

		return $result;
	}

	/**
	 * Execute or resume apply while one verified lock owner is active.
	 *
	 * @param int                   $post_id Slideshow post ID.
	 * @param Migration_Lock_Handle $handle  Current lock owner.
	 * @return string Content-free result code.
	 */
	private function apply_locked( int $post_id, Migration_Lock_Handle &$handle ): string {
		if ( $this->has_active_edit_lock( $post_id ) ) {
			return 'edit_lock_active';
		}

		$prepared = $this->load_prepared( $post_id );
		if ( null === $prepared ) {
			return 'prepared_artifacts_invalid';
		}

		$state = $this->representation_state( $post_id, $prepared['backup'], $prepared['hasher'] );
		if ( 'target_native' === $state ) {
			return $this->complete_applied_event( $post_id, $handle, $prepared );
		}
		if ( 'target_legacy' === $state ) {
			return $this->cut_over( $post_id, $handle, $prepared, false );
		}
		if ( 'original_legacy' !== $state ) {
			return str_starts_with( $state, 'interrupted_' )
				? $this->record_recovery_required( $post_id, $prepared, $handle )
				: 'prepared_source_changed';
		}

		$current = $this->builder->build( $post_id, $prepared['hasher'] );
		if ( null === $current || ! $current->matches_preparation( $prepared['context'] ) ) {
			return 'prepared_source_changed';
		}

		$this->observer->checkpoint( self::PHASE_BEFORE_CONTENT_WRITE, $post_id );
		$renewed = $this->lock->renew( $handle );
		if ( null === $renewed || ! $this->prewrite_is_current( $post_id, $prepared ) ) {
			return 'prepared_source_changed';
		}
		$handle = $renewed;

		$backup = $prepared['backup'];
		try {
			$written = $this->writer->compare_and_swap( $post_id, $backup->post_fields(), $backup->target_content() );
		} catch ( Throwable ) {
			$written = false;
		}
		if ( ! $written ) {
			$state = $this->representation_state( $post_id, $backup, $prepared['hasher'] );
			if ( 'original_legacy' === $state ) {
				return 'content_write_conflict';
			}

			return 'target_legacy' === $state
				? $this->compensate( $post_id, $handle, $prepared, null )
				: $this->record_recovery_required( $post_id, $prepared, $handle );
		}

		try {
			$this->observer->checkpoint( self::PHASE_AFTER_CONTENT_WRITE, $post_id );
			$renewed = $this->lock->renew( $handle );
			if ( null === $renewed ) {
				return $this->compensate( $post_id, $handle, $prepared, null );
			}
			$handle = $renewed;

			if ( ! $this->postwrite_is_valid( $post_id, $prepared, Migration_Deck_Mode_Store::ABSENT ) ) {
				return $this->compensate( $post_id, $handle, $prepared, null );
			}

			return $this->cut_over( $post_id, $handle, $prepared, true );
		} catch ( Throwable ) {
			return $this->compensate( $post_id, $handle, $prepared, null );
		}
	}

	/**
	 * Add and verify the final native routing marker.
	 *
	 * @param int                   $post_id        Slideshow post ID.
	 * @param Migration_Lock_Handle $handle         Current lock handle.
	 * @param array<string, mixed>  $prepared       Trusted prepared state.
	 * @param bool                  $content_written Whether this call wrote content.
	 * @return string Content-free result code.
	 */
	private function cut_over( int $post_id, Migration_Lock_Handle &$handle, array $prepared, bool $content_written ): string {
		if ( $this->has_active_edit_lock( $post_id ) ) {
			return $content_written
				? $this->compensate( $post_id, $handle, $prepared, null )
				: 'edit_lock_active';
		}

		$renewed = $this->lock->renew( $handle );
		if ( null === $renewed ) {
			return $content_written
				? $this->compensate( $post_id, $handle, $prepared, null )
				: 'lock_lost_before_cutover';
		}
		$handle = $renewed;
		if ( ! $this->postwrite_is_valid( $post_id, $prepared, Migration_Deck_Mode_Store::ABSENT ) ) {
			return $content_written
				? $this->compensate( $post_id, $handle, $prepared, null )
				: 'cutover_precondition_changed';
		}

		try {
			$meta_id = $this->mode_store->create_native( $post_id );
		} catch ( Throwable ) {
			return $content_written ? $this->compensate( $post_id, $handle, $prepared, null ) : 'cutover_failed';
		}
		if ( null === $meta_id ) {
			return $content_written
				? $this->compensate( $post_id, $handle, $prepared, null )
				: 'cutover_conflict';
		}

		try {
			$this->observer->checkpoint( self::PHASE_AFTER_CUTOVER, $post_id );
			if ( ! $this->postwrite_is_valid( $post_id, $prepared, Migration_Deck_Mode_Store::NATIVE ) ) {
				return $this->compensate( $post_id, $handle, $prepared, $meta_id );
			}

			return $this->complete_applied_event( $post_id, $handle, $prepared, $meta_id );
		} catch ( Throwable ) {
			return $this->compensate( $post_id, $handle, $prepared, $meta_id );
		}
	}

	/**
	 * Persist and verify the final applied journal event.
	 *
	 * @param int                   $post_id        Slideshow post ID.
	 * @param Migration_Lock_Handle $handle         Current lock handle.
	 * @param array<string, mixed>  $prepared       Trusted prepared state.
	 * @param int|null              $created_meta_id Marker owned by this call.
	 * @return string Content-free result code.
	 */
	private function complete_applied_event( int $post_id, Migration_Lock_Handle &$handle, array $prepared, ?int $created_meta_id = null ): string {
		$renewed = $this->lock->renew( $handle );
		if ( null === $renewed ) {
			return null !== $created_meta_id
				? $this->compensate( $post_id, $handle, $prepared, $created_meta_id )
				: 'lock_lost_after_cutover';
		}
		$handle = $renewed;
		try {
			$ready_to_record = ! $this->has_active_edit_lock( $post_id )
				&& $this->postwrite_is_valid( $post_id, $prepared, Migration_Deck_Mode_Store::NATIVE );
		} catch ( Throwable ) {
			$ready_to_record = false;
		}
		if ( ! $ready_to_record ) {
			return null !== $created_meta_id
				? $this->compensate( $post_id, $handle, $prepared, $created_meta_id )
				: $this->record_recovery_required( $post_id, $prepared, $handle );
		}

		try {
			$this->observer->checkpoint( self::PHASE_BEFORE_APPLIED_EVENT, $post_id );
			$journal_status = $prepared['journal']->append(
				$post_id,
				$prepared['attemptId'],
				Migration_Journal::STATE_APPLIED,
				$prepared['context']
			);
		} catch ( Throwable ) {
			$current = $prepared['journal']->inspect( $post_id );
			if ( null !== $created_meta_id ) {
				return Migration_Journal::STATE_APPLIED === $current['state']
					? $this->compensate_applied( $post_id, $handle, $prepared, $created_meta_id )
					: $this->compensate( $post_id, $handle, $prepared, $created_meta_id );
			}

			return 'applied_event_failed';
		}

		try {
			$valid = $journal_status['valid']
				&& Migration_Journal::STATE_APPLIED === $journal_status['state']
				&& $this->postwrite_is_valid(
					$post_id,
					$prepared,
					Migration_Deck_Mode_Store::NATIVE,
					array( Migration_Journal::STATE_APPLIED )
				);
		} catch ( Throwable ) {
			$valid = false;
		}
		if ( ! $valid ) {
			if ( null !== $created_meta_id ) {
				return Migration_Journal::STATE_APPLIED === ( $journal_status['state'] ?? null )
					? $this->compensate_applied( $post_id, $handle, $prepared, $created_meta_id )
					: $this->compensate( $post_id, $handle, $prepared, $created_meta_id );
			}

			return $this->record_recovery_required( $post_id, $prepared, $handle );
		}

		return 'applied';
	}

	/**
	 * Restore legacy routing and original content after an owned partial write.
	 *
	 * @param int                   $post_id        Slideshow post ID.
	 * @param Migration_Lock_Handle $handle         Current lock handle.
	 * @param array<string, mixed>  $prepared       Trusted prepared state.
	 * @param int|null              $created_meta_id Marker owned by this call.
	 * @return string Content-free result code.
	 */
	private function compensate( int $post_id, Migration_Lock_Handle &$handle, array $prepared, ?int $created_meta_id ): string {
		try {
			if ( null !== $created_meta_id && ! $this->mode_store->remove_created( $post_id, $created_meta_id ) ) {
				return $this->record_recovery_required( $post_id, $prepared, $handle );
			}
		} catch ( Throwable ) {
			return $this->record_recovery_required( $post_id, $prepared, $handle );
		}
		if ( Migration_Deck_Mode_Store::ABSENT !== $this->mode_store->inspect( $post_id )['state'] ) {
			return $this->record_recovery_required( $post_id, $prepared, $handle );
		}

		$renewed = $this->lock->renew( $handle );
		if ( null === $renewed ) {
			return 'lock_lost_during_compensation';
		}
		$handle = $renewed;

		$backup                     = $prepared['backup'];
		$target_post                = $backup->post_fields();
		$target_post['postContent'] = $backup->target_content();
		try {
			$restored = $this->writer->compare_and_swap( $post_id, $target_post, $backup->original_content() );
		} catch ( Throwable ) {
			$restored = false;
		}
		if ( ! $restored ) {
			return $this->record_recovery_required( $post_id, $prepared, $handle );
		}
		if ( 'original_legacy' !== $this->representation_state( $post_id, $backup, $prepared['hasher'] ) ) {
			return $this->record_recovery_required( $post_id, $prepared, $handle );
		}

		try {
			$rolled_back = $prepared['journal']->append(
				$post_id,
				$prepared['attemptId'],
				Migration_Journal::STATE_APPLY_ROLLED_BACK,
				$prepared['context']
			);
		} catch ( Throwable ) {
			$current = $prepared['journal']->inspect( $post_id );

			return Migration_Journal::STATE_APPLY_PREPARED === $current['state']
				? $this->record_recovery_required( $post_id, $prepared, $handle )
				: 'rollback_event_failed';
		}

		return $rolled_back['valid'] && Migration_Journal::STATE_APPLY_ROLLED_BACK === $rolled_back['state']
			? 'apply_rolled_back'
			: $this->record_recovery_required( $post_id, $prepared, $handle );
	}

	/**
	 * Restore a safe legacy representation after the journal reached applied.
	 *
	 * The applied event cannot transition to rolled_back, so successful
	 * compensation is followed by terminal recovery_required for manual review.
	 *
	 * @param int                   $post_id        Slideshow post ID.
	 * @param Migration_Lock_Handle $handle         Current lock handle.
	 * @param array<string, mixed>  $prepared       Trusted prepared state.
	 * @param int                   $created_meta_id Marker owned by this call.
	 * @return string Content-free result code.
	 */
	private function compensate_applied( int $post_id, Migration_Lock_Handle &$handle, array $prepared, int $created_meta_id ): string {
		try {
			if ( ! $this->mode_store->remove_created( $post_id, $created_meta_id ) ) {
				return $this->record_recovery_required( $post_id, $prepared, $handle );
			}
		} catch ( Throwable ) {
			return $this->record_recovery_required( $post_id, $prepared, $handle );
		}

		$renewed = $this->lock->renew( $handle );
		if ( null === $renewed ) {
			return 'lock_lost_recovery_unrecorded';
		}
		$handle = $renewed;

		$backup                     = $prepared['backup'];
		$target_post                = $backup->post_fields();
		$target_post['postContent'] = $backup->target_content();
		try {
			$restored = $this->writer->compare_and_swap( $post_id, $target_post, $backup->original_content() );
		} catch ( Throwable ) {
			$restored = false;
		}

		if ( ! $restored || 'original_legacy' !== $this->representation_state( $post_id, $backup, $prepared['hasher'] ) ) {
			return $this->record_recovery_required( $post_id, $prepared, $handle );
		}

		return $this->record_recovery_required( $post_id, $prepared, $handle );
	}

	/**
	 * Load and independently validate every private prepared artifact.
	 *
	 * @param int                $post_id       Slideshow post ID.
	 * @param array<int, string> $allowed_states Accepted journal states.
	 * @return array<string, mixed>|null Trusted prepared state.
	 */
	private function load_prepared( int $post_id, array $allowed_states = array( Migration_Journal::STATE_APPLY_PREPARED ) ): ?array {
		$secret = $this->secret->read();
		if ( null === $secret ) {
			return null;
		}

		$hasher         = new Migration_Hasher( $secret );
		$journal        = new Migration_Journal( $hasher );
		$journal_status = $journal->inspect( $post_id );
		$context        = $journal->verified_context( $post_id );
		if (
			! $journal_status['valid']
			|| ! in_array( $journal_status['state'], $allowed_states, true )
			|| ! is_string( $journal_status['attemptId'] )
			|| ! is_array( $context )
			|| ! isset( $context['backupId'] )
			|| ! is_string( $context['backupId'] )
		) {
			return null;
		}

		$store   = new Migration_Backup_Store( $hasher );
		$payload = $store->read_verified_payload( $post_id, $context['backupId'] );
		if ( null === $payload ) {
			return null;
		}

		$backup = Migration_Prepared_Backup::from_verified( $post_id, $payload, $context, $hasher, $this->structure );
		if (
			null === $backup
			|| ! $this->revision->verify_hash( $post_id, $backup->revision_id(), $hasher, $backup->revision_fields_hash() )
		) {
			return null;
		}

		return array(
			'attemptId' => $journal_status['attemptId'],
			'backup'    => $backup,
			'context'   => $context,
			'hasher'    => $hasher,
			'journal'   => $journal,
		);
	}

	/**
	 * Recheck exact pre-write state after the observer and lock renewal.
	 *
	 * @param int                  $post_id  Slideshow post ID.
	 * @param array<string, mixed> $prepared Trusted prepared state.
	 * @return bool Whether the first mutation remains authorized.
	 */
	private function prewrite_is_current( int $post_id, array $prepared ): bool {
		if ( $this->has_active_edit_lock( $post_id ) ) {
			return false;
		}

		$reloaded = $this->load_prepared( $post_id );
		$current  = $this->builder->build( $post_id, $prepared['hasher'] );

		return null !== $reloaded
			&& null !== $current
			&& $current->matches_preparation( $prepared['context'] )
			&& 'original_legacy' === $this->representation_state( $post_id, $prepared['backup'], $prepared['hasher'] );
	}

	/**
	 * Verify content, source fields, retained metadata, artifacts, and marker state.
	 *
	 * @param int                  $post_id       Slideshow post ID.
	 * @param array<string, mixed> $prepared      Trusted prepared state.
	 * @param string               $mode_state    Expected marker state.
	 * @param array<int, string>   $journal_states Accepted journal states.
	 * @return bool Whether every post-write invariant holds.
	 */
	private function postwrite_is_valid(
		int $post_id,
		array $prepared,
		string $mode_state,
		array $journal_states = array( Migration_Journal::STATE_APPLY_PREPARED )
	): bool {
		$representation = Migration_Deck_Mode_Store::NATIVE === $mode_state ? 'target_native' : 'target_legacy';

		return $mode_state === $this->mode_store->inspect( $post_id )['state']
			&& $representation === $this->representation_state( $post_id, $prepared['backup'], $prepared['hasher'] )
			&& $this->structure->is_valid( $prepared['backup']->target_content() )
			&& $this->revision->verify_hash(
				$post_id,
				$prepared['backup']->revision_id(),
				$prepared['hasher'],
				$prepared['backup']->revision_fields_hash()
			)
			&& null !== $this->load_prepared( $post_id, $journal_states );
	}

	/**
	 * Classify exact authored representation without exposing its values.
	 *
	 * @param int                       $post_id Slideshow post ID.
	 * @param Migration_Prepared_Backup $backup  Trusted prepared backup.
	 * @param Migration_Hasher          $hasher  Persistent hasher.
	 * @return string Content-free representation state.
	 */
	private function representation_state( int $post_id, Migration_Prepared_Backup $backup, Migration_Hasher $hasher ): string {
		$post = get_post( $post_id );
		$meta = Legacy_Meta_Payload::capture( $post_id );
		$mode = $this->mode_store->inspect( $post_id )['state'];
		if ( ! $post instanceof WP_Post || null === $meta || ! $this->post_fields_match( $post, $backup->post_fields() ) ) {
			return 'source_changed';
		}
		if ( ! hash_equals( $backup->retained_hash(), $hasher->hash( 'retained-legacy', $meta->to_array() ) ) ) {
			return 'source_changed';
		}

		$content_hash = $hasher->hash( 'post-content', $post->post_content );
		$content      = hash_equals( $backup->original_content_hash(), $content_hash )
			? 'original'
			: ( hash_equals( $backup->target_content_hash(), $content_hash ) ? 'target' : 'modified' );

		if ( Migration_Deck_Mode_Store::INVALID === $mode || 'modified' === $content || ( 'original' === $content && Migration_Deck_Mode_Store::NATIVE === $mode ) ) {
			return 'interrupted_invalid';
		}

		return $content . '_' . ( Migration_Deck_Mode_Store::NATIVE === $mode ? 'native' : 'legacy' );
	}

	/**
	 * Compare every non-content prepared post field exactly.
	 *
	 * @param WP_Post              $post     Current post.
	 * @param array<string, mixed> $expected Prepared post fields.
	 * @return bool Whether non-content fields match.
	 */
	private function post_fields_match( WP_Post $post, array $expected ): bool {
		return $post->ID === $expected['id']
			&& $post->post_type === $expected['type']
			&& $post->post_name === $expected['name']
			&& $post->post_title === $expected['title']
			&& $post->post_excerpt === $expected['excerpt']
			&& $post->menu_order === $expected['menuOrder']
			&& $post->post_status === $expected['status']
			&& $post->post_password === $expected['password'];
	}

	/**
	 * Append terminal recovery state when compensation cannot be proven.
	 *
	 * @param int                   $post_id  Slideshow post ID.
	 * @param array<string, mixed>  $prepared Trusted prepared state.
	 * @param Migration_Lock_Handle $handle   Current lock handle.
	 * @return string Content-free result code.
	 */
	private function record_recovery_required( int $post_id, array $prepared, Migration_Lock_Handle &$handle ): string {
		$renewed = $this->lock->renew( $handle );
		if ( null === $renewed ) {
			return 'recovery_required_unrecorded';
		}
		$handle = $renewed;

		try {
			$status = $prepared['journal']->append(
				$post_id,
				$prepared['attemptId'],
				Migration_Journal::STATE_RECOVERY_REQUIRED,
				$prepared['context']
			);
		} catch ( Throwable ) {
			return 'recovery_event_failed';
		}

		return $status['valid'] && Migration_Journal::STATE_RECOVERY_REQUIRED === $status['state']
			? 'recovery_required'
			: 'recovery_event_failed';
	}

	/**
	 * Determine whether another editor currently owns the post lock.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return bool Whether a live edit lock exists.
	 */
	private function has_active_edit_lock( int $post_id ): bool {
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			$root = rtrim( (string) constant( 'ABSPATH' ), '/\\' );
			require_once $root . '/wp-admin/includes/post.php';
		}

		return false !== wp_check_post_lock( $post_id );
	}

	/**
	 * Convert status into a content-free apply response.
	 *
	 * @param array<string, mixed> $status Status envelope.
	 * @param string               $code   Result code.
	 * @return array<string, mixed> Apply envelope.
	 */
	private function as_result( array $status, string $code ): array {
		$status['mode']    = 'apply';
		$status['codes'][] = $code;
		sort( $status['codes'], SORT_STRING );
		$status['codes'] = array_values( array_unique( $status['codes'] ) );

		return $status;
	}
}

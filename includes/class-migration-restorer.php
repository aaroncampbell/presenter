<?php
/**
 * Explicit Presenter migration restore orchestration.
 *
 * @package Presenter
 */

namespace Presenter;

use Throwable;
use WP_Post;

/**
 * Restores verified legacy content and routing after an applied migration.
 */
final class Migration_Restorer {
	public const PHASE_BEFORE_RESTORE_PREPARED_EVENT = 'before_restore_prepared_event';
	public const PHASE_BEFORE_MARKER_REMOVAL         = 'before_marker_removal';
	public const PHASE_AFTER_MARKER_REMOVAL          = 'after_marker_removal';
	public const PHASE_BEFORE_CONTENT_RESTORE        = 'before_content_restore';
	public const PHASE_AFTER_CONTENT_RESTORE         = 'after_content_restore';
	public const PHASE_BEFORE_RESTORED_EVENT         = 'before_restored_event';

	/**
	 * Create the restore service.
	 *
	 * @param Migration_Secret              $secret     Signing-secret reader.
	 * @param Migration_Lock                $lock       Per-deck lock.
	 * @param Migration_Revision            $revision   Revision verifier.
	 * @param Migration_Status_Service      $status     Content-free status.
	 * @param Migration_Deck_Mode_Store     $mode_store Exact marker storage.
	 * @param Native_Deck_Structure         $structure  Native structure validator.
	 * @param Migration_Post_Content_Writer $writer     Atomic content writer.
	 * @param Migration_Restore_Observer    $observer   Phase observer.
	 */
	public function __construct(
		private Migration_Secret $secret,
		private Migration_Lock $lock,
		private Migration_Revision $revision,
		private Migration_Status_Service $status,
		private Migration_Deck_Mode_Store $mode_store,
		private Native_Deck_Structure $structure,
		private Migration_Post_Content_Writer $writer,
		private Migration_Restore_Observer $observer
	) {}

	/**
	 * Restore one explicitly applied slideshow.
	 *
	 * @param int  $post_id             Slideshow post ID.
	 * @param bool $discard_native_edits Whether to preserve, then explicitly discard, modified native content.
	 * @return array<string, mixed> Content-free restore status.
	 */
	public function restore( int $post_id, bool $discard_native_edits = false ): array {
		$preflight = $this->status->inspect( $post_id );
		if ( Migration_Journal::STATE_RESTORED === $preflight['journal']['state'] ) {
			$prepared = $this->load_prepared( $post_id, array( Migration_Journal::STATE_RESTORED ) );
			$healthy  = null !== $prepared && 'original_legacy' === $this->representation_state( $post_id, $prepared['backup'], $prepared['hasher'] );

			return $this->as_result( $preflight, $healthy ? 'already_restored' : 'restored_invalid' );
		}

		if ( ! in_array( $preflight['journal']['state'], array( Migration_Journal::STATE_APPLIED, Migration_Journal::STATE_RESTORE_PREPARED ), true ) ) {
			return $this->as_result( $preflight, 'not_restorable' );
		}

		$handle = $this->lock->acquire( $post_id );
		if ( null === $handle ) {
			return $this->as_result( $this->status->inspect( $post_id ), 'lock_unavailable' );
		}

		try {
			$code = $discard_native_edits
				? $this->restore_discarding_native_edits_locked( $post_id, $handle )
				: $this->restore_locked( $post_id, $handle );
		} catch ( Throwable ) {
			$code = 'restore_failed';
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
	 * Preserve a modified native representation, then restore the signed source.
	 *
	 * This path is deliberately separate from ordinary restore. It is only
	 * reachable after an explicit destructive confirmation at the CLI boundary.
	 *
	 * @param int                   $post_id Slideshow post ID.
	 * @param Migration_Lock_Handle $handle  Current lock owner.
	 * @return string Content-free result code.
	 */
	private function restore_discarding_native_edits_locked( int $post_id, Migration_Lock_Handle &$handle ): string {
		if ( $this->has_active_edit_lock( $post_id ) ) {
			return 'edit_lock_active';
		}

		$prepared = $this->load_prepared( $post_id, array( Migration_Journal::STATE_APPLIED ) );
		$current  = null === $prepared ? null : $this->modified_native_post( $post_id, $prepared );
		if ( null === $prepared || null === $current ) {
			return 'modified_native_representation_invalid';
		}

		$revision_id = $this->revision->ensure( $post_id );
		if ( null === $revision_id || ! $this->revision->verify( $post_id, $revision_id ) ) {
			return 'modified_native_revision_failed';
		}
		$revision_hash = $prepared['hasher']->hash(
			'revision-fields',
			array(
				'title'   => $current['title'],
				'content' => $current['postContent'],
				'excerpt' => $current['excerpt'],
			)
		);

		$renewed = $this->lock->renew( $handle );
		if ( null === $renewed ) {
			return 'lock_lost_before_recovery';
		}
		$handle = $renewed;
		if (
			$this->has_active_edit_lock( $post_id )
			|| ! $this->current_post_matches( $post_id, $current )
			|| ! $this->revision->verify_hash( $post_id, $revision_id, $prepared['hasher'], $revision_hash )
		) {
			return 'modified_native_precondition_changed';
		}

		try {
			$status = $prepared['journal']->append(
				$post_id,
				$prepared['attemptId'],
				Migration_Journal::STATE_RESTORE_PREPARED,
				$prepared['context']
			);
		} catch ( Throwable ) {
			return 'restore_prepare_event_failed';
		}
		if ( ! $status['valid'] || Migration_Journal::STATE_RESTORE_PREPARED !== $status['state'] ) {
			return 'restore_prepare_event_failed';
		}

		$prepared = $this->load_prepared( $post_id, array( Migration_Journal::STATE_RESTORE_PREPARED ) );
		if (
			null === $prepared
			|| $this->has_active_edit_lock( $post_id )
			|| ! $this->current_post_matches( $post_id, $current )
			|| ! $this->revision->verify_hash( $post_id, $revision_id, $prepared['hasher'], $revision_hash )
		) {
			return 'modified_native_precondition_changed';
		}

		$meta_id = $this->mode_store->find_native( $post_id );
		if ( null === $meta_id || ! $this->mode_store->remove_created( $post_id, $meta_id ) ) {
			return 'marker_removal_conflict';
		}

		$renewed = $this->lock->renew( $handle );
		if ( null === $renewed ) {
			return 'lock_lost_before_content_restore';
		}
		$handle = $renewed;
		if (
			$this->has_active_edit_lock( $post_id )
			|| Migration_Deck_Mode_Store::ABSENT !== $this->mode_store->inspect( $post_id )['state']
			|| ! $this->current_post_matches( $post_id, $current )
			|| ! $this->revision->verify_hash( $post_id, $revision_id, $prepared['hasher'], $revision_hash )
		) {
			return $this->record_recovery_required( $post_id, $handle, $prepared );
		}

		if ( ! $this->writer->compare_and_swap( $post_id, $current, $prepared['backup']->original_content() ) ) {
			return $this->record_recovery_required( $post_id, $handle, $prepared );
		}
		if (
			'original_legacy' !== $this->representation_state( $post_id, $prepared['backup'], $prepared['hasher'] )
			|| ! $this->revision->verify_hash( $post_id, $revision_id, $prepared['hasher'], $revision_hash )
		) {
			return $this->record_recovery_required( $post_id, $handle, $prepared );
		}

		return $this->complete_restored_event( $post_id, $handle, $prepared );
	}

	/**
	 * Execute or resume restore while one verified lock owner is active.
	 *
	 * @param int                   $post_id Slideshow post ID.
	 * @param Migration_Lock_Handle $handle  Current lock owner.
	 * @return string Content-free result code.
	 */
	private function restore_locked( int $post_id, Migration_Lock_Handle &$handle ): string {
		if ( $this->has_active_edit_lock( $post_id ) ) {
			return 'edit_lock_active';
		}

		$prepared = $this->load_prepared(
			$post_id,
			array( Migration_Journal::STATE_APPLIED, Migration_Journal::STATE_RESTORE_PREPARED )
		);
		if ( null === $prepared ) {
			return 'restore_artifacts_invalid';
		}

		$state = $this->representation_state( $post_id, $prepared['backup'], $prepared['hasher'] );
		if ( Migration_Journal::STATE_APPLIED === $prepared['journalState'] ) {
			if ( 'target_native' !== $state ) {
				return 'applied_representation_invalid';
			}

			$code = $this->prepare_restore( $post_id, $handle, $prepared );
			if ( 'restore_prepared' !== $code ) {
				return $code;
			}
			$prepared['journalState'] = Migration_Journal::STATE_RESTORE_PREPARED;
		}

		return $this->resume_restore( $post_id, $handle, $prepared );
	}

	/**
	 * Persist the restore intent before changing the live representation.
	 *
	 * @param int                   $post_id  Slideshow post ID.
	 * @param Migration_Lock_Handle $handle   Current lock owner.
	 * @param array<string, mixed>  $prepared Trusted prepared state.
	 * @return string Content-free result code.
	 */
	private function prepare_restore( int $post_id, Migration_Lock_Handle &$handle, array $prepared ): string {
		try {
			$this->observer->checkpoint( self::PHASE_BEFORE_RESTORE_PREPARED_EVENT, $post_id );
		} catch ( Throwable ) {
			return 'restore_prepare_failed';
		}

		$renewed = $this->lock->renew( $handle );
		if ( null === $renewed ) {
			return 'lock_lost_before_restore';
		}
		$handle = $renewed;

		$reloaded = $this->load_prepared( $post_id, array( Migration_Journal::STATE_APPLIED ) );
		if (
			null === $reloaded
			|| $this->has_active_edit_lock( $post_id )
			|| 'target_native' !== $this->representation_state( $post_id, $reloaded['backup'], $reloaded['hasher'] )
		) {
			return 'restore_precondition_changed';
		}

		try {
			$status = $prepared['journal']->append(
				$post_id,
				$prepared['attemptId'],
				Migration_Journal::STATE_RESTORE_PREPARED,
				$prepared['context']
			);
		} catch ( Throwable ) {
			$status = $prepared['journal']->inspect( $post_id );
		}

		return $status['valid'] && Migration_Journal::STATE_RESTORE_PREPARED === $status['state']
			? 'restore_prepared'
			: 'restore_prepare_event_failed';
	}

	/**
	 * Resume one of the three exact safe restore representations.
	 *
	 * @param int                   $post_id  Slideshow post ID.
	 * @param Migration_Lock_Handle $handle   Current lock owner.
	 * @param array<string, mixed>  $prepared Trusted prepared state.
	 * @return string Content-free result code.
	 */
	private function resume_restore( int $post_id, Migration_Lock_Handle &$handle, array $prepared ): string {
		$state = $this->representation_state( $post_id, $prepared['backup'], $prepared['hasher'] );
		if ( 'target_native' === $state ) {
			$code = $this->remove_marker( $post_id, $handle, $prepared );
			if ( 'marker_removed' !== $code ) {
				return $code;
			}
			$state = 'target_legacy';
		}

		if ( 'target_legacy' === $state ) {
			$code = $this->restore_content( $post_id, $handle, $prepared );
			if ( 'content_restored' !== $code ) {
				return $code;
			}
			$state = 'original_legacy';
		}

		if ( 'original_legacy' === $state ) {
			return $this->complete_restored_event( $post_id, $handle, $prepared );
		}

		return str_starts_with( $state, 'interrupted_' )
			? $this->record_recovery_required( $post_id, $handle, $prepared )
			: 'restore_source_changed';
	}

	/**
	 * Remove the exact singleton native routing marker.
	 *
	 * @param int                   $post_id  Slideshow post ID.
	 * @param Migration_Lock_Handle $handle   Current lock owner.
	 * @param array<string, mixed>  $prepared Trusted prepared state.
	 * @return string Content-free result code.
	 */
	private function remove_marker( int $post_id, Migration_Lock_Handle &$handle, array $prepared ): string {
		try {
			$this->observer->checkpoint( self::PHASE_BEFORE_MARKER_REMOVAL, $post_id );
		} catch ( Throwable ) {
			return 'restore_interrupted';
		}

		$renewed = $this->lock->renew( $handle );
		if ( null === $renewed ) {
			return 'lock_lost_before_marker_removal';
		}
		$handle = $renewed;
		if (
			$this->has_active_edit_lock( $post_id )
			|| null === $this->load_prepared( $post_id, array( Migration_Journal::STATE_RESTORE_PREPARED ) )
			|| 'target_native' !== $this->representation_state( $post_id, $prepared['backup'], $prepared['hasher'] )
		) {
			return 'restore_precondition_changed';
		}

		$meta_id = $this->mode_store->find_native( $post_id );
		if ( null === $meta_id || ! $this->mode_store->remove_created( $post_id, $meta_id ) ) {
			$state = $this->representation_state( $post_id, $prepared['backup'], $prepared['hasher'] );

			return 'target_native' === $state
				? 'marker_removal_conflict'
				: $this->record_recovery_required( $post_id, $handle, $prepared );
		}

		try {
			$this->observer->checkpoint( self::PHASE_AFTER_MARKER_REMOVAL, $post_id );
		} catch ( Throwable ) {
			return 'restore_interrupted';
		}

		return 'target_legacy' === $this->representation_state( $post_id, $prepared['backup'], $prepared['hasher'] )
			? 'marker_removed'
			: $this->record_recovery_required( $post_id, $handle, $prepared );
	}

	/**
	 * Restore original content with an inverse byte-exact compare-and-swap.
	 *
	 * @param int                   $post_id  Slideshow post ID.
	 * @param Migration_Lock_Handle $handle   Current lock owner.
	 * @param array<string, mixed>  $prepared Trusted prepared state.
	 * @return string Content-free result code.
	 */
	private function restore_content( int $post_id, Migration_Lock_Handle &$handle, array $prepared ): string {
		try {
			$this->observer->checkpoint( self::PHASE_BEFORE_CONTENT_RESTORE, $post_id );
		} catch ( Throwable ) {
			return 'restore_interrupted';
		}

		$renewed = $this->lock->renew( $handle );
		if ( null === $renewed ) {
			return 'lock_lost_before_content_restore';
		}
		$handle = $renewed;
		if (
			$this->has_active_edit_lock( $post_id )
			|| null === $this->load_prepared( $post_id, array( Migration_Journal::STATE_RESTORE_PREPARED ) )
			|| 'target_legacy' !== $this->representation_state( $post_id, $prepared['backup'], $prepared['hasher'] )
		) {
			return 'restore_precondition_changed';
		}

		$expected                = $prepared['backup']->post_fields();
		$expected['postContent'] = $prepared['backup']->target_content();
		try {
			$restored = $this->writer->compare_and_swap( $post_id, $expected, $prepared['backup']->original_content() );
		} catch ( Throwable ) {
			$restored = false;
		}

		$state = $this->representation_state( $post_id, $prepared['backup'], $prepared['hasher'] );
		if ( ! $restored && 'original_legacy' !== $state ) {
			return 'target_legacy' === $state
				? 'content_restore_conflict'
				: $this->record_recovery_required( $post_id, $handle, $prepared );
		}

		try {
			$this->observer->checkpoint( self::PHASE_AFTER_CONTENT_RESTORE, $post_id );
		} catch ( Throwable ) {
			return 'restore_interrupted';
		}

		return 'original_legacy' === $state ? 'content_restored' : $this->record_recovery_required( $post_id, $handle, $prepared );
	}

	/**
	 * Persist and verify the final restored journal event.
	 *
	 * @param int                   $post_id  Slideshow post ID.
	 * @param Migration_Lock_Handle $handle   Current lock owner.
	 * @param array<string, mixed>  $prepared Trusted prepared state.
	 * @return string Content-free result code.
	 */
	private function complete_restored_event( int $post_id, Migration_Lock_Handle &$handle, array $prepared ): string {
		$renewed = $this->lock->renew( $handle );
		if ( null === $renewed ) {
			return 'lock_lost_before_restored_event';
		}
		$handle = $renewed;
		if (
			$this->has_active_edit_lock( $post_id )
			|| null === $this->load_prepared( $post_id, array( Migration_Journal::STATE_RESTORE_PREPARED ) )
			|| 'original_legacy' !== $this->representation_state( $post_id, $prepared['backup'], $prepared['hasher'] )
		) {
			return 'restore_precondition_changed';
		}

		try {
			$this->observer->checkpoint( self::PHASE_BEFORE_RESTORED_EVENT, $post_id );
			$status = $prepared['journal']->append(
				$post_id,
				$prepared['attemptId'],
				Migration_Journal::STATE_RESTORED,
				$prepared['context']
			);
		} catch ( Throwable ) {
			$status = $prepared['journal']->inspect( $post_id );
		}

		if ( $status['valid'] && Migration_Journal::STATE_RESTORED === $status['state'] ) {
			$verified = $this->load_prepared( $post_id, array( Migration_Journal::STATE_RESTORED ) );

			return null !== $verified && 'original_legacy' === $this->representation_state( $post_id, $verified['backup'], $verified['hasher'] )
				? 'restored'
				: 'restored_verification_failed';
		}

		return 'restored_event_failed';
	}

	/**
	 * Load and independently validate every private prepared artifact.
	 *
	 * @param int                $post_id       Slideshow post ID.
	 * @param array<int, string> $allowed_states Accepted journal states.
	 * @return array<string, mixed>|null Trusted prepared state.
	 * @phpstan-impure Reads mutable WordPress persistence.
	 */
	private function load_prepared( int $post_id, array $allowed_states ): ?array {
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

		$payload = ( new Migration_Backup_Store( $hasher ) )->read_verified_payload( $post_id, $context['backupId'] );
		$backup  = is_array( $payload )
			? Migration_Prepared_Backup::from_verified( $post_id, $payload, $context, $hasher, $this->structure )
			: null;
		if (
			null === $backup
			|| ! $this->revision->verify_hash( $post_id, $backup->revision_id(), $hasher, $backup->revision_fields_hash() )
		) {
			return null;
		}

		return array(
			'attemptId'    => $journal_status['attemptId'],
			'backup'       => $backup,
			'context'      => $context,
			'hasher'       => $hasher,
			'journal'      => $journal,
			'journalState' => $journal_status['state'],
		);
	}

	/**
	 * Capture the exact modified native post authorized for destructive recovery.
	 *
	 * @param int                  $post_id  Slideshow post ID.
	 * @param array<string, mixed> $prepared Trusted prepared state.
	 * @return array<string, mixed>|null Exact current post fields, or null.
	 */
	private function modified_native_post( int $post_id, array $prepared ): ?array {
		$post = get_post( $post_id );
		$meta = Legacy_Meta_Payload::capture( $post_id );
		if (
			! $post instanceof WP_Post
			|| null === $meta
			|| Migration_Deck_Mode_Store::NATIVE !== $this->mode_store->inspect( $post_id )['state']
			|| ! $this->post_fields_match( $post, $prepared['backup']->post_fields() )
			|| ! hash_equals( $prepared['backup']->retained_hash(), $prepared['hasher']->hash( 'retained-legacy', $meta->to_array() ) )
		) {
			return null;
		}

		$content_hash = $prepared['hasher']->hash( 'post-content', $post->post_content );
		if (
			hash_equals( $prepared['backup']->original_content_hash(), $content_hash )
			|| hash_equals( $prepared['backup']->target_content_hash(), $content_hash )
		) {
			return null;
		}

		return array(
			'id'          => $post->ID,
			'type'        => $post->post_type,
			'name'        => $post->post_name,
			'title'       => $post->post_title,
			'excerpt'     => $post->post_excerpt,
			'menuOrder'   => $post->menu_order,
			'status'      => $post->post_status,
			'password'    => $post->post_password,
			'postContent' => $post->post_content,
		);
	}

	/**
	 * Recheck every exact post field against a captured recovery precondition.
	 *
	 * @param int                  $post_id Slideshow post ID.
	 * @param array<string, mixed> $expected Exact expected post fields.
	 * @return bool Whether the current post remains byte-for-byte authorized.
	 */
	private function current_post_matches( int $post_id, array $expected ): bool {
		$post = get_post( $post_id );

		return $post instanceof WP_Post
			&& $this->post_fields_match( $post, $expected )
			&& $post->post_content === $expected['postContent'];
	}

	/**
	 * Classify exact authored representation without exposing its values.
	 *
	 * @param int                       $post_id Slideshow post ID.
	 * @param Migration_Prepared_Backup $backup  Trusted prepared backup.
	 * @param Migration_Hasher          $hasher  Persistent hasher.
	 * @return string Content-free representation state.
	 * @phpstan-impure Reads mutable WordPress persistence.
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
	 * Append terminal recovery state when exact restore safety cannot be proven.
	 *
	 * @param int                   $post_id  Slideshow post ID.
	 * @param Migration_Lock_Handle $handle   Current lock owner.
	 * @param array<string, mixed>  $prepared Trusted prepared state.
	 * @return string Content-free result code.
	 */
	private function record_recovery_required( int $post_id, Migration_Lock_Handle &$handle, array $prepared ): string {
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
	 * @phpstan-impure Reads mutable WordPress persistence.
	 */
	private function has_active_edit_lock( int $post_id ): bool {
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			$root = rtrim( (string) constant( 'ABSPATH' ), '/\\' );
			require_once $root . '/wp-admin/includes/post.php';
		}

		return false !== wp_check_post_lock( $post_id );
	}

	/**
	 * Convert status into a content-free restore response.
	 *
	 * @param array<string, mixed> $status Status envelope.
	 * @param string               $code   Result code.
	 * @return array<string, mixed> Restore envelope.
	 */
	private function as_result( array $status, string $code ): array {
		$status['mode']    = 'restore';
		$status['codes'][] = $code;
		sort( $status['codes'], SORT_STRING );
		$status['codes'] = array_values( array_unique( $status['codes'] ) );

		return $status;
	}
}

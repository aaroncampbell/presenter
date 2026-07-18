<?php
/**
 * Explicit Presenter migration preparation orchestration.
 *
 * @package Presenter
 */

namespace Presenter;

use Throwable;

/**
 * Creates verified safety artifacts without changing slideshow content or mode.
 */
final class Migration_Preparer {
	/**
	 * Create the preparation service.
	 *
	 * @param Migration_Context_Builder $builder  Private preparation context builder.
	 * @param Migration_Secret          $secret   Persistent signing secret.
	 * @param Migration_Lock            $lock     Per-deck migration lock.
	 * @param Migration_Revision        $revision WordPress revision service.
	 * @param Migration_Status_Service  $status   Zero-write status service.
	 * @param Deck_Mode                 $deck_mode Authoritative storage-mode resolver.
	 */
	public function __construct(
		private Migration_Context_Builder $builder,
		private Migration_Secret $secret,
		private Migration_Lock $lock,
		private Migration_Revision $revision,
		private Migration_Status_Service $status,
		private Deck_Mode $deck_mode
	) {}

	/**
	 * Prepare one ready legacy deck without writing native content.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return array<string, mixed> Content-free preparation status.
	 */
	public function prepare( int $post_id ): array {
		$preflight = $this->status->inspect( $post_id );
		if ( Migration_Journal::STATE_APPLY_PREPARED === $preflight['journal']['state'] ) {
			return $this->as_prepare_result(
				$preflight,
				$preflight['capabilities']['canApply'] ? 'already_prepared' : 'prepared_invalid'
			);
		}

		if ( ! $preflight['capabilities']['canPrepare'] ) {
			return $this->as_prepare_result( $preflight, 'not_preparable' );
		}

		$handle = $this->lock->acquire( $post_id );
		if ( null === $handle ) {
			return $this->as_prepare_result( $this->status->inspect( $post_id ), 'lock_unavailable' );
		}

		$code = 'preparation_failed';
		try {
			$code = $this->prepare_locked( $post_id, $handle );
		} catch ( Throwable ) {
			$code = 'preparation_failed';
		}

		$released = $this->lock->release( $handle );
		$result   = $this->as_prepare_result( $this->status->inspect( $post_id ), $code );
		if ( ! $released ) {
			$result['codes'][] = 'lock_release_failed';
			sort( $result['codes'], SORT_STRING );
			$result['codes'] = array_values( array_unique( $result['codes'] ) );
		}

		return $result;
	}

	/**
	 * Execute preparation while one verified lock owner is active.
	 *
	 * @param int                   $post_id Slideshow post ID.
	 * @param Migration_Lock_Handle $handle  Current lock owner.
	 * @return string Content-free result code.
	 */
	private function prepare_locked( int $post_id, Migration_Lock_Handle &$handle ): string {
		if ( Deck_Mode::LEGACY !== $this->deck_mode->mode( $post_id ) ) {
			return 'deck_mode_not_legacy';
		}

		if ( $this->has_active_edit_lock( $post_id ) ) {
			return 'edit_lock_active';
		}

		$secret_value  = $this->secret->get_or_create();
		$hasher        = new Migration_Hasher( $secret_value );
		$journal       = new Migration_Journal( $hasher );
		$journal_state = $journal->inspect( $post_id );

		if ( ! $journal_state['valid'] || 'empty' !== $journal_state['code'] ) {
			return 'journal_not_empty';
		}

		$context = $this->builder->build( $post_id, $hasher );
		if ( null === $context ) {
			return 'source_not_ready';
		}

		$revision_id = $this->revision->ensure( $post_id );
		if ( null === $revision_id || ! $this->revision->verify( $post_id, $revision_id ) ) {
			return 'revision_failed';
		}

		$backup_reference = $hasher->hash(
			'backup-reference',
			array(
				'preparationReference' => $context->preparation_reference(),
				'revisionId'           => $revision_id,
			)
		);
		$backup_store     = new Migration_Backup_Store( $hasher );
		$backup           = $backup_store->find_verified_reference( $post_id, $backup_reference );
		$attempt_id       = wp_generate_uuid4();

		if ( null === $backup ) {
			$backup = $backup_store->create(
				$post_id,
				$context->backup_payload( $attempt_id, $revision_id, $backup_reference )
			);
		}

		if ( null === $backup || ! $backup_store->verify( $post_id, $backup['backupId'] ) ) {
			return 'backup_failed';
		}

		$renewed = $this->lock->renew( $handle );
		if ( null === $renewed ) {
			return 'lock_lost';
		}
		$handle = $renewed;

		$final_context = $this->builder->build( $post_id, $hasher );
		if (
			null === $final_context ||
			! hash_equals( $context->preparation_reference(), $final_context->preparation_reference() ) ||
			! $this->revision->verify( $post_id, $revision_id )
		) {
			return 'source_changed';
		}

		$stored_context                    = $final_context->journal_context( $backup['backupId'], $revision_id );
		$stored_context['backupReference'] = $backup_reference;
		$journal_status                    = $journal->append(
			$post_id,
			$attempt_id,
			Migration_Journal::STATE_APPLY_PREPARED,
			$stored_context
		);

		if (
			! $journal_status['valid'] ||
			Migration_Journal::STATE_APPLY_PREPARED !== $journal_status['state'] ||
			Migration_Value_Encoder::encode( $stored_context ) !== Migration_Value_Encoder::encode( $journal->verified_context( $post_id ) )
		) {
			return 'journal_failed';
		}

		return 'prepared';
	}

	/**
	 * Determine whether another editor currently owns the WordPress edit lock.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return bool Whether an active edit lock exists.
	 */
	private function has_active_edit_lock( int $post_id ): bool {
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			$wordpress_root = rtrim( (string) constant( 'ABSPATH' ), '/\\' );
			require_once $wordpress_root . '/wp-admin/includes/post.php';
		}

		return false !== wp_check_post_lock( $post_id );
	}

	/**
	 * Convert zero-write status into a preparation command response.
	 *
	 * @param array<string, mixed> $status Status envelope.
	 * @param string               $code   Preparation result code.
	 * @return array<string, mixed> Content-free preparation envelope.
	 */
	private function as_prepare_result( array $status, string $code ): array {
		$status['mode']    = 'prepare';
		$status['codes'][] = $code;
		sort( $status['codes'], SORT_STRING );
		$status['codes'] = array_values( array_unique( $status['codes'] ) );

		return $status;
	}
}

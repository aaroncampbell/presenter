<?php
/**
 * Migration status service integration tests.
 *
 * @package Presenter
 */

use Presenter\Deck_Mode;
use Presenter\Legacy_Deck_Snapshotter;
use Presenter\Legacy_Section_Validator;
use Presenter\Legacy_Slide_Attribute_Mapper;
use Presenter\Legacy_Slide_Normalizer;
use Presenter\Legacy_Theme_Resolver;
use Presenter\Migration_Backup_Store;
use Presenter\Migration_Context_Builder;
use Presenter\Migration_Deck_Mode_Store;
use Presenter\Migration_Hasher;
use Presenter\Migration_Journal;
use Presenter\Migration_Lock;
use Presenter\Migration_Planner;
use Presenter\Migration_Preparer;
use Presenter\Migration_Revision;
use Presenter\Migration_Secret;
use Presenter\Migration_Status_Service;
use Presenter\Slide_Attribute_Validator;
use Presenter\Speaker_Notes;
use Presenter\WordPress_Legacy_Slide_Source;

/**
 * Verify zero-write, content-free classification of real migration artifacts.
 */
final class Presenter_Migration_Status_Service_Test extends Presenter_Test_Case {
	/** Remove the persistent signing fixture after each test. */
	public function tear_down(): void {
		delete_option( Migration_Secret::OPTION_NAME );
		parent::tear_down();
	}

	/** A ready unprepared deck needs no secret or status-side writes. */
	public function test_unprepared_status_with_missing_secret_is_zero_write(): void {
		$post_id  = $this->create_ready_legacy_deck();
		$services = $this->services();
		$before   = $this->migration_artifact_counts( $post_id );

		$status = $services['status']->inspect( $post_id );

		$this->assert_status_schema_and_redaction( $status );
		$this->assertSame( $before, $this->migration_artifact_counts( $post_id ) );
		$this->assertNull( $services['secret']->read() );
		$this->assertSame( 'empty', $status['journal']['integrity'] );
		$this->assertSame( 'ready', $status['plan']['state'] );
		$this->assertTrue( $status['capabilities']['canPrepare'] );
		$this->assertFalse( $status['capabilities']['canApply'] );
		$this->assertSame( array( 'unprepared' ), $status['codes'] );
	}

	/** Real preparation produces a completely verified, applicable status. */
	public function test_prepared_status_is_verified_applicable_and_redacted(): void {
		$prepared = $this->prepare_ready_deck();
		$status   = $prepared['status'];

		$this->assert_status_schema_and_redaction( $status );
		$this->assertSame( 'legacy', $status['deckMode'] );
		$this->assertSame( Migration_Journal::STATE_APPLY_PREPARED, $status['journal']['state'] );
		$this->assertSame( 'verified', $status['journal']['integrity'] );
		$this->assertSame( 'match', $status['source']['precondition'] );
		$this->assertSame( 'match', $status['source']['retained'] );
		$this->assertSame( 'original', $status['content']['classification'] );
		$this->assertSame(
			array(
				'state'    => 'verified',
				'revision' => 'verified',
			),
			$status['backup']
		);
		$this->assertFalse( $status['capabilities']['canPrepare'] );
		$this->assertTrue( $status['capabilities']['canApply'] );
		$this->assertFalse( $status['capabilities']['canRestore'] );
		$this->assertSame( array(), $status['codes'] );
	}

	/** Target content without a marker identifies an interrupted pre-cutover apply. */
	public function test_prepared_target_without_marker_reports_interrupted_before_cutover(): void {
		$prepared = $this->prepare_ready_deck();
		$this->write_prepared_target( $prepared );
		$before = $this->migration_artifact_counts( $prepared['postId'] );

		$status = $prepared['services']['status']->inspect( $prepared['postId'] );

		$this->assertSame( $before, $this->migration_artifact_counts( $prepared['postId'] ) );
		$this->assertSame( Migration_Journal::STATE_APPLY_PREPARED, $status['journal']['state'] );
		$this->assertSame( 'target', $status['content']['classification'] );
		$this->assertSame( 'superseded', $status['source']['precondition'] );
		$this->assertSame( Deck_Mode::LEGACY, $status['deckMode'] );
		$this->assertSame( array( 'apply_interrupted_before_cutover' ), $status['codes'] );
		$this->assertFalse( $status['capabilities']['canApply'] );
		$this->assertFalse( $status['capabilities']['canRestore'] );
		$this->assert_status_schema_and_redaction( $status );
	}

	/** A native marker before the applied event identifies an interrupted cutover. */
	public function test_prepared_target_with_native_marker_reports_interrupted_after_cutover(): void {
		$prepared = $this->prepare_ready_deck();
		$this->write_prepared_target( $prepared );
		$this->assertIsInt( $prepared['services']['modeStore']->create_native( $prepared['postId'] ) );

		$status = $prepared['services']['status']->inspect( $prepared['postId'] );

		$this->assertSame( Migration_Journal::STATE_APPLY_PREPARED, $status['journal']['state'] );
		$this->assertSame( 'target', $status['content']['classification'] );
		$this->assertSame( 'superseded', $status['source']['precondition'] );
		$this->assertSame( Deck_Mode::NATIVE, $status['deckMode'] );
		$this->assertSame( array( 'apply_interrupted_after_cutover' ), $status['codes'] );
		$this->assertFalse( $status['capabilities']['canApply'] );
		$this->assertFalse( $status['capabilities']['canRestore'] );
		$this->assert_status_schema_and_redaction( $status );
	}

	/** Applied target content with the exact native marker is healthy and restorable. */
	public function test_applied_target_with_native_marker_is_healthy_and_restorable(): void {
		$prepared = $this->prepare_ready_deck();
		$this->write_prepared_target( $prepared );
		$this->assertIsInt( $prepared['services']['modeStore']->create_native( $prepared['postId'] ) );
		$this->append_applied_event( $prepared );

		$status = $prepared['services']['status']->inspect( $prepared['postId'] );

		$this->assertSame( Migration_Journal::STATE_APPLIED, $status['journal']['state'] );
		$this->assertSame( 'target', $status['content']['classification'] );
		$this->assertSame( 'superseded', $status['source']['precondition'] );
		$this->assertSame( Deck_Mode::NATIVE, $status['deckMode'] );
		$this->assertSame( array(), $status['codes'] );
		$this->assertFalse( $status['capabilities']['canApply'] );
		$this->assertTrue( $status['capabilities']['canRestore'] );
		$this->assertNotContains( 'deck_mode_not_legacy', $status['codes'] );
		$this->assertNotContains( 'precondition_changed', $status['codes'] );
		$this->assert_status_schema_and_redaction( $status );
	}

	/** A non-content post-field change blocks restore against stale prepared data. */
	public function test_applied_non_content_post_change_is_not_restorable(): void {
		$prepared = $this->prepare_applied_deck();
		wp_update_post(
			array(
				'ID'         => $prepared['postId'],
				'post_title' => 'private-changed-restore-title-sentinel',
			)
		);

		$status = $prepared['services']['status']->inspect( $prepared['postId'] );

		$this->assertSame( Migration_Journal::STATE_APPLIED, $status['journal']['state'] );
		$this->assertSame( 'target', $status['content']['classification'] );
		$this->assertFalse( $status['capabilities']['canRestore'] );
		$this->assertContains( 'post_fields_changed', $status['codes'] );
		$this->assertStringNotContainsString( 'private-changed-restore-title-sentinel', wp_json_encode( $status ) );
		$this->assert_status_schema_and_redaction( $status );
	}

	/** A recorded restore intent at the applied representation is resumable. */
	public function test_restore_prepared_target_with_native_marker_is_restorable(): void {
		$prepared = $this->prepare_applied_deck();
		$this->append_restore_event( $prepared, Migration_Journal::STATE_RESTORE_PREPARED );

		$status = $prepared['services']['status']->inspect( $prepared['postId'] );

		$this->assertSame( Migration_Journal::STATE_RESTORE_PREPARED, $status['journal']['state'] );
		$this->assertSame( 'target', $status['content']['classification'] );
		$this->assertSame( Deck_Mode::NATIVE, $status['deckMode'] );
		$this->assertTrue( $status['capabilities']['canRestore'] );
		$this->assertSame( array(), $status['codes'] );
		$this->assert_status_schema_and_redaction( $status );
	}

	/** Restore can resume after its owned native cutover marker was removed. */
	public function test_restore_prepared_target_without_marker_is_restorable(): void {
		$prepared = $this->prepare_applied_deck();
		$this->append_restore_event( $prepared, Migration_Journal::STATE_RESTORE_PREPARED );
		delete_post_meta( $prepared['postId'], Deck_Mode::META_KEY );

		$status = $prepared['services']['status']->inspect( $prepared['postId'] );

		$this->assertSame( Migration_Journal::STATE_RESTORE_PREPARED, $status['journal']['state'] );
		$this->assertSame( 'target', $status['content']['classification'] );
		$this->assertSame( Deck_Mode::LEGACY, $status['deckMode'] );
		$this->assertTrue( $status['capabilities']['canRestore'] );
		$this->assertSame( array( 'restore_interrupted_after_cutover_removal' ), $status['codes'] );
		$this->assert_status_schema_and_redaction( $status );
	}

	/** Restore can resume after both the cutover and original content were restored. */
	public function test_restore_prepared_original_without_marker_is_restorable(): void {
		$prepared = $this->prepare_applied_deck();
		$this->append_restore_event( $prepared, Migration_Journal::STATE_RESTORE_PREPARED );
		delete_post_meta( $prepared['postId'], Deck_Mode::META_KEY );
		wp_update_post(
			array(
				'ID'           => $prepared['postId'],
				'post_content' => '',
			)
		);

		$status = $prepared['services']['status']->inspect( $prepared['postId'] );

		$this->assertSame( 'original', $status['content']['classification'] );
		$this->assertSame( Deck_Mode::LEGACY, $status['deckMode'] );
		$this->assertTrue( $status['capabilities']['canRestore'] );
		$this->assertSame( array( 'restore_interrupted_after_content' ), $status['codes'] );
		$this->assert_status_schema_and_redaction( $status );
	}

	/** Malformed cutover storage during restore fails closed without leaking it. */
	public function test_restore_prepared_with_malformed_marker_is_not_restorable(): void {
		$prepared = $this->prepare_applied_deck();
		$this->append_restore_event( $prepared, Migration_Journal::STATE_RESTORE_PREPARED );
		add_post_meta( $prepared['postId'], Deck_Mode::META_KEY, 'private-restore-mode-sentinel' );

		$status = $prepared['services']['status']->inspect( $prepared['postId'] );

		$this->assertFalse( $status['capabilities']['canRestore'] );
		$this->assertContains( 'restore_cutover_invalid', $status['codes'] );
		$this->assertStringNotContainsString( 'private-restore-mode-sentinel', wp_json_encode( $status ) );
		$this->assert_status_schema_and_redaction( $status );
	}

	/** Authored content outside both verified representations blocks restore. */
	public function test_restore_prepared_with_modified_content_is_not_restorable(): void {
		$prepared = $this->prepare_applied_deck();
		$this->append_restore_event( $prepared, Migration_Journal::STATE_RESTORE_PREPARED );
		wp_update_post(
			array(
				'ID'           => $prepared['postId'],
				'post_content' => '<p>private-restore-content-sentinel</p>',
			)
		);

		$status = $prepared['services']['status']->inspect( $prepared['postId'] );

		$this->assertSame( 'modified', $status['content']['classification'] );
		$this->assertFalse( $status['capabilities']['canRestore'] );
		$this->assertContains( 'content_modified', $status['codes'] );
		$this->assertStringNotContainsString( 'private-restore-content-sentinel', wp_json_encode( $status ) );
		$this->assert_status_schema_and_redaction( $status );
	}

	/** A completed restore is healthy and permits a fresh preparation attempt. */
	public function test_restored_original_without_marker_can_prepare_new_attempt(): void {
		$prepared = $this->prepare_applied_deck();
		$this->append_restore_event( $prepared, Migration_Journal::STATE_RESTORE_PREPARED );
		delete_post_meta( $prepared['postId'], Deck_Mode::META_KEY );
		wp_update_post(
			array(
				'ID'           => $prepared['postId'],
				'post_content' => '',
			)
		);
		$this->append_restore_event( $prepared, Migration_Journal::STATE_RESTORED );

		$status = $prepared['services']['status']->inspect( $prepared['postId'] );

		$this->assertSame( Migration_Journal::STATE_RESTORED, $status['journal']['state'] );
		$this->assertSame( 'original', $status['content']['classification'] );
		$this->assertSame( Deck_Mode::LEGACY, $status['deckMode'] );
		$this->assertTrue( $status['capabilities']['canPrepare'] );
		$this->assertFalse( $status['capabilities']['canRestore'] );
		$this->assertSame( array(), $status['codes'] );
		$this->assert_status_schema_and_redaction( $status );
	}

	/** A restored deck prepares a fresh attempt from its current valid source. */
	public function test_restored_source_change_can_prepare_fresh_attempt(): void {
		$prepared = $this->prepare_applied_deck();
		$this->append_restore_event( $prepared, Migration_Journal::STATE_RESTORE_PREPARED );
		delete_post_meta( $prepared['postId'], Deck_Mode::META_KEY );
		wp_update_post(
			array(
				'ID'           => $prepared['postId'],
				'post_content' => '',
			)
		);
		$this->append_restore_event( $prepared, Migration_Journal::STATE_RESTORED );
		add_post_meta( $prepared['postId'], '_presenter-short-url', 'private-restored-source-sentinel' );

		$status = $prepared['services']['status']->inspect( $prepared['postId'] );

		$this->assertTrue( $status['capabilities']['canPrepare'] );
		$this->assertContains( 'precondition_changed', $status['codes'] );
		$this->assertContains( 'retained_changed', $status['codes'] );
		$this->assertStringNotContainsString( 'private-restored-source-sentinel', wp_json_encode( $status ) );
		$this->assert_status_schema_and_redaction( $status );
	}

	/**
	 * Applied content without one exact native marker fails closed.
	 *
	 * @dataProvider invalid_applied_markers
	 *
	 * @param string $marker Fixture marker state.
	 */
	public function test_applied_target_with_invalid_marker_is_not_restorable( string $marker ): void {
		$prepared = $this->prepare_ready_deck();
		$this->write_prepared_target( $prepared );
		if ( 'malformed' === $marker ) {
			add_post_meta( $prepared['postId'], Deck_Mode::META_KEY, 'private-invalid-mode-sentinel' );
		}
		$this->append_applied_event( $prepared );

		$status = $prepared['services']['status']->inspect( $prepared['postId'] );

		$this->assertSame( Migration_Journal::STATE_APPLIED, $status['journal']['state'] );
		$this->assertSame( 'target', $status['content']['classification'] );
		$this->assertFalse( $status['capabilities']['canApply'] );
		$this->assertFalse( $status['capabilities']['canRestore'] );
		$this->assertContains( 'missing' === $marker ? 'applied_cutover_missing' : 'applied_cutover_invalid', $status['codes'] );
		$this->assertStringNotContainsString( 'private-invalid-mode-sentinel', wp_json_encode( $status ) );
		$this->assert_status_schema_and_redaction( $status );
	}

	/** Provide invalid marker states after a nominal applied event. */
	public function invalid_applied_markers(): array {
		return array(
			'missing marker'   => array( 'missing' ),
			'malformed marker' => array( 'malformed' ),
		);
	}

	/** A native cutover marker disables preparation and apply capabilities. */
	public function test_native_mode_disables_prepare_and_apply(): void {
		$prepared = $this->prepare_ready_deck();
		add_post_meta( $prepared['postId'], Deck_Mode::META_KEY, Deck_Mode::NATIVE );

		$status = $prepared['services']['status']->inspect( $prepared['postId'] );

		$this->assertSame( Deck_Mode::NATIVE, $status['deckMode'] );
		$this->assertFalse( $status['capabilities']['canPrepare'] );
		$this->assertFalse( $status['capabilities']['canApply'] );
		$this->assertContains( 'deck_mode_not_legacy', $status['codes'] );
		$this->assert_status_schema_and_redaction( $status );
	}

	/** Retained metadata changes invalidate both source comparisons. */
	public function test_source_meta_change_disables_apply(): void {
		$prepared = $this->prepare_ready_deck();
		add_post_meta( $prepared['postId'], '_presenter-short-url', 'https://example.test/changed-source' );

		$status = $prepared['services']['status']->inspect( $prepared['postId'] );

		$this->assertSame( 'changed', $status['source']['precondition'] );
		$this->assertSame( 'changed', $status['source']['retained'] );
		$this->assertSame( 'original', $status['content']['classification'] );
		$this->assertFalse( $status['capabilities']['canApply'] );
		$this->assertContains( 'precondition_changed', $status['codes'] );
		$this->assertContains( 'retained_changed', $status['codes'] );
		$this->assert_status_schema_and_redaction( $status );
	}

	/** Post content outside the prepared original and target is classified modified. */
	public function test_content_change_disables_apply(): void {
		$prepared = $this->prepare_ready_deck();
		wp_update_post(
			array(
				'ID'           => $prepared['postId'],
				'post_content' => '<p>private-modified-content-sentinel</p>',
			)
		);

		$status = $prepared['services']['status']->inspect( $prepared['postId'] );

		$this->assertSame( 'modified', $status['content']['classification'] );
		$this->assertFalse( $status['capabilities']['canApply'] );
		$this->assertContains( 'content_modified', $status['codes'] );
		$this->assertStringNotContainsString( 'private-modified-content-sentinel', wp_json_encode( $status ) );
		$this->assert_status_schema_and_redaction( $status );
	}

	/** A pruned preparation revision is reported without exposing its ID. */
	public function test_pruned_revision_disables_apply(): void {
		$prepared  = $this->prepare_ready_deck();
		$revisions = wp_get_post_revisions( $prepared['postId'] );
		$this->assertNotEmpty( $revisions );
		$revision = reset( $revisions );
		$this->assertInstanceOf( WP_Post::class, $revision );
		wp_delete_post( $revision->ID, true );

		$status = $prepared['services']['status']->inspect( $prepared['postId'] );

		$this->assertSame( 'missing', $status['backup']['revision'] );
		$this->assertFalse( $status['capabilities']['canApply'] );
		$this->assertContains( 'revision_missing', $status['codes'] );
		$this->assert_status_schema_and_redaction( $status );
	}

	/**
	 * Tampered and duplicate backup envelopes are both invalid.
	 *
	 * @dataProvider backup_corruptions
	 *
	 * @param string $corruption Backup corruption mode.
	 */
	public function test_invalid_backup_disables_apply( string $corruption ): void {
		$prepared = $this->prepare_ready_deck();
		$record   = get_post_meta( $prepared['postId'], Migration_Backup_Store::META_KEY, true );
		$this->assertIsArray( $record );

		if ( 'tampered' === $corruption ) {
			$tampered                             = $record;
			$tampered['payload']['post']['title'] = 'private-tampered-backup-sentinel';
			update_post_meta( $prepared['postId'], Migration_Backup_Store::META_KEY, $tampered, $record );
		} else {
			add_post_meta( $prepared['postId'], Migration_Backup_Store::META_KEY, $record );
		}

		$status = $prepared['services']['status']->inspect( $prepared['postId'] );

		$this->assertSame( 'invalid', $status['backup']['state'] );
		$this->assertFalse( $status['capabilities']['canApply'] );
		$this->assertContains( 'backup_invalid', $status['codes'] );
		$this->assertStringNotContainsString( 'private-tampered-backup-sentinel', wp_json_encode( $status ) );
		$this->assert_status_schema_and_redaction( $status );
	}

	/** Provide immutable-backup corruption modes. */
	public function backup_corruptions(): array {
		return array(
			'tampered envelope'  => array( 'tampered' ),
			'duplicate envelope' => array( 'duplicate' ),
		);
	}

	/** A changed journal event fails integrity without exposing private context. */
	public function test_invalid_journal_is_content_free(): void {
		$prepared = $this->prepare_ready_deck();
		$record   = get_post_meta( $prepared['postId'], Migration_Journal::META_KEY, true );
		$this->assertIsArray( $record );
		$tampered                                = $record;
		$tampered['context']['preconditionHash'] = str_repeat( '0', 64 );
		update_post_meta( $prepared['postId'], Migration_Journal::META_KEY, $tampered, $record );

		$status = $prepared['services']['status']->inspect( $prepared['postId'] );

		$this->assertSame( 'invalid', $status['journal']['integrity'] );
		$this->assertFalse( $status['capabilities']['canApply'] );
		$this->assertSame( array( 'journal_invalid' ), $status['codes'] );
		$this->assertStringNotContainsString( str_repeat( '0', 64 ), wp_json_encode( $status ) );
		$this->assert_status_schema_and_redaction( $status );
	}

	/** A validly signed event with an incomplete context fails closed. */
	public function test_invalid_verified_context_is_content_free(): void {
		$post_id  = $this->create_ready_legacy_deck();
		$services = $this->services();
		$secret   = $services['secret']->get_or_create();
		$journal  = new Migration_Journal( new Migration_Hasher( $secret ) );
		$journal->append(
			$post_id,
			wp_generate_uuid4(),
			Migration_Journal::STATE_APPLY_PREPARED,
			array( 'privateContext' => 'private-invalid-context-sentinel' )
		);

		$status = $services['status']->inspect( $post_id );

		$this->assertSame( 'verified', $status['journal']['integrity'] );
		$this->assertSame( array( 'context_invalid' ), $status['codes'] );
		$this->assertFalse( $status['capabilities']['canApply'] );
		$this->assertStringNotContainsString( 'private-invalid-context-sentinel', wp_json_encode( $status ) );
		$this->assert_status_schema_and_redaction( $status );
	}

	/** Artifacts without a valid signing secret are unavailable and not preparable. */
	public function test_invalid_secret_with_artifacts_fails_closed(): void {
		$post_id  = $this->create_ready_legacy_deck();
		$services = $this->services();
		update_option( Migration_Secret::OPTION_NAME, 'invalid-secret-sentinel', false );
		add_post_meta(
			$post_id,
			Migration_Journal::META_KEY,
			array( 'private' => 'private-orphan-artifact-sentinel' )
		);

		$status = $services['status']->inspect( $post_id );

		$this->assertSame( 'unavailable', $status['journal']['integrity'] );
		$this->assertSame( 'secret_missing', $status['journal']['code'] );
		$this->assertSame( array( 'secret_missing' ), $status['codes'] );
		$this->assertFalse( $status['capabilities']['canPrepare'] );
		$this->assertFalse( $status['capabilities']['canApply'] );
		$this->assertStringNotContainsString( 'invalid-secret-sentinel', wp_json_encode( $status ) );
		$this->assertStringNotContainsString( 'private-orphan-artifact-sentinel', wp_json_encode( $status ) );
		$this->assert_status_schema_and_redaction( $status );
	}

	/** Active locks block apply while expired locks are available but not reclaimed. */
	public function test_active_and_expired_lock_classification(): void {
		$now      = 1_700_000_000;
		$clock    = static function () use ( &$now ): int {
			return $now;
		};
		$prepared = $this->prepare_ready_deck( $clock );
		$lock     = $prepared['services']['lock'];
		$handle   = $lock->acquire( $prepared['postId'] );
		$this->assertNotNull( $handle );

		$active = $prepared['services']['status']->inspect( $prepared['postId'] );
		$this->assertSame( 'active', $active['lock']['state'] );
		$this->assertFalse( $active['capabilities']['canApply'] );
		$this->assertContains( 'lock_unavailable', $active['codes'] );

		$now     = $handle->expires_at();
		$expired = $prepared['services']['status']->inspect( $prepared['postId'] );
		$this->assertSame(
			array(
				'state'            => 'expired',
				'secondsRemaining' => 0,
			),
			$expired['lock']
		);
		$this->assertTrue( $expired['capabilities']['canApply'] );
		$this->assertNotContains( 'lock_unavailable', $expired['codes'] );
		$this->assertNotNull( get_option( $this->lock_option_name( $prepared['postId'] ), null ), 'Status must not reclaim an expired lock.' );
		$this->assertTrue( $lock->release( $handle ) );
	}

	/**
	 * Create and fully prepare a real ready legacy deck.
	 *
	 * @param callable(): int|null $clock Optional mutable clock.
	 * @return array<string, mixed> Prepared fixture services and status.
	 */
	private function prepare_ready_deck( ?callable $clock = null ): array {
		$post_id  = $this->create_ready_legacy_deck();
		$services = $this->services( $clock );
		$result   = $services['preparer']->prepare( $post_id );

		$this->assertContains( 'prepared', $result['codes'] );

		return array(
			'postId'   => $post_id,
			'services' => $services,
			'status'   => $services['status']->inspect( $post_id ),
		);
	}

	/**
	 * Build real migration services around the WordPress legacy source.
	 *
	 * @param callable(): int|null $clock Optional mutable clock.
	 * @return array<string, mixed> Migration services.
	 */
	private function services( ?callable $clock = null ): array {
		$source           = new WordPress_Legacy_Slide_Source();
		$snapshotter      = new Legacy_Deck_Snapshotter( $source );
		$themes           = new class() implements Legacy_Theme_Resolver {
			/**
			 * Resolve no explicit theme in the empty-theme fixture.
			 *
			 * @param string $legacy_theme Stored legacy theme.
			 * @return string|null Stable theme ID.
			 */
			public function resolve_legacy_theme_id( string $legacy_theme ): ?string {
				unset( $legacy_theme );
				return null;
			}
		};
		$slide_attributes = new Slide_Attribute_Validator();
		$planner          = new Migration_Planner(
			new Legacy_Slide_Normalizer(),
			new Legacy_Slide_Attribute_Mapper( $slide_attributes ),
			new Legacy_Section_Validator(),
			new Speaker_Notes(),
			$themes
		);
		$secret           = new Migration_Secret();
		$lock             = new Migration_Lock( $clock );
		$revision         = new Migration_Revision();
		$deck_mode        = new Deck_Mode( $source );
		$mode_store       = new Migration_Deck_Mode_Store();
		$status           = new Migration_Status_Service(
			$snapshotter,
			$planner,
			$secret,
			$lock,
			$revision,
			$deck_mode,
			$mode_store
		);
		$builder          = new Migration_Context_Builder( $snapshotter, $planner, $mode_store );

		return array(
			'builder'   => $builder,
			'secret'    => $secret,
			'lock'      => $lock,
			'modeStore' => $mode_store,
			'status'    => $status,
			'preparer'  => new Migration_Preparer( $builder, $secret, $lock, $revision, $status, $deck_mode, $mode_store ),
		);
	}

	/**
	 * Store the exact prepared target without changing the journal or mode marker.
	 *
	 * @param array<string, mixed> $prepared Prepared fixture and services.
	 */
	private function write_prepared_target( array $prepared ): void {
		$secret = $prepared['services']['secret']->read();
		$this->assertIsString( $secret );
		$context = $prepared['services']['builder']->build(
			$prepared['postId'],
			new Migration_Hasher( $secret )
		);
		$this->assertNotNull( $context );

		$result = wp_update_post(
			array(
				'ID'           => $prepared['postId'],
				'post_content' => $context->target_content(),
			),
			true
		);

		$this->assertSame( $prepared['postId'], $result );
	}

	/**
	 * Create one fully applied deck suitable for restore-state fixtures.
	 *
	 * @return array<string, mixed> Applied fixture and services.
	 */
	private function prepare_applied_deck(): array {
		$prepared = $this->prepare_ready_deck();
		$this->write_prepared_target( $prepared );
		$this->assertIsInt( $prepared['services']['modeStore']->create_native( $prepared['postId'] ) );
		$this->append_applied_event( $prepared );

		return $prepared;
	}

	/**
	 * Append a restore transition while retaining the verified attempt context.
	 *
	 * @param array<string, mixed> $prepared Applied fixture and services.
	 * @param string               $state    Restore journal state.
	 */
	private function append_restore_event( array $prepared, string $state ): void {
		$secret = $prepared['services']['secret']->read();
		$this->assertIsString( $secret );
		$journal = new Migration_Journal( new Migration_Hasher( $secret ) );
		$current = $journal->inspect( $prepared['postId'] );
		$context = $journal->verified_context( $prepared['postId'] );
		$this->assertIsString( $current['attemptId'] );
		$this->assertIsArray( $context );

		$restored = $journal->append(
			$prepared['postId'],
			$current['attemptId'],
			$state,
			$context
		);

		$this->assertTrue( $restored['valid'] );
		$this->assertSame( $state, $restored['state'] );
	}

	/**
	 * Advance the prepared attempt while retaining its complete verified context.
	 *
	 * @param array<string, mixed> $prepared Prepared fixture and services.
	 */
	private function append_applied_event( array $prepared ): void {
		$secret = $prepared['services']['secret']->read();
		$this->assertIsString( $secret );
		$journal = new Migration_Journal( new Migration_Hasher( $secret ) );
		$current = $journal->inspect( $prepared['postId'] );
		$context = $journal->verified_context( $prepared['postId'] );
		$this->assertIsString( $current['attemptId'] );
		$this->assertIsArray( $context );

		$applied = $journal->append(
			$prepared['postId'],
			$current['attemptId'],
			Migration_Journal::STATE_APPLIED,
			$context
		);

		$this->assertTrue( $applied['valid'] );
		$this->assertSame( Migration_Journal::STATE_APPLIED, $applied['state'] );
	}

	/** Create one ready legacy deck containing private authored sentinels. */
	private function create_ready_legacy_deck(): int {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'private-status-title-sentinel',
				'post_content' => '',
				'post_excerpt' => 'private-status-excerpt-sentinel',
			)
		);
		add_post_meta(
			$post_id,
			'_presenter_slides',
			array(
				'number'  => 1,
				'title'   => 'private-slide-title-sentinel',
				'content' => '<h2>private-slide-content-sentinel</h2>',
				'data'    => array(),
				'notes'   => array(
					'notes'    => 'private-notes-sentinel',
					'markdown' => false,
				),
			)
		);

		return $post_id;
	}

	/**
	 * Count migration artifacts whose absence proves zero-write status.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return array<string, int|bool> Artifact counts.
	 */
	private function migration_artifact_counts( int $post_id ): array {
		return array(
			'backup'    => count( get_post_meta( $post_id, Migration_Backup_Store::META_KEY, false ) ),
			'journal'   => count( get_post_meta( $post_id, Migration_Journal::META_KEY, false ) ),
			'revisions' => count( wp_get_post_revisions( $post_id ) ),
			'secret'    => null !== get_option( Migration_Secret::OPTION_NAME, null ),
		);
	}

	/**
	 * Assert the exact public schema and recursively reject private fields.
	 *
	 * @param array<string, mixed> $status Public migration status.
	 */
	private function assert_status_schema_and_redaction( array $status ): void {
		$this->assertSame(
			array( 'schemaVersion', 'mode', 'postId', 'deckMode', 'journal', 'lock', 'source', 'content', 'backup', 'plan', 'capabilities', 'codes' ),
			array_keys( $status )
		);
		$this->assertSame( array( 'state', 'attemptId', 'sequence', 'integrity', 'code' ), array_keys( $status['journal'] ) );
		$this->assertSame( array( 'state', 'secondsRemaining' ), array_keys( $status['lock'] ) );
		$this->assertSame( array( 'precondition', 'retained' ), array_keys( $status['source'] ) );
		$this->assertSame( array( 'classification' ), array_keys( $status['content'] ) );
		$this->assertSame( array( 'state', 'revision' ), array_keys( $status['backup'] ) );
		$this->assertSame( array( 'state', 'plannerVersion' ), array_keys( $status['plan'] ) );
		$this->assertSame( array( 'canPrepare', 'canApply', 'canRestore' ), array_keys( $status['capabilities'] ) );

		$this->assert_redacted_keys( $status );
		$json = wp_json_encode( $status );
		foreach ( array( 'private-status-title-sentinel', 'private-status-excerpt-sentinel', 'private-slide-title-sentinel', 'private-slide-content-sentinel', 'private-notes-sentinel' ) as $sentinel ) {
			$this->assertStringNotContainsString( $sentinel, $json );
		}
	}

	/**
	 * Recursively reject private identifiers and integrity/storage references.
	 *
	 * @param array<array-key, mixed> $values Status subtree.
	 */
	private function assert_redacted_keys( array $values ): void {
		foreach ( $values as $key => $value ) {
			if ( is_string( $key ) && ! in_array( $key, array( 'postId', 'attemptId' ), true ) ) {
				$this->assertDoesNotMatchRegularExpression( '/(?:hash|reference|token|optionName|backupId|revisionId|eventId)$/i', $key );
			}
			if ( is_array( $value ) ) {
				$this->assert_redacted_keys( $value );
			}
		}
	}

	/**
	 * Derive the private option key only for proving read-only expiry behavior.
	 *
	 * @param int $post_id Slideshow post ID.
	 */
	private function lock_option_name( int $post_id ): string {
		return 'presenter_migration_lock_' . $post_id;
	}
}

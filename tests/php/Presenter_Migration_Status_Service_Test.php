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
			'secret'   => $secret,
			'lock'     => $lock,
			'status'   => $status,
			'preparer' => new Migration_Preparer( $builder, $secret, $lock, $revision, $status, $deck_mode, $mode_store ),
		);
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

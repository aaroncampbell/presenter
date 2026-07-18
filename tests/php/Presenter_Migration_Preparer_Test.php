<?php
/**
 * Presenter migration preparation integration tests.
 *
 * @package Presenter
 */

use Presenter\Deck_Mode;
use Presenter\Legacy_Deck_Snapshotter;
use Presenter\Legacy_Section_Validator;
use Presenter\Legacy_Slide_Attribute_Mapper;
use Presenter\Legacy_Slide_Normalizer;
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
use Presenter\Migration_Value_Encoder;
use Presenter\Slide_Attribute_Validator;
use Presenter\Speaker_Notes;
use Presenter\WordPress_Legacy_Slide_Source;

/**
 * Verify prepare creates only immutable safety artifacts around unchanged source.
 */
final class Presenter_Migration_Preparer_Test extends Presenter_Test_Case {
	/** Private append-only backup metadata key. */
	private const BACKUP_META_KEY = '_presenter_migration_backup_v1';

	/** Remove global migration state before each isolated integration test. */
	public function set_up(): void {
		parent::set_up();

		delete_option( Migration_Secret::OPTION_NAME );
		wp_set_current_user( 0 );
	}

	/** Restore global migration state after each test. */
	public function tear_down(): void {
		delete_option( Migration_Secret::OPTION_NAME );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/** Successful preparation preserves source and creates verified safety artifacts. */
	public function test_successful_prepare_is_safe_verified_and_content_free(): void {
		$post_id   = $this->create_ready_deck();
		$source    = $this->authored_state( $post_id );
		$services  = $this->services();
		$result    = $services['preparer']->prepare( $post_id );
		$backups   = get_post_meta( $post_id, self::BACKUP_META_KEY, false );
		$events    = get_post_meta( $post_id, Migration_Journal::META_KEY, false );
		$revisions = $this->normal_revision_ids( $post_id );

		$this->assertContains( 'prepared', $result['codes'] );
		$this->assertSame( Migration_Journal::STATE_APPLY_PREPARED, $result['journal']['state'] );
		$this->assertTrue( $result['capabilities']['canApply'] );
		$this->assertSame( Deck_Mode::LEGACY, $result['deckMode'] );
		$this->assert_authored_unchanged( $source, $post_id );
		$this->assertCount( 1, $revisions );
		$this->assertTrue( $services['revision']->verify( $post_id, $revisions[0] ) );
		$this->assertCount( 1, $backups );
		$this->assertCount( 1, $events );
		$this->assertSame( Migration_Journal::STATE_APPLY_PREPARED, $events[0]['toState'] );
		$this->assertNotNull( $services['secret']->read() );
		$this->assertSame( 'unlocked', $services['lock']->inspect( $post_id )['state'] );

		$hasher         = new Migration_Hasher( $services['secret']->read() );
		$backup_store   = new Migration_Backup_Store( $hasher );
		$backup_payload = $backup_store->read_verified_payload( $post_id, $backups[0]['backupId'] );
		$this->assertIsArray( $backup_payload );
		$this->assertArrayNotHasKey( 'attemptId', $backup_payload );
		$this->assertArrayHasKey( 'attemptId', $events[0] );
		$this->assertTrue(
			$backup_store->verify( $post_id, $backups[0]['backupId'] )
		);
		$this->assert_safe_result( $result, $source, $backups[0], $events[0] );
	}

	/** An exact retry reuses every preparation artifact without appending state. */
	public function test_exact_rerun_is_idempotent(): void {
		$post_id  = $this->create_ready_deck();
		$services = $this->services();
		$first    = $services['preparer']->prepare( $post_id );
		$before   = $this->artifact_counts( $post_id );
		$second   = $services['preparer']->prepare( $post_id );

		$this->assertContains( 'prepared', $first['codes'] );
		$this->assertContains( 'already_prepared', $second['codes'] );
		$this->assertSame( $before, $this->artifact_counts( $post_id ) );
		$this->assertSame( Migration_Journal::STATE_APPLY_PREPARED, $second['journal']['state'] );
	}

	/** A safely rolled-back attempt can prepare again under a new attempt ID. */
	public function test_rolled_back_attempt_can_prepare_again(): void {
		$post_id  = $this->create_ready_deck();
		$services = $this->services();
		$first    = $services['preparer']->prepare( $post_id );
		$secret   = $services['secret']->read();
		$this->assertIsString( $secret );
		$journal = new Migration_Journal( new Migration_Hasher( $secret ) );
		$context = $journal->verified_context( $post_id );

		$this->assertIsArray( $context );
		$journal->append(
			$post_id,
			$first['journal']['attemptId'],
			Migration_Journal::STATE_APPLY_ROLLED_BACK,
			$context
		);
		$this->assertTrue( $services['status']->inspect( $post_id )['capabilities']['canPrepare'] );
		$before = $this->artifact_counts( $post_id );

		$second = $services['preparer']->prepare( $post_id );

		$this->assertContains( 'prepared', $second['codes'] );
		$this->assertSame( Migration_Journal::STATE_APPLY_PREPARED, $second['journal']['state'] );
		$this->assertNotSame( $first['journal']['attemptId'], $second['journal']['attemptId'] );
		$this->assertSame( $before['revisions'], $this->artifact_counts( $post_id )['revisions'] );
		$this->assertSame( $before['backups'], $this->artifact_counts( $post_id )['backups'] );
		$this->assertSame( $before['events'] + 1, $this->artifact_counts( $post_id )['events'] );
	}

	/** A verified orphan backup is reused when preparation resumes before journaling. */
	public function test_verified_orphan_backup_is_reused(): void {
		$post_id  = $this->create_ready_deck();
		$services = $this->services();
		$secret   = $services['secret']->get_or_create();
		$hasher   = new Migration_Hasher( $secret );
		$context  = $services['builder']->build( $post_id, $hasher );
		$revision = $services['revision']->ensure( $post_id );

		$this->assertNotNull( $context );
		$this->assertIsInt( $revision );
		$reference    = $hasher->hash(
			'backup-reference',
			array(
				'preparationReference' => $context->preparation_reference(),
				'revisionId'           => $revision,
			)
		);
		$backup_store = new Migration_Backup_Store( $hasher );
		$orphan       = $backup_store->create(
			$post_id,
			$context->backup_payload( $revision, $reference )
		);

		$this->assertIsArray( $orphan );
		$this->assertSame( 0, count( get_post_meta( $post_id, Migration_Journal::META_KEY, false ) ) );

		$result = $services['preparer']->prepare( $post_id );

		$this->assertContains( 'prepared', $result['codes'] );
		$this->assertCount( 1, get_post_meta( $post_id, self::BACKUP_META_KEY, false ) );
		$this->assertCount( 1, get_post_meta( $post_id, Migration_Journal::META_KEY, false ) );
		$this->assertCount( 1, $this->normal_revision_ids( $post_id ) );
	}

	/**
	 * Blocked and ineligible sources create no preparation artifacts.
	 *
	 * @dataProvider non_preparable_decks
	 *
	 * @param string $fixture Fixture kind.
	 */
	public function test_non_preparable_source_creates_no_artifacts_or_content_writes( string $fixture ): void {
		$post_id = 'blocked' === $fixture
			? $this->create_ready_deck( '<p>Existing post content blocks migration</p>' )
			: $this->create_slideshow_without_legacy_editor_post_data( array( 'post_type' => 'slideshow' ) );
		$source  = $this->authored_state( $post_id );
		$result  = $this->services()['preparer']->prepare( $post_id );

		$this->assertContains( 'not_preparable', $result['codes'] );
		$this->assert_authored_unchanged( $source, $post_id );
		$this->assertSame(
			array(
				'revisions' => 0,
				'backups'   => 0,
				'events'    => 0,
			),
			$this->artifact_counts( $post_id )
		);
		$this->assertNull( ( new Migration_Secret() )->read() );
	}

	/** A native cutover marker prevents preparation even with retained legacy data. */
	public function test_native_mode_deck_creates_no_preparation_artifacts(): void {
		$post_id = $this->create_ready_deck();
		add_post_meta( $post_id, Deck_Mode::META_KEY, Deck_Mode::NATIVE );
		$source = $this->authored_state( $post_id );

		$result = $this->services()['preparer']->prepare( $post_id );

		$this->assertContains( 'not_preparable', $result['codes'] );
		$this->assertContains( 'deck_mode_not_legacy', $result['codes'] );
		$this->assertFalse( $result['capabilities']['canPrepare'] );
		$this->assertFalse( $result['capabilities']['canApply'] );
		$this->assert_authored_unchanged( $source, $post_id );
		$this->assertSame(
			array(
				'revisions' => 0,
				'backups'   => 0,
				'events'    => 0,
			),
			$this->artifact_counts( $post_id )
		);
		$this->assertNull( ( new Migration_Secret() )->read() );
	}

	/** Malformed mode rows cannot pass preparation through legacy fallback routing. */
	public function test_malformed_mode_storage_creates_no_preparation_artifacts(): void {
		$post_id = $this->create_ready_deck();
		add_post_meta( $post_id, Deck_Mode::META_KEY, 'unexpected' );
		$source = $this->authored_state( $post_id );

		$result = $this->services()['preparer']->prepare( $post_id );

		$this->assertSame( Deck_Mode::LEGACY, $result['deckMode'] );
		$this->assertContains( 'not_preparable', $result['codes'] );
		$this->assertContains( 'deck_mode_storage_invalid', $result['codes'] );
		$this->assertFalse( $result['capabilities']['canPrepare'] );
		$this->assertFalse( $result['capabilities']['canApply'] );
		$this->assert_authored_unchanged( $source, $post_id );
		$this->assertSame(
			array(
				'revisions' => 0,
				'backups'   => 0,
				'events'    => 0,
			),
			$this->artifact_counts( $post_id )
		);
		$this->assertNull( ( new Migration_Secret() )->read() );
	}

	/** Provide blocked and ineligible preflight fixtures. */
	public function non_preparable_decks(): array {
		return array(
			'blocked plan'       => array( 'blocked' ),
			'ineligible storage' => array( 'ineligible' ),
		);
	}

	/** A live WordPress edit lock prevents every preparation write. */
	public function test_active_edit_lock_creates_no_preparation_artifacts(): void {
		$post_id = $this->create_ready_deck();
		$owner   = self::factory()->user->create();
		update_post_meta( $post_id, '_edit_lock', time() . ':' . $owner );
		$source = $this->authored_state( $post_id );

		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		$this->assertSame( $owner, wp_check_post_lock( $post_id ) );

		$result = $this->services()['preparer']->prepare( $post_id );

		$this->assertContains( 'edit_lock_active', $result['codes'] );
		$this->assert_authored_unchanged( $source, $post_id );
		$this->assertSame(
			array(
				'revisions' => 0,
				'backups'   => 0,
				'events'    => 0,
			),
			$this->artifact_counts( $post_id )
		);
		$this->assertNull( ( new Migration_Secret() )->read() );
	}

	/** A competing migration lock fails closed without creating artifacts. */
	public function test_migration_lock_conflict_fails_closed(): void {
		$post_id  = $this->create_ready_deck();
		$services = $this->services();
		$handle   = $services['lock']->acquire( $post_id );
		$source   = $this->authored_state( $post_id );

		$this->assertNotNull( $handle );
		$result = $services['preparer']->prepare( $post_id );

		$this->assertContains( 'not_preparable', $result['codes'] );
		$this->assertSame( 'active', $result['lock']['state'] );
		$this->assert_authored_unchanged( $source, $post_id );
		$this->assertSame(
			array(
				'revisions' => 0,
				'backups'   => 0,
				'events'    => 0,
			),
			$this->artifact_counts( $post_id )
		);
		$this->assertNull( $services['secret']->read() );
		$this->assertTrue( $services['lock']->release( $handle ) );
	}

	/** A malformed existing journal fails closed without adding artifacts. */
	public function test_journal_conflict_fails_closed(): void {
		$post_id  = $this->create_ready_deck();
		$services = $this->services();
		$hasher   = new Migration_Hasher( $services['secret']->get_or_create() );
		$journal  = new Migration_Journal( $hasher );
		$journal->append( $post_id, wp_generate_uuid4(), Migration_Journal::STATE_APPLY_PREPARED, array() );
		$event              = get_post_meta( $post_id, Migration_Journal::META_KEY, true );
		$event['eventHash'] = str_repeat( '0', 64 );
		update_post_meta( $post_id, Migration_Journal::META_KEY, $event );
		$before = $this->artifact_counts( $post_id );
		$source = $this->authored_state( $post_id );

		$result = $services['preparer']->prepare( $post_id );

		$this->assertContains( 'not_preparable', $result['codes'] );
		$this->assertContains( 'journal_invalid', $result['codes'] );
		$this->assertSame( $before, $this->artifact_counts( $post_id ) );
		$this->assert_authored_unchanged( $source, $post_id );
	}

	/** Build the real preparation service graph used by WordPress. */
	private function services(): array {
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
		$builder     = new Migration_Context_Builder( $snapshotter, $planner, $mode_store );
		$status      = new Migration_Status_Service( $snapshotter, $planner, $secret, $lock, $revision, $deck_mode, $mode_store );
		$preparer    = new Migration_Preparer( $builder, $secret, $lock, $revision, $status, $deck_mode, $mode_store );

		return compact( 'builder', 'lock', 'preparer', 'revision', 'secret', 'status' );
	}

	/**
	 * Create a losslessly plannable legacy deck.
	 *
	 * @param string $post_content Existing post content.
	 */
	private function create_ready_deck( string $post_content = '' ): int {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'    => 'slideshow',
				'post_status'  => 'publish',
				'post_title'   => 'Private migration title sentinel',
				'post_excerpt' => 'Private migration excerpt sentinel',
				'post_content' => $post_content,
			)
		);
		add_post_meta(
			$post_id,
			'_presenter_slides',
			(object) array(
				'number'  => 1,
				'title'   => 'Private slide title sentinel',
				'content' => '<p>Private slide content sentinel</p>',
				'class'   => '',
			)
		);

		return $post_id;
	}

	/**
	 * Capture authored post fields and compatibility metadata only.
	 *
	 * @param int $post_id Slideshow post ID.
	 */
	private function authored_state( int $post_id ): array {
		$post = get_post( $post_id );

		return array(
			'title'    => $post->post_title,
			'content'  => $post->post_content,
			'excerpt'  => $post->post_excerpt,
			'deckMode' => get_post_meta( $post_id, Deck_Mode::META_KEY, false ),
			'slides'   => get_post_meta( $post_id, '_presenter_slides', false ),
			'theme'    => get_post_meta( $post_id, '_presenter-theme', false ),
			'shortUrl' => get_post_meta( $post_id, '_presenter-short-url', false ),
		);
	}

	/**
	 * Count immutable preparation artifacts.
	 *
	 * @param int $post_id Slideshow post ID.
	 */
	private function artifact_counts( int $post_id ): array {
		return array(
			'revisions' => count( $this->normal_revision_ids( $post_id ) ),
			'backups'   => count( get_post_meta( $post_id, self::BACKUP_META_KEY, false ) ),
			'events'    => count( get_post_meta( $post_id, Migration_Journal::META_KEY, false ) ),
		);
	}

	/**
	 * Read durable revision IDs without autosaves.
	 *
	 * @param int $post_id Slideshow post ID.
	 */
	private function normal_revision_ids( int $post_id ): array {
		return array_values(
			array_map(
				static fn( WP_Post $revision ): int => $revision->ID,
				array_filter(
					wp_get_post_revisions( $post_id ),
					static fn( WP_Post $revision ): bool => false === wp_is_post_autosave( $revision )
				)
			)
		);
	}

	/**
	 * Assert a public result cannot expose private source or artifact references.
	 *
	 * @param array<string, mixed> $result Public preparation result.
	 * @param array<string, mixed> $source Authored source snapshot.
	 * @param array<string, mixed> $backup Private backup envelope.
	 * @param array<string, mixed> $event  Private journal event.
	 */
	private function assert_safe_result( array $result, array $source, array $backup, array $event ): void {
		$json = wp_json_encode( $result );
		$this->assertIsString( $json );
		foreach ( array( $source['title'], $source['excerpt'], 'Private slide title sentinel', 'Private slide content sentinel' ) as $authored ) {
			$this->assertStringNotContainsString( $authored, $json );
		}
		$this->assertStringNotContainsString( $backup['backupId'], $json );
		$this->assertStringNotContainsString( $event['eventHash'], $json );
		foreach ( $event['context'] as $key => $value ) {
			if ( is_string( $value ) && ( str_ends_with( $key, 'Hash' ) || str_ends_with( $key, 'Reference' ) ) ) {
				$this->assertStringNotContainsString( $value, $json );
			}
		}

		$this->assert_forbidden_result_keys( $result );
	}

	/**
	 * Recursively reject private field names from a public result.
	 *
	 * @param array<string, mixed> $value Public result branch.
	 */
	private function assert_forbidden_result_keys( array $value ): void {
		$forbidden = array( 'backupId', 'revisionId', 'eventHash', 'envelopeHash', 'token', 'payload', 'context' );
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$this->assertNotContains( $key, $forbidden );
			}
			if ( is_array( $item ) ) {
				$this->assert_forbidden_result_keys( $item );
			}
		}
	}

	/**
	 * Assert authored values remain exactly equivalent across metadata reads.
	 *
	 * @param array<string, mixed> $expected Expected authored state.
	 * @param int                  $post_id  Slideshow post ID.
	 */
	private function assert_authored_unchanged( array $expected, int $post_id ): void {
		$this->assertSame(
			Migration_Value_Encoder::encode( $expected ),
			Migration_Value_Encoder::encode( $this->authored_state( $post_id ) )
		);
	}
}

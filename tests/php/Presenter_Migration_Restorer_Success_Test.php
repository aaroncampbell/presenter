<?php
/**
 * Successful and resumable migration restore integration tests.
 *
 * @package Presenter
 */

use Presenter\Atomic_Migration_Post_Content_Writer;
use Presenter\Deck_Mode;
use Presenter\Legacy_Deck_Snapshotter;
use Presenter\Legacy_Meta_Payload;
use Presenter\Legacy_Section_Validator;
use Presenter\Legacy_Slide_Attribute_Mapper;
use Presenter\Legacy_Slide_Normalizer;
use Presenter\Migration_Applier;
use Presenter\Migration_Backup_Store;
use Presenter\Migration_Context_Builder;
use Presenter\Migration_Deck_Mode_Store;
use Presenter\Migration_Hasher;
use Presenter\Migration_Journal;
use Presenter\Migration_Lock;
use Presenter\Migration_Planner;
use Presenter\Migration_Preparer;
use Presenter\Migration_Restorer;
use Presenter\Migration_Restore_Observer;
use Presenter\Migration_Revision;
use Presenter\Migration_Secret;
use Presenter\Migration_Status_Service;
use Presenter\Migration_Value_Encoder;
use Presenter\Native_Deck_Structure;
use Presenter\Null_Migration_Apply_Observer;
use Presenter\Null_Migration_Restore_Observer;
use Presenter\Slide_Attribute_Validator;
use Presenter\Speaker_Notes;
use Presenter\WordPress_Legacy_Slide_Source;

require_once __DIR__ . '/support/class-presenter-test-migration-restore-observer.php';

/**
 * Verify restore reaches and resumes the exact healthy legacy representation.
 */
final class Presenter_Migration_Restorer_Success_Test extends Presenter_Test_Case {
	/** Reset the persistent site-level signing secret around each test. */
	public function set_up(): void {
		parent::set_up();

		delete_option( Migration_Secret::OPTION_NAME );
		wp_set_current_user( 0 );
	}

	/** Restore site-level state after each test. */
	public function tear_down(): void {
		delete_option( Migration_Secret::OPTION_NAME );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/** An applied deck restores exactly once and a rerun performs no writes. */
	public function test_applied_deck_restores_exactly_and_rerun_is_idempotent(): void {
		$fixture = $this->applied_fixture();

		$result = $fixture['services']['restorer']->restore( $fixture['postId'] );

		$this->assertContains( 'restored', $result['codes'], (string) wp_json_encode( $result ) );
		$this->assert_healthy_restored( $fixture );
		$footprint = $this->artifact_footprint( $fixture['postId'] );

		$rerun = $fixture['services']['restorer']->restore( $fixture['postId'] );

		$this->assertContains( 'already_restored', $rerun['codes'], (string) wp_json_encode( $rerun ) );
		$this->assertSame(
			Migration_Value_Encoder::encode( $footprint ),
			Migration_Value_Encoder::encode( $this->artifact_footprint( $fixture['postId'] ) )
		);
		$this->assert_healthy_restored( $fixture );
	}

	/** Explicit recovery preserves modified native content before restoring source. */
	public function test_confirmed_recovery_preserves_modified_native_revision(): void {
		$fixture          = $this->applied_fixture();
		$modified_content = get_post_field( 'post_content', $fixture['postId'] ) . "\n<!-- wp:paragraph --><p>Modified after migration</p><!-- /wp:paragraph -->";
		wp_update_post(
			array(
				'ID'           => $fixture['postId'],
				'post_content' => $modified_content,
			)
		);

		$ordinary = $fixture['services']['restorer']->restore( $fixture['postId'] );
		$this->assertContains( 'applied_representation_invalid', $ordinary['codes'] );
		$fixture['appliedArtifacts'] = $this->preserved_migration_artifacts( $fixture['postId'] );

		$result = $fixture['services']['restorer']->restore( $fixture['postId'], true );

		$this->assertContains( 'restored', $result['codes'], (string) wp_json_encode( $result ) );
		$this->assert_healthy_restored( $fixture );

		$preserved = false;
		foreach ( wp_get_post_revisions( $fixture['postId'] ) as $revision ) {
			if ( $modified_content === $revision->post_content ) {
				$preserved = true;
				break;
			}
		}
		$this->assertTrue( $preserved, 'Modified native content must remain recoverable through revisions.' );
	}

	/**
	 * Restore resumes each exact representation that can remain after a crash.
	 *
	 * @dataProvider resumable_restore_phases
	 *
	 * @param string $phase            Observer phase that simulates the crash.
	 * @param string $expected_content Content classification after interruption.
	 * @param string $expected_mode    Exact mode-storage state after interruption.
	 */
	public function test_restore_resumes_every_verified_interrupted_representation( string $phase, string $expected_content, string $expected_mode ): void {
		$observer = new Presenter_Test_Migration_Restore_Observer(
			static function ( string $current_phase ) use ( $phase ): void {
				if ( $phase === $current_phase ) {
					throw new RuntimeException( 'private-restore-crash-sentinel' );
				}
			}
		);
		$fixture  = $this->applied_fixture( $observer );

		$interrupted = $fixture['services']['restorer']->restore( $fixture['postId'] );

		$this->assertContains( 'restore_interrupted', $interrupted['codes'], (string) wp_json_encode( $interrupted ) );
		$this->assertSame( Migration_Journal::STATE_RESTORE_PREPARED, $this->journal( $fixture )->inspect( $fixture['postId'] )['state'] );
		$this->assertSame( $expected_content, $fixture['services']['status']->inspect( $fixture['postId'] )['content']['classification'] );
		$this->assertSame( $expected_mode, $fixture['services']['mode_store']->inspect( $fixture['postId'] )['state'] );

		$resumed = $this->restorer( $fixture['services'], new Null_Migration_Restore_Observer() )->restore( $fixture['postId'] );

		$this->assertContains( 'restored', $resumed['codes'], (string) wp_json_encode( $resumed ) );
		$this->assert_healthy_restored( $fixture );
	}

	/** Provide the three durable restore-prepared crash representations. */
	public function resumable_restore_phases(): array {
		return array(
			'target with native marker'   => array(
				Migration_Restorer::PHASE_BEFORE_MARKER_REMOVAL,
				'target',
				Migration_Deck_Mode_Store::NATIVE,
			),
			'target with absent marker'   => array(
				Migration_Restorer::PHASE_AFTER_MARKER_REMOVAL,
				'target',
				Migration_Deck_Mode_Store::ABSENT,
			),
			'original with absent marker' => array(
				Migration_Restorer::PHASE_AFTER_CONTENT_RESTORE,
				'original',
				Migration_Deck_Mode_Store::ABSENT,
			),
		);
	}

	/**
	 * Prepare and apply one exact legacy fixture with production services.
	 *
	 * @param Migration_Restore_Observer|null $restore_observer Optional restore observer.
	 * @return array<string, mixed> Applied fixture and trusted artifacts.
	 */
	private function applied_fixture( ?Migration_Restore_Observer $restore_observer = null ): array {
		$post_id  = $this->create_ready_deck();
		$before   = $this->preserved_state( $post_id );
		$services = $this->services( $restore_observer );
		$this->assertContains( 'prepared', $services['preparer']->prepare( $post_id )['codes'] );
		$prepared = $this->prepared_artifacts( $post_id, $services );
		$this->assertContains( 'applied', $services['applier']->apply( $post_id )['codes'] );
		$applied_artifacts = $this->preserved_migration_artifacts( $post_id );

		return array(
			'appliedArtifacts' => $applied_artifacts,
			'before'           => $before,
			'postId'           => $post_id,
			'prepared'         => $prepared,
			'services'         => $services,
		);
	}

	/**
	 * Build the real preparation, apply, and restore service graph.
	 *
	 * @param Migration_Restore_Observer|null $restore_observer Optional restore observer.
	 * @return array<string, mixed> Production service graph.
	 */
	private function services( ?Migration_Restore_Observer $restore_observer = null ): array {
		$legacy               = new WordPress_Legacy_Slide_Source();
		$snapshotter          = new Legacy_Deck_Snapshotter( $legacy );
		$planner              = new Migration_Planner(
			new Legacy_Slide_Normalizer(),
			new Legacy_Slide_Attribute_Mapper( new Slide_Attribute_Validator() ),
			new Legacy_Section_Validator(),
			new Speaker_Notes(),
			presenter_get_runtime()->themes()
		);
		$secret               = new Migration_Secret();
		$lock                 = new Migration_Lock();
		$revision             = new Migration_Revision();
		$deck_mode            = new Deck_Mode( $legacy );
		$mode_store           = new Migration_Deck_Mode_Store();
		$structure            = new Native_Deck_Structure();
		$builder              = new Migration_Context_Builder( $snapshotter, $planner, $mode_store );
		$status               = new Migration_Status_Service( $snapshotter, $planner, $secret, $lock, $revision, $deck_mode, $mode_store );
		$preparer             = new Migration_Preparer( $builder, $secret, $lock, $revision, $status, $deck_mode, $mode_store );
		$writer               = new Atomic_Migration_Post_Content_Writer();
		$applier              = new Migration_Applier(
			$builder,
			$secret,
			$lock,
			$revision,
			$status,
			$mode_store,
			$structure,
			$writer,
			new Null_Migration_Apply_Observer()
		);
		$services             = compact( 'applier', 'lock', 'mode_store', 'preparer', 'revision', 'secret', 'status', 'structure', 'writer' );
		$services['restorer'] = $this->restorer( $services, $restore_observer ?? new Null_Migration_Restore_Observer() );

		return $services;
	}

	/**
	 * Build a restorer around an injected observer and an existing service graph.
	 *
	 * @param array<string, mixed>       $services Existing production service graph.
	 * @param Migration_Restore_Observer $observer Injected restore observer.
	 */
	private function restorer( array $services, Migration_Restore_Observer $observer ): Migration_Restorer {
		return new Migration_Restorer(
			$services['secret'],
			$services['lock'],
			$services['revision'],
			$services['status'],
			$services['mode_store'],
			$services['structure'],
			$services['writer'],
			$observer
		);
	}

	/** Create a losslessly plannable deck with exact content and retained metadata. */
	private function create_ready_deck(): int {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'     => 'slideshow',
				'post_name'     => 'restore-success-sentinel',
				'post_status'   => 'publish',
				'post_title'    => 'Restore title sentinel',
				'post_excerpt'  => 'Restore excerpt sentinel',
				'post_content'  => '',
				'post_password' => 'restore-password',
				'menu_order'    => 23,
			)
		);
		add_post_meta(
			$post_id,
			'_presenter_slides',
			(object) array(
				'number'  => 1,
				'title'   => 'Restore slide title sentinel',
				'content' => '<p>Restore slide content sentinel</p>',
				'class'   => '',
			)
		);
		add_post_meta( $post_id, '_presenter-theme', '/plugins/presenter/reveal.js/css/theme/black.css' );
		add_post_meta( $post_id, '_presenter-short-url', 'restore-short-url' );

		return $post_id;
	}

	/**
	 * Read the trusted prepared payload and journal context for assertions.
	 *
	 * @param int                  $post_id  Slideshow post ID.
	 * @param array<string, mixed> $services Production service graph.
	 * @return array<string, mixed> Trusted prepared artifacts.
	 */
	private function prepared_artifacts( int $post_id, array $services ): array {
		$secret  = $services['secret']->read();
		$hasher  = new Migration_Hasher( $secret );
		$journal = new Migration_Journal( $hasher );
		$context = $journal->verified_context( $post_id );
		$this->assertIsArray( $context );
		$payload = ( new Migration_Backup_Store( $hasher ) )->read_verified_payload( $post_id, $context['backupId'] );
		$this->assertIsArray( $payload );

		return compact( 'context', 'hasher', 'payload' );
	}

	/**
	 * Capture every authored field that migration must preserve exactly.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return array<string, mixed> Preserved authored state.
	 */
	private function preserved_state( int $post_id ): array {
		$post = get_post( $post_id );
		$meta = Legacy_Meta_Payload::capture( $post_id );

		return array(
			'post' => array(
				'content'     => $post->post_content,
				'name'        => $post->post_name,
				'title'       => $post->post_title,
				'excerpt'     => $post->post_excerpt,
				'menuOrder'   => $post->menu_order,
				'status'      => $post->post_status,
				'password'    => $post->post_password,
				'modified'    => $post->post_modified,
				'modifiedGmt' => $post->post_modified_gmt,
			),
			'meta' => $meta->to_array(),
		);
	}

	/**
	 * Assert the complete healthy post-restore contract.
	 *
	 * @param array<string, mixed> $fixture Applied fixture and trusted artifacts.
	 */
	private function assert_healthy_restored( array $fixture ): void {
		$post_id  = $fixture['postId'];
		$services = $fixture['services'];
		$status   = $services['status']->inspect( $post_id );
		$stored   = $this->journal( $fixture )->inspect( $post_id );

		$this->assertSame(
			Migration_Value_Encoder::encode( $fixture['before'] ),
			Migration_Value_Encoder::encode( $this->preserved_state( $post_id ) )
		);
		$this->assertSame( $fixture['prepared']['payload']['post']['postContent'], get_post( $post_id )->post_content );
		$this->assertSame( Migration_Deck_Mode_Store::ABSENT, $services['mode_store']->inspect( $post_id )['state'] );
		$this->assertSame(
			Migration_Value_Encoder::encode( $fixture['appliedArtifacts'] ),
			Migration_Value_Encoder::encode( $this->preserved_migration_artifacts( $post_id ) )
		);
		$this->assertTrue( $stored['valid'] );
		$this->assertSame( Migration_Journal::STATE_RESTORED, $stored['state'] );
		$this->assertSame( 4, $stored['sequence'] );
		$this->assertSame( $fixture['prepared']['context'], $this->journal( $fixture )->verified_context( $post_id ) );
		$this->assertTrue(
			( new Migration_Backup_Store( $fixture['prepared']['hasher'] ) )->verify( $post_id, $fixture['prepared']['context']['backupId'] )
		);
		$this->assertTrue(
			$services['revision']->verify_hash(
				$post_id,
				$fixture['prepared']['context']['revisionId'],
				$fixture['prepared']['hasher'],
				$fixture['prepared']['context']['revisionFieldsHash']
			)
		);
		$this->assertSame( 'unlocked', $services['lock']->inspect( $post_id )['state'] );
		$this->assertSame( Deck_Mode::LEGACY, $status['deckMode'] );
		$this->assertSame( 'original', $status['content']['classification'] );
		$this->assertSame( 'match', $status['source']['retained'] );
		$this->assertSame( 'verified', $status['backup']['state'] );
		$this->assertSame( 'verified', $status['backup']['revision'] );
		$this->assertTrue( $status['capabilities']['canPrepare'] );
		$this->assertFalse( $status['capabilities']['canApply'] );
		$this->assertFalse( $status['capabilities']['canRestore'] );
		$this->assertSame( array(), $status['codes'] );
	}

	/**
	 * Build the verified journal for one fixture.
	 *
	 * @param array<string, mixed> $fixture Applied fixture and trusted artifacts.
	 */
	private function journal( array $fixture ): Migration_Journal {
		return new Migration_Journal( $fixture['prepared']['hasher'] );
	}

	/**
	 * Capture durable migration artifacts for exact rerun comparison.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return array<string, mixed> Complete artifact footprint.
	 */
	private function artifact_footprint( int $post_id ): array {
		return array(
			'backups'   => get_post_meta( $post_id, Migration_Backup_Store::META_KEY, false ),
			'journal'   => get_post_meta( $post_id, Migration_Journal::META_KEY, false ),
			'mode'      => get_post_meta( $post_id, Deck_Mode::META_KEY, false ),
			'revisions' => array_keys( wp_get_post_revisions( $post_id ) ),
		);
	}

	/**
	 * Capture immutable apply safety artifacts that restore must retain exactly.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return array<string, mixed> Backup and revision footprint.
	 */
	private function preserved_migration_artifacts( int $post_id ): array {
		return array(
			'backups'   => get_post_meta( $post_id, Migration_Backup_Store::META_KEY, false ),
			'revisions' => array_keys( wp_get_post_revisions( $post_id ) ),
		);
	}
}

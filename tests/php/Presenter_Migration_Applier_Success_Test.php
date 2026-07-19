<?php
/**
 * Successful and resumable migration apply integration tests.
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
use Presenter\Migration_Revision;
use Presenter\Migration_Secret;
use Presenter\Migration_Status_Service;
use Presenter\Migration_Value_Encoder;
use Presenter\Native_Deck_Structure;
use Presenter\Null_Migration_Apply_Observer;
use Presenter\Slide_Attribute_Validator;
use Presenter\Speaker_Notes;
use Presenter\WordPress_Legacy_Slide_Source;

/**
 * Verify apply reaches and resumes the exact healthy native representation.
 */
final class Presenter_Migration_Applier_Success_Test extends Presenter_Test_Case {
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

	/** A prepared deck applies exactly once without changing retained source fields. */
	public function test_prepared_deck_applies_exactly_and_rerun_is_idempotent(): void {
		$post_id  = $this->create_ready_deck();
		$services = $this->services();
		$this->assertContains( 'prepared', $services['preparer']->prepare( $post_id )['codes'] );
		$prepared = $this->prepared_artifacts( $post_id, $services );
		$before   = $this->preserved_state( $post_id );

		$result = $services['applier']->apply( $post_id );

		$this->assertContains( 'applied', $result['codes'] );
		$this->assert_healthy_applied( $post_id, $services, $prepared, $before );
		$footprint = $this->artifact_footprint( $post_id );

		$rerun = $services['applier']->apply( $post_id );

		$this->assertContains( 'already_applied', $rerun['codes'] );
		$this->assertSame(
			Migration_Value_Encoder::encode( $footprint ),
			Migration_Value_Encoder::encode( $this->artifact_footprint( $post_id ) )
		);
		$this->assert_healthy_applied( $post_id, $services, $prepared, $before );
	}

	/** The locked service rejects authorization for another prepared attempt. */
	public function test_locked_apply_rejects_changed_authorized_attempt(): void {
		$post_id  = $this->create_ready_deck();
		$services = $this->services();
		$this->assertContains( 'prepared', $services['preparer']->prepare( $post_id )['codes'] );
		$status = $services['status']->inspect( $post_id );
		$before = $this->artifact_footprint( $post_id );

		$result = $services['applier']->apply(
			$post_id,
			wp_generate_uuid4(),
			$status['journal']['sequence']
		);

		$this->assertContains( 'authorized_attempt_changed', $result['codes'] );
		$this->assertSame( Migration_Journal::STATE_APPLY_PREPARED, $result['journal']['state'] );
		$this->assertSame( '', get_post_field( 'post_content', $post_id ) );
		$this->assertSame(
			Migration_Value_Encoder::encode( $before ),
			Migration_Value_Encoder::encode( $this->artifact_footprint( $post_id ) )
		);
	}

	/** Apply resumes verified target content written before routing cutover. */
	public function test_resume_prepared_target_with_absent_marker_completes_apply(): void {
		$post_id  = $this->create_ready_deck();
		$services = $this->services();
		$services['preparer']->prepare( $post_id );
		$prepared = $this->prepared_artifacts( $post_id, $services );
		$before   = $this->preserved_state( $post_id );

		$this->assertTrue(
			$services['writer']->compare_and_swap( $post_id, $prepared['payload']['post'], $prepared['payload']['targetContent'] )
		);
		$this->assertSame( Migration_Deck_Mode_Store::ABSENT, $services['mode_store']->inspect( $post_id )['state'] );
		$this->assertContains( 'apply_interrupted_before_cutover', $services['status']->inspect( $post_id )['codes'] );

		$result = $services['applier']->apply( $post_id );

		$this->assertContains( 'applied', $result['codes'] );
		$this->assert_healthy_applied( $post_id, $services, $prepared, $before );
	}

	/** Apply resumes a verified cutover that crashed before its applied event. */
	public function test_resume_prepared_target_with_native_marker_completes_event(): void {
		$post_id  = $this->create_ready_deck();
		$services = $this->services();
		$services['preparer']->prepare( $post_id );
		$prepared = $this->prepared_artifacts( $post_id, $services );
		$before   = $this->preserved_state( $post_id );

		$this->assertTrue(
			$services['writer']->compare_and_swap( $post_id, $prepared['payload']['post'], $prepared['payload']['targetContent'] )
		);
		$this->assertIsInt( $services['mode_store']->create_native( $post_id ) );
		$this->assertContains( 'apply_interrupted_after_cutover', $services['status']->inspect( $post_id )['codes'] );

		$result = $services['applier']->apply( $post_id );

		$this->assertContains( 'applied', $result['codes'] );
		$this->assert_healthy_applied( $post_id, $services, $prepared, $before );
	}

	/** Build the real preparation and apply services used by production. */
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
		$structure   = new Native_Deck_Structure();
		$builder     = new Migration_Context_Builder( $snapshotter, $planner, $mode_store );
		$status      = new Migration_Status_Service( $snapshotter, $planner, $secret, $lock, $revision, $deck_mode, $mode_store );
		$preparer    = new Migration_Preparer( $builder, $secret, $lock, $revision, $status, $deck_mode, $mode_store );
		$writer      = new Atomic_Migration_Post_Content_Writer();
		$applier     = new Migration_Applier(
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

		return compact( 'applier', 'lock', 'mode_store', 'preparer', 'revision', 'secret', 'status', 'structure', 'writer' );
	}

	/** Create a losslessly plannable deck with representative retained metadata. */
	private function create_ready_deck(): int {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'     => 'slideshow',
				'post_name'     => 'apply-success-sentinel',
				'post_status'   => 'publish',
				'post_title'    => 'Apply title sentinel',
				'post_excerpt'  => 'Apply excerpt sentinel',
				'post_content'  => '',
				'post_password' => 'apply-password',
				'menu_order'    => 17,
			)
		);
		add_post_meta(
			$post_id,
			'_presenter_slides',
			(object) array(
				'number'  => 1,
				'title'   => 'Slide title sentinel',
				'content' => '<p>Slide content sentinel</p>',
				'class'   => '',
			)
		);
		add_post_meta( $post_id, '_presenter-theme', '/plugins/presenter/reveal.js/css/theme/black.css' );
		add_post_meta( $post_id, '_presenter-short-url', 'apply-short-url' );

		return $post_id;
	}

	/**
	 * Read the trusted prepared payload and journal context for assertions.
	 *
	 * @param int                  $post_id  Slideshow post ID.
	 * @param array<string, mixed> $services Real service graph.
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
	 * Capture fields and metadata that apply must preserve byte-for-byte.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return array<string, mixed> Preserved authored state.
	 */
	private function preserved_state( int $post_id ): array {
		$post = get_post( $post_id );
		$meta = Legacy_Meta_Payload::capture( $post_id );

		return array(
			'post' => array(
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
	 * Assert the complete healthy post-apply contract.
	 *
	 * @param int                  $post_id  Slideshow post ID.
	 * @param array<string, mixed> $services Real service graph.
	 * @param array<string, mixed> $prepared Trusted prepared artifacts.
	 * @param array<string, mixed> $before   Preserved pre-apply state.
	 */
	private function assert_healthy_applied( int $post_id, array $services, array $prepared, array $before ): void {
		$post    = get_post( $post_id );
		$status  = $services['status']->inspect( $post_id );
		$journal = new Migration_Journal( $prepared['hasher'] );
		$stored  = $journal->inspect( $post_id );

		$this->assertSame( $prepared['payload']['targetContent'], $post->post_content );
		$this->assertTrue( $services['structure']->is_valid( $post->post_content ) );
		$this->assertSame( array( Deck_Mode::NATIVE ), get_post_meta( $post_id, Deck_Mode::META_KEY, false ) );
		$this->assertSame(
			Migration_Value_Encoder::encode( $before ),
			Migration_Value_Encoder::encode( $this->preserved_state( $post_id ) )
		);
		$this->assertTrue( $stored['valid'] );
		$this->assertSame( Migration_Journal::STATE_APPLIED, $stored['state'] );
		$this->assertSame( 2, $stored['sequence'] );
		$this->assertSame( $prepared['context'], $journal->verified_context( $post_id ) );
		$this->assertTrue(
			( new Migration_Backup_Store( $prepared['hasher'] ) )->verify( $post_id, $prepared['context']['backupId'] )
		);
		$this->assertTrue(
			$services['revision']->verify_hash(
				$post_id,
				$prepared['context']['revisionId'],
				$prepared['hasher'],
				$prepared['context']['revisionFieldsHash']
			)
		);
		$this->assertSame( 'unlocked', $services['lock']->inspect( $post_id )['state'] );
		$this->assertSame( Deck_Mode::NATIVE, $status['deckMode'] );
		$this->assertSame( 'target', $status['content']['classification'] );
		$this->assertSame( 'match', $status['source']['retained'] );
		$this->assertSame( 'verified', $status['backup']['state'] );
		$this->assertSame( 'verified', $status['backup']['revision'] );
		$this->assertFalse( $status['capabilities']['canApply'] );
		$this->assertTrue( $status['capabilities']['canRestore'] );
		$this->assertSame( array(), $status['codes'] );
	}

	/**
	 * Count every durable apply artifact for exact rerun comparison.
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
}

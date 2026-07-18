<?php
/**
 * Presenter migration apply WP-CLI adapter tests.
 *
 * @package Presenter
 */

use Presenter\Atomic_Migration_Post_Content_Writer;
use Presenter\Deck_Mode;
use Presenter\Legacy_Deck_Snapshotter;
use Presenter\Legacy_Section_Validator;
use Presenter\Legacy_Slide_Attribute_Mapper;
use Presenter\Legacy_Slide_Normalizer;
use Presenter\Migration_Applier;
use Presenter\Migration_CLI;
use Presenter\Migration_Context_Builder;
use Presenter\Migration_Deck_Mode_Store;
use Presenter\Migration_Journal;
use Presenter\Migration_Lock;
use Presenter\Migration_Planner;
use Presenter\Migration_Preparer;
use Presenter\Migration_Restorer;
use Presenter\Migration_Revision;
use Presenter\Migration_Secret;
use Presenter\Migration_Status_Service;
use Presenter\Native_Deck_Structure;
use Presenter\Null_Migration_Apply_Observer;
use Presenter\Null_Migration_Restore_Observer;
use Presenter\Slide_Attribute_Validator;
use Presenter\Speaker_Notes;
use Presenter\WordPress_Legacy_Slide_Source;

require_once __DIR__ . '/support/class-wp-cli.php';

if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', true );
}

/**
 * Verify apply registration, JSON output, confirmation, and exit semantics.
 */
final class Presenter_Migration_CLI_Apply_Test extends Presenter_Test_Case {
	/** Reset captured CLI and migration state before each test. */
	public function set_up(): void {
		parent::set_up();
		WP_CLI::reset();
		delete_option( Migration_Secret::OPTION_NAME );
		wp_set_current_user( 0 );
	}

	/** Restore global migration state after each test. */
	public function tear_down(): void {
		delete_option( Migration_Secret::OPTION_NAME );
		wp_set_current_user( 0 );
		WP_CLI::reset();
		parent::tear_down();
	}

	/** Register every migration command including the explicit apply adapter. */
	public function test_registers_apply_with_the_existing_migration_commands(): void {
		$services = $this->services();

		$services['cli']->register_hooks();

		$this->assertSame(
			array(
				'presenter migration dry-run',
				'presenter migration prepare',
				'presenter migration apply',
				'presenter migration restore',
				'presenter migration status',
			),
			array_keys( WP_CLI::$commands )
		);
		$this->assertSame( array( $services['cli'], 'apply' ), WP_CLI::$commands['presenter migration apply'] );
	}

	/** A successful apply confirms, emits one JSON envelope, and does not halt. */
	public function test_successful_apply_emits_json_without_nonzero_halt(): void {
		$services = $this->services();
		$post_id  = $this->create_ready_deck();
		$this->assertContains( 'prepared', $services['preparer']->prepare( $post_id )['codes'] );

		$services['cli']->apply( array( (string) $post_id ), array( 'yes' => true ) );

		$this->assertSame(
			array(
				array(
					'question'  => sprintf( 'Apply prepared native content and cut over slideshow %d?', $post_id ),
					'assocArgs' => array( 'yes' => true ),
				),
			),
			WP_CLI::$confirmations
		);
		$this->assertSame( array(), WP_CLI::$halts );
		$this->assertCount( 1, WP_CLI::$lines );
		$result = json_decode( WP_CLI::$lines[0], true );
		$this->assertIsArray( $result );
		$this->assertSame( 'apply', $result['mode'] );
		$this->assertSame( Migration_Journal::STATE_APPLIED, $result['journal']['state'] );
		$this->assertContains( 'applied', $result['codes'] );
		$this->assert_cli_line_redacted( WP_CLI::$lines[0] );
	}

	/** A characterized apply failure emits JSON before halting with status one. */
	public function test_failed_apply_emits_json_then_halts_nonzero(): void {
		$services = $this->services();
		$post_id  = $this->create_ready_deck();

		try {
			$services['cli']->apply( array( (string) $post_id ), array( 'yes' => true ) );
			$this->fail( 'A failed apply must halt WP-CLI.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'wp_cli_halt', $error->getMessage() );
		}

		$this->assertSame( array( 1 ), WP_CLI::$halts );
		$this->assertCount( 1, WP_CLI::$confirmations );
		$this->assertCount( 1, WP_CLI::$lines );
		$result = json_decode( WP_CLI::$lines[0], true );
		$this->assertIsArray( $result );
		$this->assertSame( 'apply', $result['mode'] );
		$this->assertNotSame( Migration_Journal::STATE_APPLIED, $result['journal']['state'] );
		$this->assertContains( 'not_applicable', $result['codes'] );
		$this->assert_cli_line_redacted( WP_CLI::$lines[0] );
	}

	/** A site query policy cannot hide password-protected legacy decks. */
	public function test_batch_dry_run_ignores_pre_get_posts_password_filter(): void {
		$services       = $this->services();
		$public_id      = $this->create_ready_deck();
		$protected_id   = $this->create_ready_deck( array( 'post_password' => 'local-password' ) );
		$hide_passwords = static function ( WP_Query $query ): void {
			$query->set( 'has_password', false );
		};

		add_action( 'pre_get_posts', $hide_passwords );
		try {
			$services['cli']->dry_run(
				array(),
				array(
					'limit'  => '100',
					'offset' => '0',
				)
			);
		} finally {
			remove_action( 'pre_get_posts', $hide_passwords );
		}

		$result = json_decode( WP_CLI::$lines[0], true );
		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['count'] );
		$this->assertSame( array( $public_id, $protected_id ), array_column( $result['reports'], 'postId' ) );
	}

	/** Batch discovery keeps deterministic limit/offset semantics across eligible statuses. */
	public function test_batch_dry_run_is_bounded_ordered_and_excludes_non_authored_statuses(): void {
		$services = $this->services();
		$this->create_ready_deck( array( 'post_status' => 'publish' ) );
		$draft_id   = $this->create_ready_deck( array( 'post_status' => 'draft' ) );
		$private_id = $this->create_ready_deck( array( 'post_status' => 'private' ) );
		$this->create_ready_deck( array( 'post_status' => 'trash' ) );
		$this->create_ready_deck( array( 'post_status' => 'auto-draft' ) );

		$services['cli']->dry_run(
			array(),
			array(
				'limit'  => '2',
				'offset' => '1',
			)
		);

		$result = json_decode( WP_CLI::$lines[0], true );
		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['count'] );
		$this->assertSame( array( $draft_id, $private_id ), array_column( $result['reports'], 'postId' ) );
	}

	/** Build the real production service graph around the capturing CLI surface. */
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
		$applier     = new Migration_Applier(
			$builder,
			$secret,
			$lock,
			$revision,
			$status,
			$mode_store,
			$structure,
			new Atomic_Migration_Post_Content_Writer(),
			new Null_Migration_Apply_Observer()
		);
		$restorer    = new Migration_Restorer(
			$secret,
			$lock,
			$revision,
			$status,
			$mode_store,
			$structure,
			new Atomic_Migration_Post_Content_Writer(),
			new Null_Migration_Restore_Observer()
		);
		$cli         = new Migration_CLI( $snapshotter, $planner, $preparer, $applier, $restorer, $status );

		return compact( 'cli', 'preparer' );
	}

	/**
	 * Create one ready legacy deck with private authored sentinels.
	 *
	 * @param array<string, mixed> $post_data Post fields to override.
	 */
	private function create_ready_deck( array $post_data = array() ): int {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array_merge(
				array(
					'post_type'    => 'slideshow',
					'post_status'  => 'publish',
					'post_title'   => 'private-cli-title-sentinel',
					'post_excerpt' => 'private-cli-excerpt-sentinel',
					'post_content' => '',
				),
				$post_data
			)
		);
		add_post_meta(
			$post_id,
			'_presenter_slides',
			(object) array(
				'number'  => 1,
				'title'   => 'private-cli-slide-title-sentinel',
				'content' => '<p>private-cli-slide-content-sentinel</p>',
				'class'   => '',
			)
		);

		return $post_id;
	}

	/**
	 * Assert serialized command output contains no authored values or private keys.
	 *
	 * @param string $line Captured JSON line.
	 */
	private function assert_cli_line_redacted( string $line ): void {
		foreach (
			array(
				'private-cli-title-sentinel',
				'private-cli-excerpt-sentinel',
				'private-cli-slide-title-sentinel',
				'private-cli-slide-content-sentinel',
				'backupId',
				'revisionId',
				'preconditionHash',
				'targetContent',
			) as $private_value
		) {
			$this->assertStringNotContainsString( $private_value, $line );
		}
	}
}

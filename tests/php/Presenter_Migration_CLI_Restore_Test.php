<?php
/**
 * Presenter migration restore WP-CLI adapter tests.
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
 * Verify restore confirmation, JSON output, and exit semantics.
 */
final class Presenter_Migration_CLI_Restore_Test extends Presenter_Test_Case {
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

	/** A successful restore confirms and emits a redacted JSON envelope. */
	public function test_successful_restore_emits_json_without_nonzero_halt(): void {
		$services = $this->services();
		$post_id  = $this->create_ready_deck();
		$this->assertContains( 'prepared', $services['preparer']->prepare( $post_id )['codes'] );
		$this->assertContains( 'applied', $services['applier']->apply( $post_id )['codes'] );

		$services['cli']->restore( array( (string) $post_id ), array( 'yes' => true ) );

		$this->assertSame(
			array(
				array(
					'question'  => sprintf( 'Restore verified legacy content and routing for slideshow %d?', $post_id ),
					'assocArgs' => array( 'yes' => true ),
				),
			),
			WP_CLI::$confirmations
		);
		$this->assertSame( array(), WP_CLI::$halts );
		$this->assertCount( 1, WP_CLI::$lines );
		$result = json_decode( WP_CLI::$lines[0], true );
		$this->assertIsArray( $result );
		$this->assertSame( 'restore', $result['mode'] );
		$this->assertSame( Migration_Journal::STATE_RESTORED, $result['journal']['state'] );
		$this->assertContains( 'restored', $result['codes'] );
		$this->assert_cli_line_redacted( WP_CLI::$lines[0] );
	}

	/** A characterized restore failure emits JSON before halting with status one. */
	public function test_failed_restore_emits_json_then_halts_nonzero(): void {
		$services = $this->services();
		$post_id  = $this->create_ready_deck();

		try {
			$services['cli']->restore( array( (string) $post_id ), array( 'yes' => true ) );
			$this->fail( 'A failed restore must halt WP-CLI.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'wp_cli_halt', $error->getMessage() );
		}

		$this->assertSame( array( 1 ), WP_CLI::$halts );
		$this->assertCount( 1, WP_CLI::$confirmations );
		$this->assertCount( 1, WP_CLI::$lines );
		$result = json_decode( WP_CLI::$lines[0], true );
		$this->assertIsArray( $result );
		$this->assertSame( 'restore', $result['mode'] );
		$this->assertNotSame( Migration_Journal::STATE_RESTORED, $result['journal']['state'] );
		$this->assertContains( 'not_restorable', $result['codes'] );
		$this->assert_cli_line_redacted( WP_CLI::$lines[0] );
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
		$cli         = new Migration_CLI( new \Presenter\Legacy_Deck_Inventory(), $snapshotter, $planner, $preparer, $applier, $restorer, $status );

		return compact( 'cli', 'preparer', 'applier' );
	}

	/** Create one ready legacy deck with private authored sentinels. */
	private function create_ready_deck(): int {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'    => 'slideshow',
				'post_status'  => 'publish',
				'post_title'   => 'private-restore-cli-title-sentinel',
				'post_excerpt' => 'private-restore-cli-excerpt-sentinel',
				'post_content' => '',
			)
		);
		add_post_meta(
			$post_id,
			'_presenter_slides',
			(object) array(
				'number'  => 1,
				'title'   => 'private-restore-cli-slide-title-sentinel',
				'content' => '<p>private-restore-cli-slide-content-sentinel</p>',
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
				'private-restore-cli-title-sentinel',
				'private-restore-cli-excerpt-sentinel',
				'private-restore-cli-slide-title-sentinel',
				'private-restore-cli-slide-content-sentinel',
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

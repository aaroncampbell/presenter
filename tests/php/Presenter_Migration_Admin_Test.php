<?php
/**
 * Presenter migration admin boundary tests.
 *
 * @package Presenter
 */

use Presenter\Deck_Mode;
use Presenter\Migration_Admin;
use Presenter\Migration_Backup_Store;
use Presenter\Migration_Journal;
use Presenter\Migration_Lock;
use Presenter\Migration_Secret;
use Presenter\Migration_Value_Encoder;

/** Verify the initial authenticated, prepare-only migration tool. */
final class Presenter_Migration_Admin_Test extends Presenter_Test_Case {
	/** Reset request and global migration state before each test. */
	public function set_up(): void {
		parent::set_up();

		delete_option( Migration_Secret::OPTION_NAME );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
	}

	/** Restore request and global migration state. */
	public function tear_down(): void {
		delete_option( Migration_Secret::OPTION_NAME );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_METHOD'] );

		parent::tear_down();
	}

	/** The application registers the Tools page and authenticated POST handler. */
	public function test_application_registers_admin_hooks(): void {
		$this->assertSame( 10, has_action( 'admin_menu', array( $this->admin_provider(), 'register_page' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_' . Migration_Admin::PREPARE_ACTION, array( $this->admin_provider(), 'handle_prepare' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_' . Migration_Admin::APPLY_ACTION, array( $this->admin_provider(), 'handle_apply' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_' . Migration_Admin::RESTORE_ACTION, array( $this->admin_provider(), 'handle_restore' ) ) );
	}

	/** An applied deck renders an explicit, zero-write legacy restore form. */
	public function test_applied_row_renders_scoped_restore_confirmation(): void {
		$post_id = $this->create_ready_deck( array( 'post_title' => 'Applied restore identity' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_valid_prepare_request( $post_id );
		$this->assertSame( 'prepared', $this->admin_provider()->process_prepare_request() );
		$this->set_valid_apply_request( $post_id );
		$this->assertSame( 'applied', $this->admin_provider()->process_apply_request() );
		$before = $this->state_fingerprint( $post_id );

		$_POST    = array();
		$_REQUEST = array();
		ob_start();
		$this->admin_provider()->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="action" value="' . Migration_Admin::RESTORE_ACTION . '"', $output );
		$this->assertStringNotContainsString( 'name="action" value="' . Migration_Admin::APPLY_ACTION . '"', $output );
		$this->assertStringContainsString( 'name="presenter_confirm" value="restore" required', $output );
		$this->assertStringContainsString( 'Restore legacy content', $output );
		$this->assertSame( 1, preg_match( '/name="_wpnonce" value="([^"]+)"/', $output, $matches ) );
		$this->assertSame( 1, wp_verify_nonce( $matches[1], Migration_Admin::RESTORE_ACTION . ':' . $post_id ) );
		$this->assertFalse( wp_verify_nonce( $matches[1], Migration_Admin::APPLY_ACTION . ':' . $post_id ) );
		foreach ( $this->private_artifact_values( $post_id ) as $private_value ) {
			$this->assertStringNotContainsString( $private_value, $output );
		}
		$this->assertSame( $before, $this->state_fingerprint( $post_id ) );
	}

	/** A confirmed valid request restores exactly one deck idempotently. */
	public function test_valid_restore_request_is_one_deck_bounded_and_idempotent(): void {
		$post_id     = $this->create_ready_deck();
		$neighbor_id = $this->create_ready_deck( array( 'post_title' => 'Restore neighbor sentinel' ) );
		$original    = get_post_field( 'post_content', $post_id );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_valid_prepare_request( $post_id );
		$this->assertSame( 'prepared', $this->admin_provider()->process_prepare_request() );
		$this->set_valid_apply_request( $post_id );
		$this->assertSame( 'applied', $this->admin_provider()->process_apply_request() );
		$neighbor_before = $this->state_fingerprint( $neighbor_id );

		$this->set_valid_restore_request( $post_id );
		$this->assertSame( 'restored', $this->admin_provider()->process_restore_request() );
		$status = $this->migration_status( $post_id );
		$this->assertSame( Migration_Journal::STATE_RESTORED, $status['journal']['state'] );
		$this->assertSame( 'legacy', $status['deckMode'] );
		$this->assertSame( $original, get_post_field( 'post_content', $post_id ) );
		$this->assertTrue( $status['capabilities']['canPrepare'] );
		$this->assertNotEmpty( get_post_meta( $post_id, Migration_Backup_Store::META_KEY, false ) );
		$this->assertNotEmpty( get_post_meta( $post_id, Migration_Journal::META_KEY, false ) );
		$this->assertSame( $neighbor_before, $this->state_fingerprint( $neighbor_id ) );
		$restored_state = $this->state_fingerprint( $post_id );

		$this->set_valid_restore_request( $post_id );
		$this->assertSame( 'restored', $this->admin_provider()->process_restore_request() );
		$this->assertSame( $restored_state, $this->state_fingerprint( $post_id ) );
		$this->assertSame( $neighbor_before, $this->state_fingerprint( $neighbor_id ) );
	}

	/** Restore requires its exact scalar confirmation and operation nonce. */
	public function test_restore_rejects_invalid_confirmation_and_apply_nonce(): void {
		$post_id = $this->create_ready_deck();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_valid_prepare_request( $post_id );
		$this->assertSame( 'prepared', $this->admin_provider()->process_prepare_request() );
		$this->set_valid_apply_request( $post_id );
		$this->assertSame( 'applied', $this->admin_provider()->process_apply_request() );
		$applied_state = $this->state_fingerprint( $post_id );

		foreach ( array( '', 'RE STORE', array( 'restore' ) ) as $invalid_confirmation ) {
			$this->set_valid_restore_request( $post_id );
			$_POST['presenter_confirm']    = $invalid_confirmation;
			$_REQUEST['presenter_confirm'] = $invalid_confirmation;
			try {
				$this->admin_provider()->process_restore_request();
				$this->fail( 'Only the exact scalar Restore confirmation may pass.' );
			} catch ( WPDieException $exception ) {
				$this->assertStringContainsString( 'Confirm', $exception->getMessage() );
			}
			$this->assertSame( $applied_state, $this->state_fingerprint( $post_id ) );
		}

		$this->set_valid_apply_request( $post_id );
		$_POST['presenter_confirm']    = 'restore';
		$_REQUEST['presenter_confirm'] = 'restore';
		try {
			$this->admin_provider()->process_restore_request();
			$this->fail( 'An Apply nonce must not authorize Restore.' );
		} catch ( WPDieException $exception ) {
			$this->assertNotSame( '', $exception->getMessage() );
		}
		$this->assertSame( $applied_state, $this->state_fingerprint( $post_id ) );

		$this->set_valid_restore_request( $post_id );
		$wrong_nonce          = wp_create_nonce( Migration_Admin::RESTORE_ACTION . ':' . ( $post_id + 1 ) );
		$_POST['_wpnonce']    = $wrong_nonce;
		$_REQUEST['_wpnonce'] = $wrong_nonce;
		try {
			$this->admin_provider()->process_restore_request();
			$this->fail( 'A nonce for another post must not authorize Restore.' );
		} catch ( WPDieException $exception ) {
			$this->assertNotSame( '', $exception->getMessage() );
		}
		$this->assertSame( $applied_state, $this->state_fingerprint( $post_id ) );
	}

	/** Restore outcomes fail closed across complete, resumable, and invalid states. */
	public function test_restore_result_classifier_uses_persisted_representation(): void {
		$legacy = array(
			'journal'      => array( 'state' => Migration_Journal::STATE_RESTORED ),
			'capabilities' => array( 'canRestore' => false ),
			'codes'        => array( 'restored' ),
			'deckMode'     => 'legacy',
			'content'      => array( 'classification' => 'original' ),
			'source'       => array(
				'precondition' => 'match',
				'retained'     => 'match',
			),
			'backup'       => array(
				'state'    => 'verified',
				'revision' => 'verified',
			),
		);
		$this->assertSame( 'restored', $this->admin_provider()->classify_restore_result( $legacy ) );
		$legacy['codes'][] = 'lock_release_failed';
		$this->assertSame( 'restored-warning', $this->admin_provider()->classify_restore_result( $legacy ) );
		$legacy['content']['classification'] = 'modified';
		$this->assertSame( 'restore-review-required', $this->admin_provider()->classify_restore_result( $legacy ) );

		$resumable                               = $legacy;
		$resumable['journal']['state']           = Migration_Journal::STATE_RESTORE_PREPARED;
		$resumable['content']['classification']  = 'original';
		$resumable['capabilities']['canRestore'] = true;
		$this->assertSame( 'restore-incomplete', $this->admin_provider()->classify_restore_result( $resumable ) );
		$resumable['capabilities']['canRestore'] = false;
		$this->assertSame( 'restore-incomplete', $this->admin_provider()->classify_restore_result( $resumable ) );
		$resumable['content']['classification'] = 'target';
		$this->assertSame( 'restore-incomplete', $this->admin_provider()->classify_restore_result( $resumable ) );
		$resumable['deckMode'] = 'native';
		$this->assertSame( 'restore-incomplete', $this->admin_provider()->classify_restore_result( $resumable ) );
		$resumable['codes'][] = 'post_fields_changed';
		$this->assertSame( 'restore-review-required', $this->admin_provider()->classify_restore_result( $resumable ) );
		$resumable['deckMode'] = 'legacy';
		$resumable['codes']    = array( 'lock_unavailable', 'restore_cutover_invalid' );
		$this->assertSame( 'restore-review-required', $this->admin_provider()->classify_restore_result( $resumable ) );

		$native                               = $legacy;
		$native['journal']['state']           = Migration_Journal::STATE_APPLIED;
		$native['capabilities']['canRestore'] = true;
		$native['codes']                      = array( 'edit_lock_active' );
		$native['deckMode']                   = 'native';
		$native['content']                    = array( 'classification' => 'target' );
		$this->assertSame( 'restore-failed', $this->admin_provider()->classify_restore_result( $native ) );
		$native['backup']['revision'] = 'missing';
		$this->assertSame( 'restore-review-required', $this->admin_provider()->classify_restore_result( $native ) );
		$native['journal']['state'] = Migration_Journal::STATE_RECOVERY_REQUIRED;
		$this->assertSame( 'recovery-required', $this->admin_provider()->classify_restore_result( $native ) );
	}

	/** An unsigned or forged query cannot produce a migration result notice. */
	public function test_result_notice_rejects_unsigned_query(): void {
		$post_id = $this->create_ready_deck();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET = array(
			'presenter-result' => 'restored',
			'presenter-post'   => (string) $post_id,
		);

		$output = $this->render_admin_page();

		$this->assertStringNotContainsString( 'The exact verified legacy content and renderer are active again.', $output );
		$this->assertStringNotContainsString( 'notice-success', $output );
	}

	/** A signed Restore receipt renders success only while exact restored state persists. */
	public function test_signed_restore_notice_rechecks_persisted_state_without_writes(): void {
		$post_id = $this->create_ready_deck();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_valid_prepare_request( $post_id );
		$this->assertSame( 'prepared', $this->admin_provider()->process_prepare_request() );
		$this->set_valid_apply_request( $post_id );
		$this->assertSame( 'applied', $this->admin_provider()->process_apply_request() );
		$this->set_valid_restore_request( $post_id );
		$this->assertSame( 'restored', $this->admin_provider()->process_restore_request() );
		$this->set_result_query( 'restored', $post_id );
		$before = $this->state_fingerprint( $post_id );

		$output = $this->render_admin_page();

		$this->assertStringContainsString( 'The exact verified legacy content and renderer are active again.', $output );
		$this->assertSame( $before, $this->state_fingerprint( $post_id ) );
	}

	/** A once-valid success receipt degrades to review when persisted state changes. */
	public function test_signed_apply_notice_fails_closed_after_state_change(): void {
		$post_id = $this->create_ready_deck();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_valid_prepare_request( $post_id );
		$this->assertSame( 'prepared', $this->admin_provider()->process_prepare_request() );
		$this->set_valid_apply_request( $post_id );
		$this->assertSame( 'applied', $this->admin_provider()->process_apply_request() );
		$this->set_result_query( 'applied', $post_id );
		delete_post_meta( $post_id, Deck_Mode::META_KEY );
		$before = $this->state_fingerprint( $post_id );

		$output = $this->render_admin_page();

		$this->assertStringNotContainsString( 'The verified native content is active.', $output );
		$this->assertStringContainsString( 'The migration state changed before this notice could be verified.', $output );
		$this->assertSame( $before, $this->state_fingerprint( $post_id ) );
	}

	/** A signed receipt is bound to the administrator who initiated the action. */
	public function test_signed_result_notice_is_current_user_bound(): void {
		$post_id = $this->create_ready_deck();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_valid_prepare_request( $post_id );
		$this->assertSame( 'prepared', $this->admin_provider()->process_prepare_request() );
		$this->set_result_query( 'prepared', $post_id );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$output = $this->render_admin_page();

		$this->assertStringNotContainsString( 'The slideshow safety artifacts are verified and ready to apply.', $output );
		$this->assertStringNotContainsString( 'notice-success', $output );
	}

	/** A replayed failure receipt cannot override a later verified success. */
	public function test_failure_notice_is_rechecked_after_later_success(): void {
		$post_id = $this->create_ready_deck();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_result_query( 'prepare-failed', $post_id );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserves a production-signed test receipt across the simulated POST.
		$signed_query = $_GET;
		$this->set_valid_prepare_request( $post_id );
		$this->assertSame( 'prepared', $this->admin_provider()->process_prepare_request() );
		$_GET = $signed_query;

		$output = $this->render_admin_page();

		$this->assertStringNotContainsString( 'Presenter could not safely prepare that slideshow.', $output );
		$this->assertStringContainsString( 'The migration state changed before this notice could be verified.', $output );
	}

	/** A cleared lock-cleanup warning is canonicalized to current success. */
	public function test_stale_apply_warning_is_canonicalized_after_lock_clears(): void {
		$post_id = $this->create_ready_deck();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_valid_prepare_request( $post_id );
		$this->assertSame( 'prepared', $this->admin_provider()->process_prepare_request() );
		$this->set_valid_apply_request( $post_id );
		$this->assertSame( 'applied', $this->admin_provider()->process_apply_request() );
		$this->set_result_query( 'applied-warning', $post_id );

		$output = $this->render_admin_page();

		$this->assertStringContainsString( 'The verified native content is active.', $output );
		$this->assertStringNotContainsString( 'lock cleanup needs attention', $output );
	}

	/** A prepared deck renders an explicit, zero-write native cutover form. */
	public function test_prepared_row_renders_scoped_apply_confirmation(): void {
		$post_id = $this->create_ready_deck( array( 'post_title' => 'Prepared apply identity' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_valid_prepare_request( $post_id );
		$this->assertSame( 'prepared', $this->admin_provider()->process_prepare_request() );
		$before = $this->state_fingerprint( $post_id );

		$_POST    = array();
		$_REQUEST = array();
		ob_start();
		$this->admin_provider()->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="action" value="' . Migration_Admin::APPLY_ACTION . '"', $output );
		$this->assertStringNotContainsString( 'name="action" value="' . Migration_Admin::PREPARE_ACTION . '"', $output );
		$this->assertStringContainsString( 'name="presenter_confirm" value="apply" required', $output );
		$this->assertStringContainsString( 'name="return_page" value="1"', $output );
		$this->assertStringContainsString( 'Apply native content', $output );
		$this->assertStringContainsString( 'legacy metadata, verified backup, and revision are retained', $output );
		$this->assertSame( 1, preg_match( '/name="_wpnonce" value="([^"]+)"/', $output, $matches ) );
		$this->assertSame( 1, wp_verify_nonce( $matches[1], Migration_Admin::APPLY_ACTION . ':' . $post_id ) );
		$this->assertFalse( wp_verify_nonce( $matches[1], Migration_Admin::PREPARE_ACTION . ':' . $post_id ) );
		foreach ( $this->private_artifact_values( $post_id ) as $private_value ) {
			$this->assertStringNotContainsString( $private_value, $output );
		}
		$this->assertStringNotContainsString( 'Private slide content sentinel', $output );
		$this->assertSame( $before, $this->state_fingerprint( $post_id ) );
	}

	/** A confirmed valid request applies exactly one deck idempotently. */
	public function test_valid_apply_request_is_one_deck_bounded_and_idempotent(): void {
		$post_id     = $this->create_ready_deck();
		$neighbor_id = $this->create_ready_deck( array( 'post_title' => 'Apply neighbor sentinel' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_valid_prepare_request( $post_id );
		$this->assertSame( 'prepared', $this->admin_provider()->process_prepare_request() );
		$neighbor_before = $this->state_fingerprint( $neighbor_id );

		$this->set_valid_apply_request( $post_id );
		$this->assertSame( 'applied', $this->admin_provider()->process_apply_request() );
		$status = $this->migration_status( $post_id );
		$this->assertSame( Migration_Journal::STATE_APPLIED, $status['journal']['state'] );
		$this->assertSame( 'native', $status['deckMode'] );
		$this->assertTrue( $status['capabilities']['canRestore'] );
		$this->assertStringContainsString( '<!-- wp:presenter/deck', get_post_field( 'post_content', $post_id ) );
		$this->assertNotEmpty( get_post_meta( $post_id, '_presenter_slides', false ) );
		$this->assertSame( $neighbor_before, $this->state_fingerprint( $neighbor_id ) );
		$applied_state = $this->state_fingerprint( $post_id );

		$this->set_valid_apply_request( $post_id );
		$this->assertSame( 'applied', $this->admin_provider()->process_apply_request() );
		$this->assertSame( $applied_state, $this->state_fingerprint( $post_id ) );
		$this->assertSame( $neighbor_before, $this->state_fingerprint( $neighbor_id ) );
	}

	/** Apply requires its exact confirmation and its own operation nonce. */
	public function test_apply_rejects_missing_confirmation_and_prepare_nonce(): void {
		$post_id = $this->create_ready_deck();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_valid_prepare_request( $post_id );
		$this->assertSame( 'prepared', $this->admin_provider()->process_prepare_request() );
		$prepared_state = $this->state_fingerprint( $post_id );

		$this->set_valid_apply_request( $post_id );
		unset( $_POST['presenter_confirm'], $_REQUEST['presenter_confirm'] );
		try {
			$this->admin_provider()->process_apply_request();
			$this->fail( 'Apply without explicit confirmation must terminate.' );
		} catch ( WPDieException $exception ) {
			$this->assertStringContainsString( 'Confirm', $exception->getMessage() );
		}
		$this->assertSame( $prepared_state, $this->state_fingerprint( $post_id ) );

		foreach ( array( 'AP PLY', array( 'apply' ) ) as $invalid_confirmation ) {
			$this->set_valid_apply_request( $post_id );
			$_POST['presenter_confirm']    = $invalid_confirmation;
			$_REQUEST['presenter_confirm'] = $invalid_confirmation;
			try {
				$this->admin_provider()->process_apply_request();
				$this->fail( 'Only the exact scalar Apply confirmation may pass.' );
			} catch ( WPDieException $exception ) {
				$this->assertStringContainsString( 'Confirm', $exception->getMessage() );
			}
			$this->assertSame( $prepared_state, $this->state_fingerprint( $post_id ) );
		}

		$this->set_valid_prepare_request( $post_id );
		$_POST['presenter_confirm']    = 'apply';
		$_REQUEST['presenter_confirm'] = 'apply';
		try {
			$this->admin_provider()->process_apply_request();
			$this->fail( 'A Prepare nonce must not authorize Apply.' );
		} catch ( WPDieException $exception ) {
			$this->assertNotSame( '', $exception->getMessage() );
		}
		$this->assertSame( $prepared_state, $this->state_fingerprint( $post_id ) );

		$this->set_valid_apply_request( $post_id );
		$_POST['_wpnonce']    = wp_create_nonce( Migration_Admin::APPLY_ACTION . ':' . ( $post_id + 1 ) );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Constructs a deliberately wrong-post nonce.
		try {
			$this->admin_provider()->process_apply_request();
			$this->fail( 'An Apply nonce for another post must not authorize this deck.' );
		} catch ( WPDieException $exception ) {
			$this->assertNotSame( '', $exception->getMessage() );
		}
		$this->assertSame( $prepared_state, $this->state_fingerprint( $post_id ) );
	}

	/** Lock and edit contention cannot cross the admin cutover boundary. */
	public function test_apply_reports_contention_without_cutover(): void {
		$post_id = $this->create_ready_deck();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_valid_prepare_request( $post_id );
		$this->assertSame( 'prepared', $this->admin_provider()->process_prepare_request() );

		$lock   = new Migration_Lock();
		$handle = $lock->acquire( $post_id );
		$this->assertNotNull( $handle );
		try {
			$locked_state = $this->state_fingerprint( $post_id );
			$this->set_valid_apply_request( $post_id );
			$this->assertSame( 'apply-failed', $this->admin_provider()->process_apply_request() );
			$this->assertSame( $locked_state, $this->state_fingerprint( $post_id ) );
			$this->assertSame( Migration_Journal::STATE_APPLY_PREPARED, $this->migration_status( $post_id )['journal']['state'] );
		} finally {
			$this->assertTrue( $lock->release( $handle ) );
		}

		$other_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_post_meta( $post_id, '_edit_lock', time() . ':' . $other_user );
		$edit_locked_state = $this->state_fingerprint( $post_id );
		$this->set_valid_apply_request( $post_id );
		$this->assertSame( 'apply-failed', $this->admin_provider()->process_apply_request() );
		$this->assertSame( $edit_locked_state, $this->state_fingerprint( $post_id ) );
		$this->assertSame( 'legacy', $this->migration_status( $post_id )['deckMode'] );
	}

	/** Every apply result maps to one fixed operator-facing outcome. */
	public function test_apply_result_classifier_distinguishes_persisted_outcomes(): void {
		$base = array(
			'journal'      => array( 'state' => Migration_Journal::STATE_APPLIED ),
			'capabilities' => array( 'canRestore' => true ),
			'codes'        => array( 'applied' ),
			'deckMode'     => 'native',
			'content'      => array( 'classification' => 'target' ),
		);

		$this->assertSame( 'applied', $this->admin_provider()->classify_apply_result( $base ) );
		$base['codes'] = array( 'already_applied' );
		$this->assertSame( 'applied', $this->admin_provider()->classify_apply_result( $base ) );
		$base['codes'][] = 'lock_release_failed';
		$this->assertSame( 'applied-warning', $this->admin_provider()->classify_apply_result( $base ) );
		$base['codes']                      = array( 'applied' );
		$base['capabilities']['canRestore'] = false;
		$this->assertSame( 'apply-review-required', $this->admin_provider()->classify_apply_result( $base ) );
		$base['codes'][] = 'applied_cutover_invalid';
		$this->assertSame( 'apply-review-required', $this->admin_provider()->classify_apply_result( $base ) );
		$base['journal']['state'] = Migration_Journal::STATE_APPLY_ROLLED_BACK;
		$base['codes']            = array( 'apply_rolled_back' );
		$base['deckMode']         = 'legacy';
		$base['content']          = array( 'classification' => 'original' );
		$base['source']           = array(
			'precondition' => 'match',
			'retained'     => 'match',
		);
		$base['backup']           = array(
			'state'    => 'verified',
			'revision' => 'verified',
		);
		$this->assertSame( 'apply-rolled-back', $this->admin_provider()->classify_apply_result( $base ) );
		$base['deckMode'] = 'native';
		$this->assertSame( 'apply-review-required', $this->admin_provider()->classify_apply_result( $base ) );
		$base['journal']['state'] = Migration_Journal::STATE_RECOVERY_REQUIRED;
		$this->assertSame( 'recovery-required', $this->admin_provider()->classify_apply_result( $base ) );
		$base['journal']['state'] = Migration_Journal::STATE_APPLY_PREPARED;
		$base['codes']            = array( 'apply_interrupted_after_cutover', 'lock_unavailable' );
		$this->assertSame( 'apply-review-required', $this->admin_provider()->classify_apply_result( $base ) );
		$base['codes']    = array( 'lock_lost_recovery_unrecorded' );
		$base['deckMode'] = 'legacy';
		$base['content']  = array( 'classification' => 'original' );
		$this->assertSame( 'apply-review-required', $this->admin_provider()->classify_apply_result( $base ) );
		$base['codes'] = array( 'lock_unavailable' );
		$this->assertSame( 'apply-failed', $this->admin_provider()->classify_apply_result( $base ) );
		$base['source']['retained'] = 'changed';
		$this->assertSame( 'apply-review-required', $this->admin_provider()->classify_apply_result( $base ) );
	}

	/** Mutation forms retain a bounded originating inventory page. */
	public function test_apply_form_preserves_bounded_inventory_page(): void {
		for ( $index = 0; $index < 21; ++$index ) {
			$this->create_ready_deck( array( 'post_title' => 'Paged migration ' . $index ) );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['paged'] = '2';

		ob_start();
		$this->admin_provider()->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="return_page" value="2"', $output );
		$this->assertStringContainsString( 'Paged migration 20', $output );
	}

	/** Rendering is bounded, content-free, nonce-scoped, and zero-write. */
	public function test_admin_page_renders_safe_prepare_action_without_writes(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Migration admin fixture',
				'post_content' => '',
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
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$before = $this->state_fingerprint( $post_id );
		ob_start();
		$this->admin_provider()->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Migration admin fixture', $output );
		$this->assertStringContainsString( 'name="action" value="' . Migration_Admin::PREPARE_ACTION . '"', $output );
		$this->assertStringContainsString( 'name="post_id" value="' . $post_id . '"', $output );
		$this->assertStringNotContainsString( 'Private slide title sentinel', $output );
		$this->assertStringNotContainsString( 'Private slide content sentinel', $output );
		$this->assertSame( $before, $this->state_fingerprint( $post_id ) );

		$this->assertSame( 1, preg_match( '/name="_wpnonce" value="([^"]+)"/', $output, $matches ) );
		$this->assertSame( 1, wp_verify_nonce( $matches[1], Migration_Admin::PREPARE_ACTION . ':' . $post_id ) );
		$this->assertFalse( wp_verify_nonce( $matches[1], Migration_Admin::PREPARE_ACTION . ':' . ( $post_id + 1 ) ) );
	}

	/** Artifact-bearing status renders no authored, integrity, or lock secrets. */
	public function test_admin_page_redacts_prepared_artifacts_and_active_lock(): void {
		$post_id = $this->create_ready_deck(
			array(
				'post_title'    => 'Visible migration identity',
				'post_excerpt'  => 'Private excerpt sentinel',
				'post_password' => 'private-password-sentinel',
			)
		);
		add_post_meta( $post_id, '_presenter-short-url', 'https://private-url-sentinel.test/' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->set_valid_prepare_request( $post_id );
		$this->assertSame( 'prepared', $this->admin_provider()->process_prepare_request() );
		$lock   = new Migration_Lock();
		$handle = $lock->acquire( $post_id );
		$this->assertNotNull( $handle );

		try {
			$before          = $this->state_fingerprint( $post_id );
			$artifact_values = $this->private_artifact_values( $post_id );
			$revision_id     = $this->prepared_revision_id( $post_id );
			$_POST           = array();
			ob_start();
			$this->admin_provider()->render_page();
			$output = (string) ob_get_clean();

			$this->assertStringContainsString( 'Visible migration identity', $output );
			$this->assertStringContainsString( 'Prepared', $output );
			$this->assertStringContainsString( 'Migration locked', $output );
			foreach (
				array_merge(
					array(
						'Private slide title sentinel',
						'Private slide content sentinel',
						'Private excerpt sentinel',
						'private-password-sentinel',
						'private-url-sentinel',
						$handle->token(),
						'backupId',
						'preconditionHash',
						'revisionId',
					),
					$artifact_values
				) as $private_value
			) {
				$this->assertStringNotContainsString( $private_value, $output );
			}
			$this->assertStringNotContainsString( 'post=' . $revision_id . '&#038;', $output );
			$this->assertSame( 0, preg_match( '/\b[a-f0-9]{64}\b/i', $output ) );
			$this->assertSame( 0, preg_match( '/\b[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\b/i', $output ) );
			$this->assertSame( $before, $this->state_fingerprint( $post_id ) );
		} finally {
			$this->assertTrue( $lock->release( $handle ) );
		}
	}

	/** An authorized valid request prepares exactly one deck idempotently. */
	public function test_valid_prepare_request_is_one_deck_bounded_and_idempotent(): void {
		$post_id       = $this->create_ready_deck();
		$neighbor_id   = $this->create_ready_deck( array( 'post_title' => 'Neighbor sentinel' ) );
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator );
		( new Migration_Secret() )->get_or_create();

		$neighbor_before = $this->state_fingerprint( $neighbor_id );
		$this->set_valid_prepare_request( $post_id );
		$this->assertSame( 'prepared', $this->admin_provider()->process_prepare_request() );
		$prepared = $this->artifact_counts( $post_id );

		$this->assertSame( 1, $prepared['backups'] );
		$this->assertSame( 1, $prepared['events'] );
		$this->assertSame( 1, $prepared['secret'] );
		$this->assertSame( $neighbor_before, $this->state_fingerprint( $neighbor_id ) );
		$status = $this->migration_status( $post_id );
		$this->assertSame( Migration_Journal::STATE_APPLY_PREPARED, $status['journal']['state'] );
		$this->assertTrue( $status['capabilities']['canApply'] );
		$prepared_state = $this->state_fingerprint( $post_id );

		$this->set_valid_prepare_request( $post_id );
		$this->assertSame( 'prepared', $this->admin_provider()->process_prepare_request() );
		$this->assertSame( $prepared, $this->artifact_counts( $post_id ) );
		$this->assertSame( $prepared_state, $this->state_fingerprint( $post_id ) );
		$this->assertSame( $neighbor_before, $this->state_fingerprint( $neighbor_id ) );
	}

	/** Pagination clamps huge requested pages before calculating the SQL offset. */
	public function test_admin_page_clamps_unbounded_page_input(): void {
		$this->create_ready_deck();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['paged'] = (string) PHP_INT_MAX;

		ob_start();
		$this->admin_provider()->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Presenter Migration', $output );
	}

	/** A GET request can never cross the preparation mutation boundary. */
	public function test_prepare_handler_rejects_non_post_requests_without_writes(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data( array( 'post_status' => 'publish' ) );
		$this->add_legacy_slide_fixture( $post_id );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST['post_id']          = (string) $post_id;

		$before = $this->artifact_counts( $post_id );
		try {
			$this->admin_provider()->process_prepare_request();
			$this->fail( 'A GET preparation request must terminate.' );
		} catch ( WPDieException $exception ) {
			$this->assertStringContainsString( 'POST request', $exception->getMessage() );
		}

		$this->assertSame( $before, $this->artifact_counts( $post_id ) );
	}

	/** A POST from a user without migration access is rejected before nonce use. */
	public function test_prepare_handler_rejects_unauthorized_users_without_writes(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_status'  => 'publish',
				'post_content' => '',
			)
		);
		$this->add_legacy_slide_fixture( $post_id );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['post_id']          = (string) $post_id;

		$before = $this->artifact_counts( $post_id );
		try {
			$this->admin_provider()->process_prepare_request();
			$this->fail( 'An unauthorized preparation request must terminate.' );
		} catch ( WPDieException $exception ) {
			$this->assertStringContainsString( 'not allowed', $exception->getMessage() );
		}

		$this->assertSame( $before, $this->artifact_counts( $post_id ) );
	}

	/** A mismatched per-post nonce cannot create migration artifacts. */
	public function test_prepare_handler_rejects_invalid_nonce_without_writes(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_status'  => 'publish',
				'post_content' => '',
			)
		);
		$this->add_legacy_slide_fixture( $post_id );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'post_id'  => (string) $post_id,
			'_wpnonce' => wp_create_nonce( Migration_Admin::PREPARE_ACTION . ':' . ( $post_id + 1 ) ),
		);
		$_REQUEST                  = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Constructs a deliberately invalid nonce request.

		$before = $this->artifact_counts( $post_id );
		try {
			$this->admin_provider()->process_prepare_request();
			$this->fail( 'A preparation request with a mismatched nonce must terminate.' );
		} catch ( WPDieException $exception ) {
			$this->assertNotSame( '', $exception->getMessage() );
		}

		$this->assertSame( $before, $this->artifact_counts( $post_id ) );
	}

	/** Screen access does not bypass the target post's edit capability. */
	public function test_prepare_request_requires_per_post_edit_capability(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id   = $this->create_ready_deck( array( 'post_author' => $author_id ) );
		$user_id   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user      = get_user_by( 'id', $user_id );
		$user->add_cap( 'manage_options' );
		wp_set_current_user( $user_id );
		$this->set_valid_prepare_request( $post_id );

		$before = $this->state_fingerprint( $post_id );
		try {
			$this->admin_provider()->process_prepare_request();
			$this->fail( 'Screen capability alone must not authorize a target post.' );
		} catch ( WPDieException $exception ) {
			$this->assertStringContainsString( 'not allowed', $exception->getMessage() );
		}

		$this->assertSame( $before, $this->state_fingerprint( $post_id ) );
	}

	/** A valid nonce cannot prepare an ordinary WordPress post. */
	public function test_prepare_request_rejects_non_slideshow_target(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'Ordinary post sentinel',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_valid_prepare_request( $post_id );

		$before = $this->state_fingerprint( $post_id );
		try {
			$this->admin_provider()->process_prepare_request();
			$this->fail( 'A non-slideshow target must be rejected.' );
		} catch ( WPDieException $exception ) {
			$this->assertStringContainsString( 'not allowed', $exception->getMessage() );
		}

		$this->assertSame( $before, $this->state_fingerprint( $post_id ) );
	}

	/**
	 * Render the migration page and return its HTML.
	 *
	 * @return string Rendered page HTML.
	 */
	private function render_admin_page(): string {
		$_POST    = array();
		$_REQUEST = array();
		ob_start();
		$this->admin_provider()->render_page();

		return (string) ob_get_clean();
	}

	/**
	 * Populate GET with a genuine short-lived result receipt.
	 *
	 * @param string $code    Fixed result code.
	 * @param int    $post_id Slideshow post ID.
	 */
	private function set_result_query( string $code, int $post_id ): void {
		$method = new ReflectionMethod( $this->admin_provider(), 'result_url' );
		$url    = $method->invoke( $this->admin_provider(), $code, $post_id );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Builds a test GET receipt authenticated by the production HMAC verifier.
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $_GET );
	}

	/**
	 * Find the registered provider from the application composition root.
	 *
	 * @return Migration_Admin Registered provider.
	 */
	private function admin_provider(): Migration_Admin {
		$application = presenter_get_runtime();
		$reflection  = new ReflectionProperty( $application, 'hook_providers' );
		$providers   = $reflection->getValue( $application );

		foreach ( $providers as $provider ) {
			if ( $provider instanceof Migration_Admin ) {
				return $provider;
			}
		}

		$this->fail( 'Migration admin provider was not registered.' );
	}

	/**
	 * Count migration artifacts whose absence proves read-only behavior.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return array<string, int> Artifact counts.
	 */
	private function artifact_counts( int $post_id ): array {
		return array(
			'backups' => count( get_post_meta( $post_id, Migration_Backup_Store::META_KEY, false ) ),
			'events'  => count( get_post_meta( $post_id, Migration_Journal::META_KEY, false ) ),
			'secret'  => null === ( new Migration_Secret() )->read() ? 0 : 1,
		);
	}

	/**
	 * Create a losslessly plannable legacy deck with private slide sentinels.
	 *
	 * @param array<string, mixed> $post_data Post fields to override.
	 * @return int Slideshow post ID.
	 */
	private function create_ready_deck( array $post_data = array() ): int {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array_merge(
				array(
					'post_status'  => 'publish',
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
				'title'   => 'Private slide title sentinel',
				'content' => '<p>Private slide content sentinel</p>',
				'class'   => '',
			)
		);

		return $post_id;
	}

	/**
	 * Populate one valid operation-and-post-scoped POST request.
	 *
	 * @param int $post_id Slideshow post ID.
	 */
	private function set_valid_prepare_request( int $post_id ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'post_id'  => (string) $post_id,
			'_wpnonce' => wp_create_nonce( Migration_Admin::PREPARE_ACTION . ':' . $post_id ),
		);
		$_REQUEST                  = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Mirrors the valid test request for check_admin_referer().
	}

	/**
	 * Populate one valid confirmed Apply request.
	 *
	 * @param int $post_id Slideshow post ID.
	 */
	private function set_valid_apply_request( int $post_id ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'post_id'           => (string) $post_id,
			'_wpnonce'          => wp_create_nonce( Migration_Admin::APPLY_ACTION . ':' . $post_id ),
			'presenter_confirm' => 'apply',
		);
		$_REQUEST                  = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Mirrors the valid test request for check_admin_referer().
	}

	/**
	 * Populate one valid confirmed Restore request.
	 *
	 * @param int $post_id Slideshow post ID.
	 */
	private function set_valid_restore_request( int $post_id ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'post_id'           => (string) $post_id,
			'_wpnonce'          => wp_create_nonce( Migration_Admin::RESTORE_ACTION . ':' . $post_id ),
			'presenter_confirm' => 'restore',
		);
		$_REQUEST                  = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Mirrors the valid test request for check_admin_referer().
	}

	/**
	 * Capture an exact keyed representation of all state rendering must preserve.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return string State fingerprint.
	 */
	private function state_fingerprint( int $post_id ): string {
		global $wpdb;

		$state = array(
			'posts'   => $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE ID = %d OR (post_parent = %d AND post_type = %s) ORDER BY ID ASC',
					$wpdb->posts,
					$post_id,
					$post_id,
					'revision'
				),
				ARRAY_A
			),
			'meta'    => $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE post_id = %d OR post_id IN (SELECT ID FROM %i WHERE post_parent = %d AND post_type = %s) ORDER BY meta_id ASC',
					$wpdb->postmeta,
					$post_id,
					$wpdb->posts,
					$post_id,
					'revision'
				),
				ARRAY_A
			),
			'terms'   => $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE object_id = %d ORDER BY term_taxonomy_id ASC', $wpdb->term_relationships, $post_id ),
				ARRAY_A
			),
			'options' => $wpdb->get_results(
				$wpdb->prepare(
					'SELECT option_name, option_value, autoload FROM %i WHERE option_name = %s OR option_name = %s ORDER BY option_name ASC',
					$wpdb->options,
					Migration_Secret::OPTION_NAME,
					'presenter_migration_lock_' . $post_id
				),
				ARRAY_A
			),
		);

		return hash( 'sha256', Migration_Value_Encoder::encode( $state ) );
	}

	/**
	 * Read the content-free status through the application's shared service.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return array<string, mixed> Content-free status.
	 */
	private function migration_status( int $post_id ): array {
		$property = new ReflectionProperty( $this->admin_provider(), 'status' );

		return $property->getValue( $this->admin_provider() )->inspect( $post_id );
	}

	/**
	 * Extract actual private UUID and hash values from stored safety artifacts.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return array<int, string> Private artifact values.
	 */
	private function private_artifact_values( int $post_id ): array {
		$serialized = maybe_serialize(
			array(
				'backups' => get_post_meta( $post_id, Migration_Backup_Store::META_KEY, false ),
				'events'  => get_post_meta( $post_id, Migration_Journal::META_KEY, false ),
			)
		);
		preg_match_all(
			'/\b(?:[a-f0-9]{64}|[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})\b/i',
			$serialized,
			$matches
		);

		return array_values( array_unique( $matches[0] ) );
	}

	/**
	 * Read the private prepared revision ID for a targeted leakage assertion.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return int Revision ID.
	 */
	private function prepared_revision_id( int $post_id ): int {
		$events = get_post_meta( $post_id, Migration_Journal::META_KEY, false );
		$this->assertIsArray( $events[0]['context'] ?? null );

		return (int) $events[0]['context']['revisionId'];
	}
}

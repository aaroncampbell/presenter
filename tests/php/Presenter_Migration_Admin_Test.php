<?php
/**
 * Presenter migration admin boundary tests.
 *
 * @package Presenter
 */

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

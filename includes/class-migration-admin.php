<?php
/**
 * Authenticated Presenter migration administration.
 *
 * @package Presenter
 */

namespace Presenter;

use WP_Post;

/**
 * Provides bounded, authenticated one-deck migration operations.
 */
final class Migration_Admin implements Hook_Provider {
	/** Tools-page slug. */
	public const PAGE_SLUG = 'presenter-migration';

	/** Admin-post action used for one-deck preparation. */
	public const PREPARE_ACTION = 'presenter_migration_prepare';

	/** Authenticated AJAX transport for one client-chained Prepare item. */
	public const BATCH_PREPARE_ACTION = 'presenter_migration_batch_prepare_item';

	/** Authenticated AJAX transport for one client-chained Apply item. */
	public const BATCH_APPLY_ACTION = 'presenter_migration_batch_apply_item';

	/** Admin-post action used for one-deck native cutover. */
	public const APPLY_ACTION = 'presenter_migration_apply';

	/** Admin-post action used for one-deck legacy restoration. */
	public const RESTORE_ACTION = 'presenter_migration_restore';

	/** Number of decks inspected on one screen request. */
	private const PAGE_SIZE = 20;

	/** Capability required to access the migration tool. */
	private const SCREEN_CAPABILITY = 'manage_options';

	/**
	 * Create the admin adapter.
	 *
	 * @param Legacy_Deck_Inventory    $inventory Bounded legacy deck inventory.
	 * @param Migration_Status_Service $status    Zero-write status service.
	 * @param Migration_Preparer       $preparer  Verified preparation service.
	 * @param Migration_Applier        $applier   Verified apply service.
	 * @param Migration_Restorer       $restorer  Verified restore service.
	 * @param Assets                   $assets    Registered plugin assets.
	 */
	public function __construct(
		private Legacy_Deck_Inventory $inventory,
		private Migration_Status_Service $status,
		private Migration_Preparer $preparer,
		private Migration_Applier $applier,
		private Migration_Restorer $restorer,
		private Assets $assets
	) {}

	/** Register admin-only request hooks. */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_post_' . self::PREPARE_ACTION, array( $this, 'handle_prepare' ) );
		add_action( 'wp_ajax_' . self::BATCH_PREPARE_ACTION, array( $this, 'handle_prepare_batch_item' ) );
		add_action( 'admin_post_' . self::APPLY_ACTION, array( $this, 'handle_apply' ) );
		add_action( 'wp_ajax_' . self::BATCH_APPLY_ACTION, array( $this, 'handle_apply_batch_item' ) );
		add_action( 'admin_post_' . self::RESTORE_ACTION, array( $this, 'handle_restore' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Load the batch runner only on the Presenter migration screen.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'tools_page_' . self::PAGE_SLUG === $hook_suffix ) {
			$this->assets->enqueue_admin_migration();
		}
	}

	/** Register the migration screen under Tools. */
	public function register_page(): void {
		add_management_page(
			__( 'Presenter Migration', 'presenter' ),
			__( 'Presenter Migration', 'presenter' ),
			self::SCREEN_CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/** Render one bounded, read-only inventory page. */
	public function render_page(): void {
		if ( ! current_user_can( self::SCREEN_CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Presenter migrations.', 'presenter' ) );
		}

		$total       = $this->inventory->count();
		$total_pages = max( 1, (int) ceil( $total / self::PAGE_SIZE ) );
		$page        = min( $this->requested_page(), $total_pages );
		$post_ids    = $this->inventory->ids( self::PAGE_SIZE, ( $page - 1 ) * self::PAGE_SIZE );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Presenter Migration', 'presenter' ); ?></h1>
			<p><?php esc_html_e( 'Review legacy slideshows, create verified safety artifacts, and explicitly advance one deck at a time.', 'presenter' ); ?></p>
			<?php $this->render_notice(); ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Slideshow', 'presenter' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Plan', 'presenter' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Migration state', 'presenter' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Batch selection', 'presenter' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Available action', 'presenter' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php $batch_counts = $this->render_rows( $post_ids, $page ); ?>
				</tbody>
			</table>
			<?php $this->render_prepare_batch_controls( $batch_counts['prepare'] ); ?>
			<?php $this->render_apply_batch_controls( $batch_counts['apply'] ); ?>
			<?php $this->render_pagination( $page, $total_pages ); ?>
		</div>
		<?php
	}

	/** Handle one explicit, nonce-protected preparation request. */
	public function handle_prepare(): void {
		$code = $this->process_prepare_request();

		wp_safe_redirect( $this->result_url( $code, $this->requested_post_id() ) );
		exit;
	}

	/** Process one client-chained Prepare item and return fixed JSON. */
	public function handle_prepare_batch_item(): void {
		wp_send_json( $this->process_prepare_batch_item() );
	}

	/**
	 * Process one batch item without terminating the request.
	 *
	 * @return array{schemaVersion: int, operation: string, result: string} Batch response.
	 */
	public function process_prepare_batch_item(): array {
		return $this->prepare_batch_payload( $this->process_prepare_request() );
	}

	/** Handle one explicit, nonce-protected native cutover request. */
	public function handle_apply(): void {
		$code = $this->process_apply_request();

		wp_safe_redirect( $this->result_url( $code, $this->requested_post_id() ) );
		exit;
	}

	/** Process one client-chained Apply item and return fixed JSON. */
	public function handle_apply_batch_item(): void {
		wp_send_json( $this->process_apply_batch_item() );
	}

	/**
	 * Process one batch Apply item through the exact one-deck authority.
	 *
	 * @return array{schemaVersion: int, operation: string, result: string} Batch response.
	 */
	public function process_apply_batch_item(): array {
		return $this->apply_batch_payload( $this->process_apply_request() );
	}

	/** Handle one explicit, nonce-protected legacy restoration request. */
	public function handle_restore(): void {
		$code = $this->process_restore_request();

		wp_safe_redirect( $this->result_url( $code, $this->requested_post_id() ) );
		exit;
	}

	/**
	 * Validate and process one preparation request without redirecting or exiting.
	 *
	 * This seam keeps the authenticated request boundary directly testable. The
	 * returned fixed code is the only service result exposed to the redirect.
	 *
	 * @return string Fixed content-free result code.
	 */
	public function process_prepare_request(): string {
		$post_id = $this->validate_mutation_request( self::PREPARE_ACTION );

		$result = $this->preparer->prepare( $post_id );
		$code   = $result['capabilities']['canApply']
			&& ( in_array( 'prepared', $result['codes'], true ) || in_array( 'already_prepared', $result['codes'], true ) )
			? 'prepared'
			: 'prepare-failed';

		return $code;
	}

	/**
	 * Reduce one Prepare result to the complete content-free batch response.
	 *
	 * @param string $code Fixed single-deck result code.
	 * @return array{schemaVersion: int, operation: string, result: string} Batch response.
	 */
	public function prepare_batch_payload( string $code ): array {
		return array(
			'schemaVersion' => 1,
			'operation'     => 'prepare',
			'result'        => 'prepared' === $code ? 'prepared' : 'stopped',
		);
	}

	/**
	 * Validate and process one confirmed native cutover without redirecting.
	 *
	 * @return string Fixed content-free result code.
	 */
	public function process_apply_request(): string {
		$authorization = $this->validate_apply_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The operation-and-post nonce is verified immediately before this exact enum read.
		$confirmation = $_POST['presenter_confirm'] ?? null;
		if ( ! is_string( $confirmation ) || 'apply' !== wp_unslash( $confirmation ) ) {
			wp_die( esc_html__( 'Confirm that you understand this will change the published slideshow.', 'presenter' ), '', array( 'response' => 400 ) );
		}

		return $this->classify_apply_result(
			$this->applier->apply(
				$authorization['postId'],
				$authorization['attemptId'],
				$authorization['sequence']
			)
		);
	}

	/**
	 * Reduce one Apply result to an exact, content-free batch response.
	 *
	 * @param string $code Fixed single-deck result code.
	 * @return array{schemaVersion: int, operation: string, result: string} Batch response.
	 */
	public function apply_batch_payload( string $code ): array {
		$result = match ( $code ) {
			'applied'                => 'applied',
			'applied-warning'        => 'applied-warning',
			'apply-failed',
			'apply-rolled-back'      => 'stopped',
			'apply-review-required',
			'recovery-required'      => 'review-required',
			default                  => 'review-required',
		};

		return array(
			'schemaVersion' => 1,
			'operation'     => 'apply',
			'result'        => $result,
		);
	}

	/**
	 * Classify a content-free apply envelope into a fixed admin result.
	 *
	 * @param array<string, mixed> $result Apply service result.
	 * @return string Fixed result code.
	 */
	public function classify_apply_result( array $result ): string {
		$state = $result['journal']['state'] ?? null;
		$codes = is_array( $result['codes'] ?? null ) ? $result['codes'] : array();

		if ( Migration_Journal::STATE_RECOVERY_REQUIRED === $state ) {
			return 'recovery-required';
		}
		if ( Migration_Journal::STATE_APPLY_ROLLED_BACK === $state ) {
			return in_array( 'apply_rolled_back', $codes, true )
				&& ! in_array( 'lock_release_failed', $codes, true )
				&& $this->proves_verified_legacy( $result )
				? 'apply-rolled-back'
				: 'apply-review-required';
		}
		if ( Migration_Journal::STATE_APPLIED === $state ) {
			$expected_code = in_array( 'applied', $codes, true ) || in_array( 'already_applied', $codes, true );
			$can_restore   = true === ( $result['capabilities']['canRestore'] ?? false );
			$clean_release = ! in_array( 'lock_release_failed', $codes, true );

			if ( $expected_code && $can_restore ) {
				return $clean_release ? 'applied' : 'applied-warning';
			}

			return 'apply-review-required';
		}

		$deck_mode = $result['deckMode'] ?? null;
		$content   = $result['content']['classification'] ?? null;
		$dangerous = array_intersect(
			$codes,
			array(
				'apply_interrupted_before_cutover',
				'apply_interrupted_after_cutover',
				'applied_cutover_missing',
				'applied_cutover_invalid',
				'content_modified',
				'deck_mode_not_native',
				'lock_lost_recovery_unrecorded',
				'recovery_event_failed',
			)
		);
		if ( ! empty( $dangerous ) || 'native' === $deck_mode || 'target' === $content || 'modified' === $content ) {
			return 'apply-review-required';
		}

		return Migration_Journal::STATE_APPLY_PREPARED === $state && $this->proves_verified_legacy( $result )
			? 'apply-failed'
			: 'apply-review-required';
	}

	/**
	 * Validate and process one confirmed legacy restoration without redirecting.
	 *
	 * @return string Fixed content-free result code.
	 */
	public function process_restore_request(): string {
		$post_id = $this->validate_mutation_request( self::RESTORE_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The operation-and-post nonce is verified immediately before this exact enum read.
		$confirmation = $_POST['presenter_confirm'] ?? null;
		if ( ! is_string( $confirmation ) || 'restore' !== wp_unslash( $confirmation ) ) {
			wp_die( esc_html__( 'Confirm that you understand this will restore the legacy slideshow.', 'presenter' ), '', array( 'response' => 400 ) );
		}

		return $this->classify_restore_result( $this->restorer->restore( $post_id ) );
	}

	/**
	 * Classify a content-free restore envelope into a fixed admin result.
	 *
	 * @param array<string, mixed> $result Restore service result.
	 * @return string Fixed result code.
	 */
	public function classify_restore_result( array $result ): string {
		$state = $result['journal']['state'] ?? null;
		$codes = is_array( $result['codes'] ?? null ) ? $result['codes'] : array();

		if ( Migration_Journal::STATE_RECOVERY_REQUIRED === $state ) {
			return 'recovery-required';
		}
		if ( Migration_Journal::STATE_RESTORED === $state ) {
			$expected_code = in_array( 'restored', $codes, true ) || in_array( 'already_restored', $codes, true );
			if ( ! $expected_code || ! $this->proves_verified_legacy( $result ) ) {
				return 'restore-review-required';
			}

			return in_array( 'lock_release_failed', $codes, true ) ? 'restored-warning' : 'restored';
		}
		if ( Migration_Journal::STATE_RESTORE_PREPARED === $state ) {
			return $this->proves_resumable_restore( $result )
				? 'restore-incomplete'
				: 'restore-review-required';
		}
		if (
			Migration_Journal::STATE_APPLIED === $state
			&& true === ( $result['capabilities']['canRestore'] ?? false )
			&& $this->proves_verified_native( $result )
		) {
			return 'restore-failed';
		}

		return 'restore-review-required';
	}

	/**
	 * Prove the exact verified legacy representation required by safe notices.
	 *
	 * @param array<string, mixed> $result Apply service result.
	 * @return bool Whether legacy ownership and safety artifacts remain verified.
	 */
	private function proves_verified_legacy( array $result ): bool {
		return 'legacy' === ( $result['deckMode'] ?? null )
			&& 'original' === ( $result['content']['classification'] ?? null )
			&& 'match' === ( $result['source']['precondition'] ?? null )
			&& 'match' === ( $result['source']['retained'] ?? null )
			&& 'verified' === ( $result['backup']['state'] ?? null )
			&& 'verified' === ( $result['backup']['revision'] ?? null );
	}

	/**
	 * Prove the exact verified native representation required by safe notices.
	 *
	 * @param array<string, mixed> $result Restore service result.
	 * @return bool Whether native ownership and safety artifacts remain verified.
	 */
	private function proves_verified_native( array $result ): bool {
		return 'native' === ( $result['deckMode'] ?? null )
			&& 'target' === ( $result['content']['classification'] ?? null )
			&& 'match' === ( $result['source']['retained'] ?? null )
			&& 'verified' === ( $result['backup']['state'] ?? null )
			&& 'verified' === ( $result['backup']['revision'] ?? null );
	}

	/**
	 * Prove one of the exact representations from which Restore can resume.
	 *
	 * Lock contention may temporarily make canRestore false without making the
	 * persisted representation ambiguous.
	 *
	 * @param array<string, mixed> $result Restore service result.
	 * @return bool Whether the persisted representation is safely resumable.
	 */
	private function proves_resumable_restore( array $result ): bool {
		$deck_mode = $result['deckMode'] ?? null;
		$content   = $result['content']['classification'] ?? null;
		$codes     = is_array( $result['codes'] ?? null ) ? $result['codes'] : array();
		$exact     = ( 'native' === $deck_mode && 'target' === $content )
			|| ( 'legacy' === $deck_mode && in_array( $content, array( 'target', 'original' ), true ) );

		return Migration_Journal::STATE_RESTORE_PREPARED === ( $result['journal']['state'] ?? null )
			&& $exact
			&& 'match' === ( $result['source']['retained'] ?? null )
			&& 'verified' === ( $result['backup']['state'] ?? null )
			&& 'verified' === ( $result['backup']['revision'] ?? null )
			&& empty( array_intersect( $codes, array( 'post_fields_changed', 'restore_cutover_invalid', 'restore_cutover_present_after_content' ) ) );
	}

	/**
	 * Render accessible rows for decks the current user may edit.
	 *
	 * @param array<int, int> $post_ids Legacy slideshow IDs.
	 * @param int             $page     Current inventory page.
	 * @return array{prepare: int, apply: int} Eligible current-page batch counts.
	 */
	private function render_rows( array $post_ids, int $page ): array {
		$rendered      = 0;
		$prepare_count = 0;
		$apply_count   = 0;
		foreach ( $post_ids as $post_id ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$status        = $this->status->inspect( $post_id );
			$journal_state = is_string( $status['journal']['state'] ) ? $status['journal']['state'] : '';
			$can_prepare   = true === $status['capabilities']['canPrepare'];
			$can_apply     = true === $status['capabilities']['canApply'];
			if ( $can_prepare ) {
				++$prepare_count;
			}
			if ( $can_apply ) {
				++$apply_count;
			}
			++$rendered;
			?>
			<tr>
				<th scope="row">
					<a href="<?php echo esc_url( get_edit_post_link( $post_id, 'raw' ) ); ?>">
						<?php echo esc_html( get_the_title( $post ) ? get_the_title( $post ) : __( '(no title)', 'presenter' ) ); ?>
					</a>
					<span aria-hidden="true"> — </span><span class="screen-reader-text"><?php esc_html_e( 'Post ID:', 'presenter' ); ?></span><?php echo esc_html( (string) $post_id ); ?>
				</th>
				<td><?php echo esc_html( $this->plan_label( (string) $status['plan']['state'] ) ); ?></td>
				<td><?php echo esc_html( $this->journal_label( $journal_state ) ); ?></td>
				<td>
					<?php if ( $can_prepare ) : ?>
						<input type="checkbox" data-presenter-prepare-select data-presenter-prepare-form="<?php echo esc_attr( 'presenter-prepare-' . $post_id ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: slideshow title. */ __( 'Select %s for batch preparation', 'presenter' ), get_the_title( $post ) ? get_the_title( $post ) : __( '(no title)', 'presenter' ) ) ); ?>" hidden>
					<?php elseif ( $can_apply ) : ?>
						<input type="checkbox" data-presenter-apply-select data-presenter-apply-form="<?php echo esc_attr( 'presenter-apply-' . $post_id ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: slideshow title. */ __( 'Select %s for batch apply', 'presenter' ), get_the_title( $post ) ? get_the_title( $post ) : __( '(no title)', 'presenter' ) ) ); ?>" hidden>
					<?php else : ?>
						<span aria-hidden="true">—</span><span class="screen-reader-text"><?php esc_html_e( 'Not eligible for batch preparation', 'presenter' ); ?></span>
					<?php endif; ?>
				</td>
				<td><?php $this->render_action( $post_id, $status, $page ); ?></td>
			</tr>
			<?php
		}

		if ( 0 === $rendered ) {
			?>
			<tr><td colspan="5"><?php esc_html_e( 'No editable legacy slideshows were found on this page.', 'presenter' ); ?></td></tr>
			<?php
		}

		return array(
			'prepare' => $prepare_count,
			'apply'   => $apply_count,
		);
	}

	/**
	 * Render progressive-enhancement controls for current-page preparation.
	 *
	 * @param int $prepare_count Number of eligible rows on this page.
	 */
	private function render_prepare_batch_controls( int $prepare_count ): void {
		if ( $prepare_count < 1 ) {
			return;
		}
		?>
		<section class="card" data-presenter-prepare-batch data-endpoint="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-action="<?php echo esc_attr( self::BATCH_PREPARE_ACTION ); ?>" hidden>
			<h2><?php esc_html_e( 'Prepare selected decks', 'presenter' ); ?></h2>
			<p><?php esc_html_e( 'This creates verified safety artifacts only; it does not change published slideshows. The queue is limited to eligible decks on this page and stops at the first issue.', 'presenter' ); ?></p>
			<p>
				<label><input type="checkbox" data-presenter-prepare-select-all> <?php esc_html_e( 'Select every eligible deck on this page', 'presenter' ); ?></label>
			</p>
			<p>
				<button type="button" class="button button-secondary" data-presenter-prepare-start disabled><?php esc_html_e( 'Prepare selected decks', 'presenter' ); ?></button>
				<button type="button" class="button" data-presenter-prepare-stop hidden><?php esc_html_e( 'Stop after current deck', 'presenter' ); ?></button>
			</p>
			<p data-presenter-prepare-progress role="status" aria-live="polite"></p>
		</section>
		<?php
	}

	/**
	 * Render progressive-enhancement controls for current-page native cutover.
	 *
	 * @param int $apply_count Number of eligible rows on this page.
	 */
	private function render_apply_batch_controls( int $apply_count ): void {
		if ( $apply_count < 1 ) {
			return;
		}
		?>
		<section class="card" data-presenter-apply-batch data-endpoint="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-action="<?php echo esc_attr( self::BATCH_APPLY_ACTION ); ?>" hidden>
			<h2><?php esc_html_e( 'Apply selected decks', 'presenter' ); ?></h2>
			<p><?php esc_html_e( 'Each selected slideshow is published independently. Successful decks remain native if a later deck stops, and the queue never restores them automatically.', 'presenter' ); ?></p>
			<p><label><input type="checkbox" data-presenter-apply-select-all> <?php esc_html_e( 'Select every eligible prepared deck on this page', 'presenter' ); ?></label></p>
			<p><label><input type="checkbox" data-presenter-apply-confirm> <?php esc_html_e( 'I understand that this changes the published slideshows.', 'presenter' ); ?></label></p>
			<p>
				<button type="button" class="button button-primary" data-presenter-apply-start disabled><?php esc_html_e( 'Apply native content to selected decks', 'presenter' ); ?></button>
				<button type="button" class="button" data-presenter-apply-stop hidden><?php esc_html_e( 'Stop after current deck', 'presenter' ); ?></button>
			</p>
			<p data-presenter-apply-progress role="status" aria-live="polite"></p>
		</section>
		<?php
	}

	/**
	 * Render the explicit one-deck preparation form when safe.
	 *
	 * @param int                  $post_id Slideshow post ID.
	 * @param array<string, mixed> $status  Content-free migration status.
	 * @param int                  $page    Current inventory page.
	 */
	private function render_action( int $post_id, array $status, int $page ): void {
		if ( $status['capabilities']['canPrepare'] ) {
			$this->render_prepare_form( $post_id, $page );
			return;
		}
		if ( $status['capabilities']['canApply'] ) {
			$this->render_apply_form( $post_id, $page, $status );
			return;
		}
		if ( $status['capabilities']['canRestore'] ) {
			$this->render_restore_form( $post_id, $page, $status );
			return;
		}

		echo esc_html( $this->unavailable_label( $status ) );
	}

	/**
	 * Render the explicit one-deck preparation form.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @param int $page    Current inventory page.
	 */
	private function render_prepare_form( int $post_id, int $page ): void {
		?>
		<form id="<?php echo esc_attr( 'presenter-prepare-' . $post_id ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-presenter-prepare-form>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::PREPARE_ACTION ); ?>">
			<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>">
			<input type="hidden" name="return_page" value="<?php echo esc_attr( (string) $page ); ?>">
			<?php wp_nonce_field( $this->nonce_action( self::PREPARE_ACTION, $post_id ) ); ?>
			<?php submit_button( __( 'Prepare', 'presenter' ), 'secondary small', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Render the explicit, confirmed one-deck native cutover form.
	 *
	 * @param int                  $post_id Slideshow post ID.
	 * @param int                  $page   Current inventory page.
	 * @param array<string, mixed> $status Fresh content-free migration status.
	 */
	private function render_apply_form( int $post_id, int $page, array $status ): void {
		$confirmation_id = 'presenter-confirm-apply-' . $post_id;
		$attempt_id      = (string) $status['journal']['attemptId'];
		$sequence        = (int) $status['journal']['sequence'];
		?>
		<form id="<?php echo esc_attr( 'presenter-apply-' . $post_id ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-presenter-apply-form>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::APPLY_ACTION ); ?>">
			<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>">
			<input type="hidden" name="return_page" value="<?php echo esc_attr( (string) $page ); ?>">
			<?php wp_nonce_field( $this->apply_nonce_action( $post_id, $attempt_id, $sequence ) ); ?>
			<p><?php esc_html_e( 'This replaces the active post content with verified block content and switches the public slideshow to the native renderer. The legacy metadata, verified backup, and revision are retained.', 'presenter' ); ?></p>
			<label for="<?php echo esc_attr( $confirmation_id ); ?>">
				<input id="<?php echo esc_attr( $confirmation_id ); ?>" type="checkbox" name="presenter_confirm" value="apply" required>
				<?php esc_html_e( 'I understand that this changes the published slideshow.', 'presenter' ); ?>
			</label>
			<?php submit_button( __( 'Apply native content', 'presenter' ), 'primary small', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Render the explicit, confirmed one-deck legacy restoration form.
	 *
	 * @param int                  $post_id Slideshow post ID.
	 * @param int                  $page    Current inventory page.
	 * @param array<string, mixed> $status Content-free migration status.
	 */
	private function render_restore_form( int $post_id, int $page, array $status ): void {
		$confirmation_id = 'presenter-confirm-restore-' . $post_id;
		$resuming        = Migration_Journal::STATE_RESTORE_PREPARED === $status['journal']['state'];
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-presenter-restore-form>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::RESTORE_ACTION ); ?>">
			<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>">
			<input type="hidden" name="return_page" value="<?php echo esc_attr( (string) $page ); ?>">
			<?php wp_nonce_field( $this->nonce_action( self::RESTORE_ACTION, $post_id ) ); ?>
			<p><?php echo esc_html( $resuming ? __( 'A previous restore stopped at a verified resumable point. This completes the exact legacy content and routing restoration.', 'presenter' ) : __( 'This replaces the verified native block content with the exact pre-migration legacy content and returns public rendering to the legacy renderer. Migration safety artifacts remain available.', 'presenter' ) ); ?></p>
			<label for="<?php echo esc_attr( $confirmation_id ); ?>">
				<input id="<?php echo esc_attr( $confirmation_id ); ?>" type="checkbox" name="presenter_confirm" value="restore" required>
				<?php esc_html_e( 'I understand that this switches the slideshow back to the legacy renderer.', 'presenter' ); ?>
			</label>
			<?php submit_button( $resuming ? __( 'Resume restore', 'presenter' ) : __( 'Restore legacy content', 'presenter' ), 'secondary small', 'submit', false ); ?>
		</form>
		<?php
	}

	/** Render a fixed, content-free result notice. */
	private function render_notice(): void {
		$result = $this->verified_notice_result();
		if ( 'prepared' === $result ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'The slideshow safety artifacts are verified and ready to apply.', 'presenter' ) );
		} elseif ( 'prepare-failed' === $result ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'Presenter could not safely prepare that slideshow. Resolve any edits, locks, or migration-state issues before retrying.', 'presenter' ) );
		} elseif ( 'applied' === $result ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'The verified native content is active. Legacy metadata, backup, and revision were retained.', 'presenter' ) );
		} elseif ( 'applied-warning' === $result ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html__( 'Native content is active, but migration verification or lock cleanup needs attention before another operation.', 'presenter' ) );
		} elseif ( 'apply-review-required' === $result ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'Presenter could not conclusively classify the active representation. Do not retry or edit this slideshow until its migration state is manually reviewed.', 'presenter' ) );
		} elseif ( 'apply-rolled-back' === $result ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html__( 'Presenter could not complete the cutover and safely restored the legacy representation.', 'presenter' ) );
		} elseif ( 'recovery-required' === $result ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'Presenter could not prove a safe representation. Do not retry or edit this slideshow until its migration state is manually reviewed.', 'presenter' ) );
		} elseif ( 'apply-failed' === $result ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'Presenter did not activate native content. Resolve the reported migration state before retrying.', 'presenter' ) );
		} elseif ( 'restored' === $result ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'The exact verified legacy content and renderer are active again. Migration artifacts were retained.', 'presenter' ) );
		} elseif ( 'restored-warning' === $result ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html__( 'The verified legacy representation is active, but migration lock cleanup needs manual attention.', 'presenter' ) );
		} elseif ( 'restore-failed' === $result ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'Presenter did not complete the restore. The verified native representation remains active; resolve contention before retrying.', 'presenter' ) );
		} elseif ( 'restore-incomplete' === $result ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html__( 'Restore paused at a verified resumable point. Do not edit the slideshow; use Resume restore after contention clears.', 'presenter' ) );
		} elseif ( 'restore-review-required' === $result ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'Presenter could not conclusively classify the active representation during restore. Do not retry or edit this slideshow until its migration state is manually reviewed.', 'presenter' ) );
		} elseif ( 'migration-review-required' === $result ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'The migration state changed before this notice could be verified. Review the current slideshow state before another operation.', 'presenter' ) );
		} elseif ( 'migration-result-review' === $result ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'Presenter could not verify a conclusive current-state result from this receipt. Review the slideshow state before another operation.', 'presenter' ) );
		}
	}

	/**
	 * Validate a short-lived signed result and recheck its persisted state.
	 *
	 * @return string Verified fixed result code, or empty when no receipt exists.
	 */
	private function verified_notice_result(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This read-only receipt is authenticated by its user-bound HMAC below.
		$query = wp_unslash( $_GET );
		if (
			! isset( $query['presenter-result'], $query['presenter-post'], $query['presenter-exp'], $query['presenter-sig'] )
			|| ! is_string( $query['presenter-result'] )
			|| ! is_string( $query['presenter-post'] )
			|| ! is_string( $query['presenter-exp'] )
			|| ! is_string( $query['presenter-sig'] )
		) {
			return '';
		}

		$code    = sanitize_key( $query['presenter-result'] );
		$post_id = absint( $query['presenter-post'] );
		$expires = absint( $query['presenter-exp'] );
		$now     = time();
		if (
			$code !== $query['presenter-result']
			|| (string) $post_id !== $query['presenter-post']
			|| (string) $expires !== $query['presenter-exp']
			|| $post_id < 1
			|| $expires < $now
			|| $expires > $now + 300
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $query['presenter-sig'] )
			|| ! hash_equals( $this->result_signature( $code, $post_id, $expires ), $query['presenter-sig'] )
		) {
			return '';
		}

		$status = $this->status->inspect( $post_id );

		return $this->notice_result_for_status( $code, $status );
	}

	/**
	 * Canonicalize a signed notice against fresh persisted migration state.
	 *
	 * @param string               $code   Fixed result code.
	 * @param array<string, mixed> $status Fresh content-free status.
	 * @return string Truthful fixed notice code.
	 */
	private function notice_result_for_status( string $code, array $status ): string {
		$state = $status['journal']['state'] ?? null;
		$lock  = $status['lock']['state'] ?? null;
		$match = match ( $code ) {
			'prepared'          => Migration_Journal::STATE_APPLY_PREPARED === $state && true === ( $status['capabilities']['canApply'] ?? false ),
			'applied'           => Migration_Journal::STATE_APPLIED === $state && true === ( $status['capabilities']['canRestore'] ?? false ) && $this->proves_verified_native( $status ),
			'apply-rolled-back' => Migration_Journal::STATE_APPLY_ROLLED_BACK === $state && $this->proves_verified_legacy( $status ),
			'apply-failed'      => Migration_Journal::STATE_APPLY_PREPARED === $state && $this->proves_verified_legacy( $status ),
			'recovery-required' => Migration_Journal::STATE_RECOVERY_REQUIRED === $state,
			'restored'          => Migration_Journal::STATE_RESTORED === $state && $this->proves_verified_legacy( $status ),
			'restore-incomplete' => $this->proves_resumable_restore( $status ),
			'restore-failed'    => Migration_Journal::STATE_APPLIED === $state && $this->proves_verified_native( $status ),
			default             => false,
		};

		if ( $match ) {
			return $code;
		}
		if ( 'applied-warning' === $code && Migration_Journal::STATE_APPLIED === $state && $this->proves_verified_native( $status ) ) {
			return in_array( $lock, array( 'unlocked', 'expired' ), true )
				? 'applied'
				: 'applied-warning';
		}
		if ( 'restored-warning' === $code && Migration_Journal::STATE_RESTORED === $state && $this->proves_verified_legacy( $status ) ) {
			return in_array( $lock, array( 'unlocked', 'expired' ), true )
				? 'restored'
				: 'restored-warning';
		}
		if ( 'prepare-failed' === $code && in_array( $state, array( null, '', Migration_Journal::STATE_APPLY_PREPARED ), true ) ) {
			return true === ( $status['capabilities']['canApply'] ?? false ) ? 'migration-review-required' : 'prepare-failed';
		}
		if ( in_array( $code, array( 'apply-review-required', 'restore-review-required' ), true ) ) {
			return 'migration-result-review';
		}

		return 'migration-review-required';
	}

	/**
	 * Render bounded pagination links.
	 *
	 * @param int $page        Current one-based page.
	 * @param int $total_pages Total inventory pages.
	 */
	private function render_pagination( int $page, int $total_pages ): void {
		if ( $total_pages < 2 ) {
			return;
		}

		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%', $this->page_url() ),
				'current'   => $page,
				'total'     => $total_pages,
				'prev_text' => __( 'Previous', 'presenter' ),
				'next_text' => __( 'Next', 'presenter' ),
			)
		);
		if ( '' !== $links ) {
			printf( '<nav class="tablenav-pages" aria-label="%1$s">%2$s</nav>', esc_attr__( 'Presenter migration pages', 'presenter' ), wp_kses_post( $links ) );
		}
	}

	/** Get and bound the requested inventory page. */
	private function requested_page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only inventory pagination.
		$page = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1;

		return max( 1, $page );
	}

	/** Validate the exact explicit slideshow ID from POST data. */
	private function requested_post_id(): int {
		// The post ID is needed to select the exact nonce action verified immediately afterwards.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$value   = isset( $_POST['post_id'] ) ? sanitize_text_field( wp_unslash( $_POST['post_id'] ) ) : '';
		$post_id = absint( $value );
		if ( $post_id < 1 || (string) $post_id !== $value ) {
			wp_die( esc_html__( 'A valid slideshow post ID is required.', 'presenter' ), '', array( 'response' => 400 ) );
		}

		return $post_id;
	}

	/**
	 * Build the exact per-operation, per-post nonce action.
	 *
	 * @param string $action  Exact mutation action.
	 * @param int    $post_id Slideshow post ID.
	 * @return string Nonce action.
	 */
	private function nonce_action( string $action, int $post_id ): string {
		return $action . ':' . $post_id;
	}

	/**
	 * Build an Apply nonce bound to one verified preparation attempt.
	 *
	 * @param int    $post_id    Slideshow post ID.
	 * @param string $attempt_id Verified migration attempt UUID.
	 * @param int    $sequence   Verified prepared journal sequence.
	 * @return string Nonce action.
	 */
	private function apply_nonce_action( int $post_id, string $attempt_id, int $sequence ): string {
		return implode( ':', array( self::APPLY_ACTION, (string) $post_id, $attempt_id, (string) $sequence ) );
	}

	/**
	 * Validate an Apply request against the exact rendered preparation attempt.
	 *
	 * The same authorization may be replayed only after that exact attempt has
	 * reached Applied, where the applier's existing idempotent path is safe.
	 *
	 * @return array{postId: int, attemptId: string, sequence: int} Locked-service authorization.
	 */
	private function validate_apply_request(): array {
		if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			wp_die( esc_html__( 'Presenter migration changes require a POST request.', 'presenter' ), '', array( 'response' => 405 ) );
		}
		if ( ! current_user_can( self::SCREEN_CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Presenter migrations.', 'presenter' ), '', array( 'response' => 403 ) );
		}

		$post_id = $this->requested_post_id();
		$post    = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'slideshow' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to migrate this slideshow.', 'presenter' ), '', array( 'response' => 403 ) );
		}

		$status           = $this->status->inspect( $post_id );
		$current_attempt  = $status['journal']['attemptId'] ?? null;
		$current_sequence = $status['journal']['sequence'] ?? null;
		$current_state    = $status['journal']['state'] ?? null;
		if ( ! is_string( $current_attempt ) || ! wp_is_uuid( $current_attempt, 4 ) || ! is_int( $current_sequence ) ) {
			wp_die( esc_html__( 'This Apply authorization no longer matches the current prepared migration.', 'presenter' ), '', array( 'response' => 409 ) );
		}
		$authorized_sequence = Migration_Journal::STATE_APPLY_PREPARED === $current_state
			? $current_sequence
			: ( Migration_Journal::STATE_APPLIED === $current_state ? $current_sequence - 1 : 0 );
		if ( $authorized_sequence < 1 ) {
			wp_die( esc_html__( 'This Apply authorization no longer matches the current prepared migration.', 'presenter' ), '', array( 'response' => 409 ) );
		}
		check_admin_referer( $this->apply_nonce_action( $post_id, $current_attempt, $authorized_sequence ) );

		return array(
			'postId'    => $post_id,
			'attemptId' => $current_attempt,
			'sequence'  => $authorized_sequence,
		);
	}

	/**
	 * Validate the request method, capabilities, target, and scoped nonce.
	 *
	 * @param string $action Exact mutation action.
	 * @return int Authorized slideshow ID.
	 */
	private function validate_mutation_request( string $action ): int {
		if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			wp_die( esc_html__( 'Presenter migration changes require a POST request.', 'presenter' ), '', array( 'response' => 405 ) );
		}
		if ( ! current_user_can( self::SCREEN_CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Presenter migrations.', 'presenter' ), '', array( 'response' => 403 ) );
		}

		$post_id = $this->requested_post_id();
		check_admin_referer( $this->nonce_action( $action, $post_id ) );

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'slideshow' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to migrate this slideshow.', 'presenter' ), '', array( 'response' => 403 ) );
		}

		return $post_id;
	}

	/**
	 * Map the plan enum to an operator-facing localized label.
	 *
	 * @param string $state Plan state.
	 * @return string Localized label.
	 */
	private function plan_label( string $state ): string {
		return match ( $state ) {
			'ready'      => __( 'Ready', 'presenter' ),
			'blocked'    => __( 'Needs review', 'presenter' ),
			'ineligible' => __( 'Not eligible', 'presenter' ),
			default      => __( 'Unknown', 'presenter' ),
		};
	}

	/**
	 * Map the journal enum to an operator-facing localized label.
	 *
	 * @param string $state Journal state.
	 * @return string Localized label.
	 */
	private function journal_label( string $state ): string {
		return match ( $state ) {
			''                                        => __( 'Not prepared', 'presenter' ),
			Migration_Journal::STATE_APPLY_PREPARED   => __( 'Prepared', 'presenter' ),
			Migration_Journal::STATE_APPLIED          => __( 'Applied', 'presenter' ),
			Migration_Journal::STATE_APPLY_ROLLED_BACK => __( 'Apply rolled back', 'presenter' ),
			Migration_Journal::STATE_RESTORE_PREPARED => __( 'Restore in progress', 'presenter' ),
			Migration_Journal::STATE_RESTORED         => __( 'Restored', 'presenter' ),
			Migration_Journal::STATE_RECOVERY_REQUIRED => __( 'Manual recovery required', 'presenter' ),
			default                                   => __( 'Unknown', 'presenter' ),
		};
	}

	/**
	 * Explain why the prepare action is unavailable without exposing codes.
	 *
	 * @param array<string, mixed> $status Content-free migration status.
	 * @return string Localized explanation.
	 */
	private function unavailable_label( array $status ): string {
		if ( 'blocked' === $status['plan']['state'] ) {
			return __( 'Review required', 'presenter' );
		}
		if ( Migration_Journal::STATE_RECOVERY_REQUIRED === $status['journal']['state'] ) {
			return __( 'Manual recovery required', 'presenter' );
		}
		if ( 'active' === $status['lock']['state'] ) {
			return __( 'Migration locked', 'presenter' );
		}
		if ( Migration_Journal::STATE_APPLIED === $status['journal']['state'] ) {
			return __( 'Review required', 'presenter' );
		}
		if ( Migration_Journal::STATE_RESTORE_PREPARED === $status['journal']['state'] ) {
			return __( 'Review required', 'presenter' );
		}

		return __( 'Not currently available', 'presenter' );
	}

	/**
	 * Build a safe URL back to the migration page.
	 *
	 * @param array<string, string> $args Optional query values.
	 * @return string Admin page URL.
	 */
	private function page_url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'tools.php?page=' . self::PAGE_SLUG ) );
	}

	/**
	 * Sign one short-lived result for the current administrator.
	 *
	 * @param string $code    Fixed content-free result code.
	 * @param int    $post_id Slideshow post ID.
	 * @param int    $expires Receipt expiry timestamp.
	 * @return string Receipt HMAC.
	 */
	private function result_signature( string $code, int $post_id, int $expires ): string {
		$payload = implode( '|', array( $code, (string) $post_id, (string) $expires, (string) get_current_user_id() ) );

		return hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) );
	}

	/**
	 * Build the bounded redirect URL for a fixed result code.
	 *
	 * @param string $code    Fixed content-free result code.
	 * @param int    $post_id Slideshow post ID.
	 * @return string Bounded admin redirect URL.
	 */
	private function result_url( string $code, int $post_id ): string {
		$expires = time() + 300;
		$args    = array(
			'presenter-result' => $code,
			'presenter-post'   => (string) $post_id,
			'presenter-exp'    => (string) $expires,
			'presenter-sig'    => $this->result_signature( $code, $post_id, $expires ),
		);
		$page    = $this->requested_return_page();
		if ( 1 < $page ) {
			$args['paged'] = (string) $page;
		}

		return $this->page_url( $args );
	}

	/** Read and clamp the nonce-protected originating inventory page. */
	private function requested_return_page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called only after the mutation processor verifies the scoped nonce.
		$value = isset( $_POST['return_page'] ) && is_string( $_POST['return_page'] ) ? wp_unslash( $_POST['return_page'] ) : '1';
		$page  = absint( $value );
		if ( $page < 1 || (string) $page !== $value ) {
			return 1;
		}

		$total_pages = max( 1, (int) ceil( $this->inventory->count() / self::PAGE_SIZE ) );

		return min( $page, $total_pages );
	}
}

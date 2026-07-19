<?php
/**
 * Authenticated Presenter migration administration.
 *
 * @package Presenter
 */

namespace Presenter;

use WP_Post;

/**
 * Provides a bounded, server-rendered migration screen and one-deck preparation.
 */
final class Migration_Admin implements Hook_Provider {
	/** Tools-page slug. */
	public const PAGE_SLUG = 'presenter-migration';

	/** Admin-post action used for one-deck preparation. */
	public const PREPARE_ACTION = 'presenter_migration_prepare';

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
	 */
	public function __construct(
		private Legacy_Deck_Inventory $inventory,
		private Migration_Status_Service $status,
		private Migration_Preparer $preparer
	) {}

	/** Register admin-only request hooks. */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_post_' . self::PREPARE_ACTION, array( $this, 'handle_prepare' ) );
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
			<p><?php esc_html_e( 'Review legacy slideshows and create verified safety artifacts before any native-content cutover.', 'presenter' ); ?></p>
			<?php $this->render_notice(); ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Slideshow', 'presenter' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Plan', 'presenter' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Migration state', 'presenter' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Available action', 'presenter' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php $this->render_rows( $post_ids ); ?>
				</tbody>
			</table>
			<?php $this->render_pagination( $page, $total_pages ); ?>
		</div>
		<?php
	}

	/** Handle one explicit, nonce-protected preparation request. */
	public function handle_prepare(): void {
		$code = $this->process_prepare_request();

		wp_safe_redirect( $this->page_url( array( 'presenter-result' => $code ) ) );
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
		if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			wp_die( esc_html__( 'Presenter migration changes require a POST request.', 'presenter' ), '', array( 'response' => 405 ) );
		}
		if ( ! current_user_can( self::SCREEN_CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Presenter migrations.', 'presenter' ), '', array( 'response' => 403 ) );
		}

		$post_id = $this->requested_post_id();
		check_admin_referer( $this->nonce_action( $post_id ) );

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'slideshow' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to prepare this slideshow.', 'presenter' ), '', array( 'response' => 403 ) );
		}

		$result = $this->preparer->prepare( $post_id );
		$code   = $result['capabilities']['canApply']
			&& ( in_array( 'prepared', $result['codes'], true ) || in_array( 'already_prepared', $result['codes'], true ) )
			? 'prepared'
			: 'prepare-failed';

		return $code;
	}

	/**
	 * Render accessible rows for decks the current user may edit.
	 *
	 * @param array<int, int> $post_ids Legacy slideshow IDs.
	 */
	private function render_rows( array $post_ids ): void {
		$rendered = 0;
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
				<td><?php $this->render_prepare_form( $post_id, $status ); ?></td>
			</tr>
			<?php
		}

		if ( 0 === $rendered ) {
			?>
			<tr><td colspan="4"><?php esc_html_e( 'No editable legacy slideshows were found on this page.', 'presenter' ); ?></td></tr>
			<?php
		}
	}

	/**
	 * Render the explicit one-deck preparation form when safe.
	 *
	 * @param int                  $post_id Slideshow post ID.
	 * @param array<string, mixed> $status  Content-free migration status.
	 */
	private function render_prepare_form( int $post_id, array $status ): void {
		if ( ! $status['capabilities']['canPrepare'] ) {
			echo esc_html( $this->unavailable_label( $status ) );
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::PREPARE_ACTION ); ?>">
			<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>">
			<?php wp_nonce_field( $this->nonce_action( $post_id ) ); ?>
			<?php submit_button( __( 'Prepare', 'presenter' ), 'secondary small', 'submit', false ); ?>
		</form>
		<?php
	}

	/** Render a fixed, content-free result notice. */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only fixed notice selection.
		$result = isset( $_GET['presenter-result'] ) ? sanitize_key( wp_unslash( $_GET['presenter-result'] ) ) : '';
		if ( 'prepared' === $result ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'The slideshow safety artifacts are verified and ready to apply.', 'presenter' ) );
		} elseif ( 'prepare-failed' === $result ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'Presenter could not safely prepare that slideshow. Resolve any edits, locks, or migration-state issues before retrying.', 'presenter' ) );
		}
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
	 * @param int $post_id Slideshow post ID.
	 * @return string Nonce action.
	 */
	private function nonce_action( int $post_id ): string {
		return self::PREPARE_ACTION . ':' . $post_id;
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
			return __( 'Already applied', 'presenter' );
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
}

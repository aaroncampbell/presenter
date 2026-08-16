<?php
/**
 * Explicit administration for legacy Presenter HTML trust.
 *
 * @package Presenter
 */

namespace Presenter;

use WP_Post;

/**
 * Lets authorized administrators inventory and trust exact legacy slide sets.
 */
final class Legacy_HTML_Trust_Admin implements Hook_Provider {
	/** Tools-page slug. */
	public const PAGE_SLUG = 'presenter-legacy-html-trust';

	/** Admin-post action for explicit legacy HTML trust changes. */
	public const TRUST_ACTION = 'presenter_legacy_html_trust';

	/** Number of decks displayed or manually selected on one page. */
	private const PAGE_SIZE = 20;

	/**
	 * Bounded legacy inventory.
	 *
	 * @var Legacy_Deck_Inventory
	 */
	private Legacy_Deck_Inventory $inventory;

	/**
	 * Read-only legacy slide source.
	 *
	 * @var Legacy_Slide_Source
	 */
	private Legacy_Slide_Source $slides;

	/**
	 * Content-bound trust policy.
	 *
	 * @var Legacy_HTML_Trust
	 */
	private Legacy_HTML_Trust $trust;

	/**
	 * Authoritative deck-mode resolver.
	 *
	 * @var Deck_Mode
	 */
	private Deck_Mode $deck_mode;

	/**
	 * Create the trust administration adapter.
	 *
	 * @param Legacy_Deck_Inventory $inventory Bounded legacy deck inventory.
	 * @param Legacy_Slide_Source   $slides    Read-only legacy slide source.
	 * @param Legacy_HTML_Trust     $trust     Content-bound trust policy.
	 * @param Deck_Mode             $deck_mode Authoritative deck-mode resolver.
	 */
	public function __construct(
		Legacy_Deck_Inventory $inventory,
		Legacy_Slide_Source $slides,
		Legacy_HTML_Trust $trust,
		Deck_Mode $deck_mode
	) {
		$this->inventory = $inventory;
		$this->slides    = $slides;
		$this->trust     = $trust;
		$this->deck_mode = $deck_mode;
	}

	/** Register admin-only request hooks. */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_post_' . self::TRUST_ACTION, array( $this, 'handle_trust' ) );
	}

	/** Register the explicit trust screen under Tools. */
	public function register_page(): void {
		add_management_page(
			__( 'Presenter Legacy HTML Trust', 'presenter' ),
			__( 'Presenter HTML Trust', 'presenter' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/** Render one content-free, paginated trust inventory page. */
	public function render_page(): void {
		$this->require_screen_capabilities();

		$records      = $this->inventory_records();
		$total        = count( $records );
		$total_pages  = max( 1, (int) ceil( $total / self::PAGE_SIZE ) );
		$page         = min( $this->requested_page(), $total_pages );
		$page_rows    = array_slice( $records, ( $page - 1 ) * self::PAGE_SIZE, self::PAGE_SIZE );
		$eligible     = count( array_filter( $page_rows, static fn( array $record ): bool => $record['eligible'] ) );
		$all_eligible = count( array_filter( $records, static fn( array $record ): bool => $record['eligible'] ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Presenter Legacy HTML Trust', 'presenter' ); ?></h1>
			<p><?php esc_html_e( 'Untrusted legacy slide HTML is sanitized by default. Use this screen only after reviewing the selected decks and deciding that their current stored HTML, including any scripts, may run without filtering.', 'presenter' ); ?></p>
			<?php $this->render_notice(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::TRUST_ACTION ); ?>">
				<input type="hidden" name="return_page" value="<?php echo esc_attr( (string) $page ); ?>">
				<?php wp_nonce_field( self::TRUST_ACTION ); ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<td id="cb" class="manage-column column-cb check-column">
								<?php if ( $eligible > 0 ) : ?>
									<input id="cb-select-all-1" type="checkbox"><label for="cb-select-all-1"><span class="screen-reader-text"><?php esc_html_e( 'Select all eligible decks on this page', 'presenter' ); ?></span></label>
								<?php endif; ?>
							</td>
							<th scope="col"><?php esc_html_e( 'Slideshow', 'presenter' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Active representation', 'presenter' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Raw HTML trust', 'presenter' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php $this->render_rows( $page_rows ); ?>
					</tbody>
				</table>
				<?php if ( $eligible > 0 || $all_eligible > 0 ) : ?>
					<p><label><input type="checkbox" name="presenter_confirm" value="trust" required> <?php esc_html_e( 'I reviewed these decks and authorize their exact current legacy slide HTML to run without WordPress HTML filtering.', 'presenter' ); ?></label></p>
					<?php if ( $eligible > 0 ) : ?>
						<button type="submit" class="button button-primary" name="trust_scope" value="selected"><?php esc_html_e( 'Trust selected decks', 'presenter' ); ?></button>
					<?php endif; ?>
					<?php if ( $all_eligible > 0 ) : ?>
						<button type="submit" class="button button-secondary" name="trust_scope" value="all"><?php echo esc_html( sprintf( /* translators: %d: number of eligible untrusted decks. */ _n( 'Trust all %d deck', 'Trust all %d decks', $all_eligible, 'presenter' ), $all_eligible ) ); ?></button>
					<?php endif; ?>
				<?php endif; ?>
			</form>
			<?php $this->render_pagination( $page, $total_pages ); ?>
		</div>
		<?php
	}

	/** Handle one explicit selected-deck or all-eligible trust request. */
	public function handle_trust(): void {
		$count = $this->process_trust_request();
		$url   = $this->page_url(
			array(
				'presenter-trust-result' => 'complete',
				'presenter-trust-count'  => (string) $count,
				'paged'                  => '1',
			)
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Validate and trust an explicit selected set or all eligible decks without redirecting.
	 *
	 * @return int Number of exact current slide sets trusted.
	 */
	public function process_trust_request(): int {
		if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			wp_die( esc_html__( 'Legacy HTML trust changes require a POST request.', 'presenter' ), '', array( 'response' => 405 ) );
		}
		$this->require_screen_capabilities();
		check_admin_referer( self::TRUST_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The action nonce is verified immediately above.
		$confirmation = $_POST['presenter_confirm'] ?? null;
		if ( ! is_string( $confirmation ) || 'trust' !== wp_unslash( $confirmation ) ) {
			wp_die( esc_html__( 'Confirm that you reviewed and trust the selected legacy HTML.', 'presenter' ), '', array( 'response' => 400 ) );
		}

		$records = $this->inventory_records();
		$scope   = $this->requested_scope();
		$page    = $this->requested_return_page( count( $records ) );
		$allowed = array();

		if ( 'all' === $scope ) {
			$post_ids = array_column(
				array_filter( $records, static fn( array $record ): bool => $record['eligible'] ),
				'post_id'
			);
		} else {
			$post_ids = $this->requested_post_ids();
			$allowed  = array_column( array_slice( $records, ( $page - 1 ) * self::PAGE_SIZE, self::PAGE_SIZE ), 'post_id' );
		}

		foreach ( $post_ids as $post_id ) {
			if ( ( 'selected' === $scope && ! in_array( $post_id, $allowed, true ) ) || ! $this->is_eligible( $post_id ) ) {
				wp_die( esc_html__( 'One selected slideshow is not eligible for legacy HTML trust.', 'presenter' ), '', array( 'response' => 409 ) );
			}
		}

		$trusted = 0;
		foreach ( $post_ids as $post_id ) {
			$current_slides = $this->slides->read_slides( $post_id );
			$this->trust->synchronize( $post_id, $current_slides, true );
			if ( $this->trust->is_trusted( $post_id, $current_slides ) ) {
				++$trusted;
			}
		}

		return $trusted;
	}

	/**
	 * Render authorized rows without exposing authored slide content.
	 *
	 * @param array<int, array{post_id: int, post: WP_Post, legacy: bool, trusted: bool, eligible: bool}> $records Current inventory page records.
	 */
	private function render_rows( array $records ): void {
		$rendered = 0;
		foreach ( $records as $record ) {
			$post_id    = $record['post_id'];
			$post       = $record['post'];
			$legacy     = $record['legacy'];
			$is_trusted = $record['trusted'];
			$can_trust  = $record['eligible'];
			$label      = get_the_title( $post ) ? get_the_title( $post ) : __( '(no title)', 'presenter' );
			++$rendered;
			?>
			<tr>
				<th scope="row" class="check-column">
					<?php if ( $can_trust ) : ?>
						<input id="cb-select-<?php echo esc_attr( (string) $post_id ); ?>" type="checkbox" name="post_ids[]" value="<?php echo esc_attr( (string) $post_id ); ?>"><label for="cb-select-<?php echo esc_attr( (string) $post_id ); ?>"><span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: slideshow title. */ __( 'Select %s', 'presenter' ), $label ) ); ?></span></label>
					<?php else : ?>
						<span aria-hidden="true">—</span><span class="screen-reader-text"><?php esc_html_e( 'Not eligible for trust', 'presenter' ); ?></span>
					<?php endif; ?>
				</th>
				<td><a href="<?php echo esc_url( get_edit_post_link( $post_id, 'raw' ) ); ?>"><?php echo esc_html( $label ); ?></a> <span aria-hidden="true">—</span> <?php echo esc_html( (string) $post_id ); ?></td>
				<td><?php echo esc_html( $legacy ? __( 'Legacy metadata', 'presenter' ) : __( 'Native blocks', 'presenter' ) ); ?></td>
				<td><?php echo esc_html( $is_trusted ? __( 'Trusted for this exact content', 'presenter' ) : __( 'Sanitized', 'presenter' ) ); ?></td>
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
	 * Load editable inventory records and put eligible untrusted decks first.
	 *
	 * @return array<int, array{post_id: int, post: WP_Post, legacy: bool, trusted: bool, eligible: bool}> Ordered records.
	 */
	private function inventory_records(): array {
		$total   = $this->inventory->count();
		$records = array();

		for ( $offset = 0; $offset < $total; $offset += Legacy_Deck_Inventory::MAX_BATCH_SIZE ) {
			$limit = min( Legacy_Deck_Inventory::MAX_BATCH_SIZE, $total - $offset );
			foreach ( $this->inventory->ids( $limit, $offset ) as $post_id ) {
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					continue;
				}

				$post = get_post( $post_id );
				if ( ! $post instanceof WP_Post ) {
					continue;
				}

				$slides     = $this->slides->read_slides( $post_id );
				$legacy     = $this->deck_mode->uses_legacy_runtime( $post_id );
				$is_trusted = $this->trust->is_trusted( $post_id, $slides );
				$records[]  = array(
					'post_id'  => $post_id,
					'post'     => $post,
					'legacy'   => $legacy,
					'trusted'  => $is_trusted,
					'eligible' => $legacy && ! $is_trusted && array() !== $slides,
				);
			}
		}

		usort(
			$records,
			static function ( array $left, array $right ): int {
				if ( $left['eligible'] !== $right['eligible'] ) {
					return $left['eligible'] ? -1 : 1;
				}

				return $left['post_id'] <=> $right['post_id'];
			}
		);

		return $records;
	}

	/**
	 * Determine whether the current exact deck may be trusted.
	 *
	 * @param int $post_id Slideshow post ID.
	 */
	private function is_eligible( int $post_id ): bool {
		$post   = get_post( $post_id );
		$slides = $this->slides->read_slides( $post_id );

		return $post instanceof WP_Post
			&& 'slideshow' === $post->post_type
			&& current_user_can( 'edit_post', $post_id )
			&& $this->deck_mode->uses_legacy_runtime( $post_id )
			&& array() !== $slides
			&& ! $this->trust->is_trusted( $post_id, $slides );
	}

	/** Require both administrative authority and raw-HTML authorship. */
	private function require_screen_capabilities(): void {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'unfiltered_html' ) ) {
			wp_die( esc_html__( 'You are not allowed to trust legacy Presenter HTML.', 'presenter' ), '', array( 'response' => 403 ) );
		}
	}

	/** Read, validate, deduplicate, and bound selected post IDs. */
	private function requested_post_ids(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called only after the action nonce is verified.
		$values = $_POST['post_ids'] ?? null;
		if ( ! is_array( $values ) || array() === $values || count( $values ) > self::PAGE_SIZE ) {
			wp_die( esc_html__( 'Select from one through twenty eligible slideshows.', 'presenter' ), '', array( 'response' => 400 ) );
		}

		$post_ids = array();
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) ) {
				wp_die( esc_html__( 'Every selected slideshow ID must be valid.', 'presenter' ), '', array( 'response' => 400 ) );
			}
			$value   = sanitize_text_field( wp_unslash( $value ) );
			$post_id = absint( $value );
			if ( $post_id < 1 || (string) $post_id !== $value || isset( $post_ids[ $post_id ] ) ) {
				wp_die( esc_html__( 'Every selected slideshow must be unique and valid.', 'presenter' ), '', array( 'response' => 400 ) );
			}
			$post_ids[ $post_id ] = $post_id;
		}

		return array_values( $post_ids );
	}

	/** Read and validate the requested trust scope. */
	private function requested_scope(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called only after the action nonce is verified.
		$value = $_POST['trust_scope'] ?? 'selected';
		if ( ! is_string( $value ) ) {
			wp_die( esc_html__( 'The legacy HTML trust scope is invalid.', 'presenter' ), '', array( 'response' => 400 ) );
		}

		$scope = sanitize_key( wp_unslash( $value ) );
		if ( ! in_array( $scope, array( 'selected', 'all' ), true ) ) {
			wp_die( esc_html__( 'The legacy HTML trust scope is invalid.', 'presenter' ), '', array( 'response' => 400 ) );
		}

		return $scope;
	}

	/** Render a status notice whose authoritative detail comes from the table. */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This fixed read-only status cannot mutate state.
		$result = isset( $_GET['presenter-trust-result'] ) && is_string( $_GET['presenter-trust-result'] ) ? sanitize_key( wp_unslash( $_GET['presenter-trust-result'] ) ) : '';
		if ( 'complete' === $result ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'The trust request completed. The current content-bound status for every deck is shown below.', 'presenter' ) );
		}
	}

	/**
	 * Render bounded inventory pagination.
	 *
	 * @param int $page        Current page.
	 * @param int $total_pages Total page count.
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
			printf( '<nav class="tablenav-pages" aria-label="%1$s">%2$s</nav>', esc_attr__( 'Presenter legacy HTML trust pages', 'presenter' ), wp_kses_post( $links ) );
		}
	}

	/** Get and bound the requested inventory page. */
	private function requested_page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only inventory pagination.
		$page = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1;

		return max( 1, $page );
	}

	/**
	 * Read and clamp the nonce-protected originating inventory page.
	 *
	 * @param int|null $total Optional count of ordered, editable records.
	 */
	private function requested_return_page( ?int $total = null ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called after the request nonce is verified or only to build a redirect.
		$value = isset( $_POST['return_page'] ) && is_string( $_POST['return_page'] ) ? wp_unslash( $_POST['return_page'] ) : '1';
		$page  = absint( $value );
		if ( $page < 1 || (string) $page !== $value ) {
			return 1;
		}

		$total       = null === $total ? $this->inventory->count() : $total;
		$total_pages = max( 1, (int) ceil( $total / self::PAGE_SIZE ) );

		return min( $page, $total_pages );
	}

	/**
	 * Build a safe URL back to the trust page.
	 *
	 * @param array<string, string> $args Optional query values.
	 */
	private function page_url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'tools.php?page=' . self::PAGE_SLUG ) );
	}
}

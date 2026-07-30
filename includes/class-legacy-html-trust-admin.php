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

	/** Admin-post action for one bounded selected set. */
	public const TRUST_ACTION = 'presenter_legacy_html_trust';

	/** Number of decks inspected or changed by one request. */
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

	/** Render one bounded, content-free trust inventory page. */
	public function render_page(): void {
		$this->require_screen_capabilities();

		$total       = $this->inventory->count();
		$total_pages = max( 1, (int) ceil( $total / self::PAGE_SIZE ) );
		$page        = min( $this->requested_page(), $total_pages );
		$post_ids    = $this->inventory->ids( self::PAGE_SIZE, ( $page - 1 ) * self::PAGE_SIZE );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Presenter Legacy HTML Trust', 'presenter' ); ?></h1>
			<p><?php esc_html_e( 'Untrusted legacy slide HTML is sanitized by default. Use this screen only after reviewing the selected decks and deciding that their current stored HTML, including any scripts, may run without filtering.', 'presenter' ); ?></p>
			<?php $this->render_notice(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::TRUST_ACTION ); ?>">
				<input type="hidden" name="return_page" value="<?php echo esc_attr( (string) $page ); ?>">
				<?php wp_nonce_field( self::TRUST_ACTION ); ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Slideshow', 'presenter' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Active representation', 'presenter' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Raw HTML trust', 'presenter' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Select', 'presenter' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php $eligible = $this->render_rows( $post_ids ); ?>
					</tbody>
				</table>
				<?php if ( $eligible > 0 ) : ?>
					<p><label><input type="checkbox" name="presenter_confirm" value="trust" required> <?php esc_html_e( 'I reviewed these decks and authorize their exact current legacy slide HTML to run without WordPress HTML filtering.', 'presenter' ); ?></label></p>
					<?php submit_button( __( 'Trust selected decks', 'presenter' ), 'primary', 'submit', false ); ?>
				<?php endif; ?>
			</form>
			<?php $this->render_pagination( $page, $total_pages ); ?>
		</div>
		<?php
	}

	/** Handle one explicit selected-deck trust request. */
	public function handle_trust(): void {
		$count = $this->process_trust_request();
		$page  = $this->requested_return_page();
		$url   = $this->page_url(
			array(
				'presenter-trust-result' => 'complete',
				'presenter-trust-count'  => (string) $count,
				'paged'                  => (string) $page,
			)
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Validate and trust one bounded selected set without redirecting.
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

		$post_ids = $this->requested_post_ids();
		$page     = $this->requested_return_page();
		$allowed  = $this->inventory->ids( self::PAGE_SIZE, ( $page - 1 ) * self::PAGE_SIZE );
		foreach ( $post_ids as $post_id ) {
			if ( ! in_array( $post_id, $allowed, true ) || ! $this->is_eligible( $post_id ) ) {
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
	 * @param array<int, int> $post_ids Current inventory page IDs.
	 * @return int Number of selectable decks.
	 */
	private function render_rows( array $post_ids ): int {
		$rendered = 0;
		$eligible = 0;
		foreach ( $post_ids as $post_id ) {
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
			$can_trust  = $legacy && ! $is_trusted && array() !== $slides;
			$label      = get_the_title( $post ) ? get_the_title( $post ) : __( '(no title)', 'presenter' );
			++$rendered;
			if ( $can_trust ) {
				++$eligible;
			}
			?>
			<tr>
				<th scope="row"><a href="<?php echo esc_url( get_edit_post_link( $post_id, 'raw' ) ); ?>"><?php echo esc_html( $label ); ?></a> <span aria-hidden="true">—</span> <?php echo esc_html( (string) $post_id ); ?></th>
				<td><?php echo esc_html( $legacy ? __( 'Legacy metadata', 'presenter' ) : __( 'Native blocks', 'presenter' ) ); ?></td>
				<td><?php echo esc_html( $is_trusted ? __( 'Trusted for this exact content', 'presenter' ) : __( 'Sanitized', 'presenter' ) ); ?></td>
				<td>
					<?php if ( $can_trust ) : ?>
						<label><input type="checkbox" name="post_ids[]" value="<?php echo esc_attr( (string) $post_id ); ?>"> <?php echo esc_html( sprintf( /* translators: %s: slideshow title. */ __( 'Trust %s', 'presenter' ), $label ) ); ?></label>
					<?php else : ?>
						<span aria-hidden="true">—</span><span class="screen-reader-text"><?php esc_html_e( 'Not eligible for trust', 'presenter' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<?php
		}

		if ( 0 === $rendered ) {
			?>
			<tr><td colspan="4"><?php esc_html_e( 'No editable legacy slideshows were found on this page.', 'presenter' ); ?></td></tr>
			<?php
		}

		return $eligible;
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

	/** Read and clamp the nonce-protected originating inventory page. */
	private function requested_return_page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called after the request nonce is verified or only to build a redirect.
		$value = isset( $_POST['return_page'] ) && is_string( $_POST['return_page'] ) ? wp_unslash( $_POST['return_page'] ) : '1';
		$page  = absint( $value );
		if ( $page < 1 || (string) $page !== $value ) {
			return 1;
		}

		$total_pages = max( 1, (int) ceil( $this->inventory->count() / self::PAGE_SIZE ) );

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

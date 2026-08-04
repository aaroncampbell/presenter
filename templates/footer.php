<?php
/**
 * Presenter 1.x compatibility document footer.
 *
 * @package Presenter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
			</div>

		<?php
		$presenter_short_url = \Presenter\Meta::sanitize_short_url( get_post_meta( get_the_ID(), '_presenter-short-url', true ) );
		if ( ! empty( $presenter_short_url ) ) {
			?>
			<p class="permalink">
				<a href="<?php echo esc_url( $presenter_short_url, array( 'http', 'https' ) ); ?>"><?php echo esc_html( $presenter_short_url ); ?></a>
			</p>
			<?php
		}
		do_action( 'presenter-reveal-footer' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Retained Presenter 1.x public hook.
		?>
		</div>
		<?php
		/** Retained Presenter 1.x footer integration point. */
		do_action( 'presenter-footer' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Retained Presenter 1.x public hook.
		wp_footer();
		\Presenter\Presentation_Document::end();
		?>

	</body>
</html>

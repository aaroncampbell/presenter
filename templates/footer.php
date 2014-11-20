			</div>

		<?php
		$presenter_short_url = get_post_meta( get_the_ID(), '_presenter-short-url', true );
		if ( ! empty( $presenter_short_url ) ) {
			?>
			<p class="permalink">
				<a href="<?php echo esc_attr( $presenter_short_url ); ?>"><?php echo esc_html( $presenter_short_url ); ?></a>
			</p>
			<?php
		}
		?>
		</div>
		<?php
		$scripts_to_load = array( 'reveal-head', 'reveal' );

		global $SyntaxHighlighter;
		if ( is_a( $SyntaxHighlighter, 'SyntaxHighlighter' ) ) {
			$scripts_to_load[] = 'syntaxhighlighter';
		}
		wp_print_scripts( $scripts_to_load );
		/**
		 * Custom footer action because loading other CSS/JS breaks things
		 *
		 * @todo Find a way to still include Analytics codes. At least work with popular GA plugins
		 */
		do_action( 'presenter-footer' );
		?>

	</body>
</html>

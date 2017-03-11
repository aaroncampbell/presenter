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
		do_action( 'presenter-reveal-footer' );
		?>
		</div>
		<?php
		/**
		 * Custom footer action because loading other CSS/JS breaks things
		 *
		 * @todo Find a way to still include Analytics codes. At least work with popular GA plugins
		 */
		do_action( 'presenter-footer' );
		?>

	</body>
</html>

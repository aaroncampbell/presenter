<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=1024, user-scalable=no">
	<title><?php wp_title(); ?></title>
	<link rel="profile" href="http://gmpg.org/xfn/11" />
	<link rel="pingback" href="<?php bloginfo( 'pingback_url' ); ?>" />
<?php
	/**
	 * Always have wp_head() just before the closing </head>
	 * tag of your theme, or you will break many plugins, which
	 * generally use this hook to add elements to <head> such
	 * as styles, scripts, and meta tags.
	 */
	wp_head();
?>
</head>

<body>
<?php
if ( have_posts() ) {
	?>
	<article class="deck-container">
		<?php
		while ( have_posts() ) {
			the_post();
			?>
			<section class="slide" id="<?php echo esc_attr( $post->post_name ) ?>">
			<?php the_content();?>
			</section>
		<?php
		}
		?>
		<p class="permalink"><a href="<?php the_permalink(); ?>"><?php echo wp_get_shortlink(); ?></a></p>

		<!-- deck.status snippet -->
		<p class="deck-status">
			<span class="deck-status-current"></span>
			/
			<span class="deck-status-total"></span>
		</p>

		<!-- deck.goto snippet -->
		<form action="." method="get" class="goto-form">
			<label for="goto-slide">Go to slide:</label>
			<input type="text" name="slidenum" id="goto-slide" list="goto-datalist">
			<datalist id="goto-datalist"></datalist>
			<input type="submit" value="Go">
		</form>

		<!-- deck.hash snippet -->
		<a href="." title="Permalink to this slide" class="deck-permalink">#</a>
	</article>
	<?php
}
wp_footer();
?>
<!-- Initialize the deck -->
<script>
jQuery(function($) {
	$.deck('.slide');
});
</script>

</body>
</html>

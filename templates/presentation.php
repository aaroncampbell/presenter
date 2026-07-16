<?php
/**
 * Presenter 2.0 native presentation template.
 *
 * @package Presenter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$presenter_post = get_post();

if ( ! $presenter_post instanceof WP_Post ) {
	return;
}

// Render blocks before wp_head() so block styles and view scripts can enqueue.
$presenter_slides   = apply_filters( 'the_content', $presenter_post->post_content );
$presenter_settings = apply_filters( 'presenter_reveal_config', array(), $presenter_post );
$presenter_plugins  = apply_filters( 'presenter_reveal_plugins', null, $presenter_post );

if ( ! is_array( $presenter_settings ) ) {
	$presenter_settings = array();
}

if ( ! is_array( $presenter_plugins ) ) {
	$presenter_plugins = null;
}

$presenter_markup = presenter_get_runtime()->renderer()->render_blocks(
	$presenter_slides,
	$presenter_settings,
	$presenter_plugins
);
?>
<!doctype html>
<html <?php language_attributes(); ?>>
	<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>">
		<?php wp_head(); ?>
	</head>
	<body <?php body_class( 'presenter-presentation presenter-presentation-native' ); ?>>
		<?php wp_body_open(); ?>
		<a class="screen-reader-text skip-link" href="#presenter-presentation">
			<?php esc_html_e( 'Skip to presentation', 'presenter' ); ?>
		</a>
		<main id="presenter-presentation" tabindex="-1">
			<?php echo $presenter_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer combines escaped Presenter markup with WordPress-rendered block HTML and script-safe JSON. ?>
		</main>
		<?php wp_footer(); ?>
	</body>
</html>

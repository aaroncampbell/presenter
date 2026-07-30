<?php
/**
 * Presenter 2.0 native presentation template.
 *
 * @package Presenter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! have_posts() ) {
	return;
}

the_post();
$presenter_post = get_post();

if ( ! $presenter_post instanceof WP_Post ) {
	return;
}

// Render blocks before wp_head() so block styles and view scripts can enqueue.
$presenter_slides   = apply_filters( 'the_content', $presenter_post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core content hook.
$presenter_settings = apply_filters( 'presenter_reveal_config', array(), $presenter_post );
$presenter_plugins  = apply_filters( 'presenter_reveal_plugins', \Presenter\Reveal_Config::plugins_for_markup( $presenter_slides ), $presenter_post );

if ( ! is_array( $presenter_settings ) ) {
	$presenter_settings = array();
}

if ( ! is_array( $presenter_plugins ) ) {
	$presenter_plugins = null;
}

$presenter_short_url = get_post_meta( $presenter_post->ID, '_presenter-short-url', true );
if ( ! is_string( $presenter_short_url ) ) {
	$presenter_short_url = '';
}

// Presenter owns this complete document and emits one deterministic title.
$presenter_core_title_priority = has_action( 'wp_head', '_wp_render_title_tag' );
if ( false !== $presenter_core_title_priority ) {
	remove_action( 'wp_head', '_wp_render_title_tag', $presenter_core_title_priority );
}
$presenter_block_viewport_priority = has_action( 'wp_head', '_block_template_viewport_meta_tag' );
if ( false !== $presenter_block_viewport_priority ) {
	remove_action( 'wp_head', '_block_template_viewport_meta_tag', $presenter_block_viewport_priority );
}

?>
<!doctype html>
<html <?php language_attributes(); ?>>
	<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title><?php echo esc_html( wp_get_document_title() ); ?></title>
		<?php
		wp_head();
		if ( false !== $presenter_core_title_priority ) {
			add_action( 'wp_head', '_wp_render_title_tag', $presenter_core_title_priority );
		}
		if ( false !== $presenter_block_viewport_priority ) {
			add_action( 'wp_head', '_block_template_viewport_meta_tag', $presenter_block_viewport_priority );
		}
		?>
	</head>
	<body <?php body_class( 'presenter-presentation presenter-presentation-native' ); ?>>
		<?php wp_body_open(); ?>
		<a class="screen-reader-text skip-link" href="#presenter-presentation">
			<?php esc_html_e( 'Skip to presentation', 'presenter' ); ?>
		</a>
		<main id="presenter-presentation" tabindex="-1">
			<?php
			ob_start();
			do_action( 'presenter-reveal-footer' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Retained Presenter 1.x public hook.
			$presenter_reveal_footer = ob_get_clean();

			if ( false === $presenter_reveal_footer ) {
				$presenter_reveal_footer = '';
			}

			$presenter_markup = presenter_get_runtime()->renderer()->render_blocks(
				$presenter_slides,
				$presenter_settings,
				$presenter_plugins,
				$presenter_short_url,
				$presenter_reveal_footer
			);
			?>
			<?php echo $presenter_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer combines escaped Presenter markup, WordPress-rendered block HTML, trusted plugin-hook markup, and script-safe JSON. ?>
		</main>
		<?php wp_footer(); ?>
	</body>
</html>

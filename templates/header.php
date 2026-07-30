<?php
/**
 * Presenter 1.x compatibility document header.
 *
 * @package Presenter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Presenter owns this complete document and emits one deterministic title.
$presenter_core_title_priority = has_action( 'wp_head', '_wp_render_title_tag' );
if ( false !== $presenter_core_title_priority ) {
	remove_action( 'wp_head', '_wp_render_title_tag', $presenter_core_title_priority );
}
$presenter_block_title_priority = has_action( 'wp_head', '_block_template_render_title_tag' );
if ( false !== $presenter_block_title_priority ) {
	remove_action( 'wp_head', '_block_template_render_title_tag', $presenter_block_title_priority );
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

		<meta name="apple-mobile-web-app-capable" content="yes" />
		<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />

		<?php
		wp_print_styles( array( 'presenter', 'reveal', 'reveal-theme' ) );
		/** Retained Presenter 1.x head integration point. */
		do_action( 'presenter-head' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Retained Presenter 1.x public hook.
		wp_head();
		if ( false !== $presenter_core_title_priority ) {
			add_action( 'wp_head', '_wp_render_title_tag', $presenter_core_title_priority );
		}
		if ( false !== $presenter_block_title_priority ) {
			add_action( 'wp_head', '_block_template_render_title_tag', $presenter_block_title_priority );
		}
		if ( false !== $presenter_block_viewport_priority ) {
			add_action( 'wp_head', '_block_template_viewport_meta_tag', $presenter_block_viewport_priority );
		}
		?>
	</head>

	<body <?php body_class( 'presenter-presentation' ); ?>>
		<?php wp_body_open(); ?>

		<div class="reveal" data-presenter-reveal-root>

			<!-- Any section element inside of this container is displayed as a slide -->
			<div class="slides">

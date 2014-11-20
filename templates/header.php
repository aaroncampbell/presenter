<!doctype html>
<html <?php language_attributes(); ?>>

	<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>">
		<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">

		<title><?php wp_title( '|', true, 'right' ); ?></title>

		<meta name="apple-mobile-web-app-capable" content="yes" />
		<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />

		<?php
		wp_print_styles( array( 'presenter', 'reveal', 'reveal-theme', 'reveal-lib-zenburn' ) );
		/**
		 * Custom head action because loading other CSS/JS breaks things
		 *
		 * @todo Find a way to still include Analytics codes. At least work with popular GA plugins
		 */
		do_action( 'presenter-head' );
		?>
	</head>

	<body>

		<div class="reveal">

			<!-- Any section element inside of this container is displayed as a slide -->
			<div class="slides">

<?php
/**
 * Presenter 1.x compatibility presentation template.
 *
 * @package Presenter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require __DIR__ . '/header.php';

// Start the Loop.
while ( have_posts() ) {
	the_post();
	the_content();
}

require __DIR__ . '/footer.php';

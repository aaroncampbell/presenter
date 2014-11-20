<?php
include( 'header.php' );

// Start the Loop.
while ( have_posts() ) {
	the_post();
	the_content();
}

include( 'footer.php' );

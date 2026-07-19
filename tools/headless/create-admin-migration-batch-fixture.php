<?php
/**
 * Create disposable legacy decks for the admin Prepare queue gate.
 *
 * @package Presenter
 */

if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'The admin migration batch fixture may only be created locally.' );
}

$fixtures      = array(
	'presenter-admin-batch-one'      => 'Presenter Admin Batch One',
	'presenter-admin-batch-two'      => 'Presenter Admin Batch Two',
	'presenter-admin-batch-three'    => 'Presenter Admin Batch Three',
	'presenter-admin-batch-neighbor' => 'Presenter Admin Batch Neighbor',
	'presenter-admin-apply-neighbor' => 'Presenter Admin Apply Neighbor',
);
$fixture_slugs = array_merge( array_keys( $fixtures ), array_map( static fn( int $index ): string => 'presenter-admin-batch-padding-' . $index, range( 1, 20 ) ) );

foreach ( $fixture_slugs as $slug ) {
	$existing = get_page_by_path( $slug, OBJECT, 'slideshow' );
	if ( $existing instanceof WP_Post ) {
		wp_delete_post( $existing->ID, true );
	}
}

$create_fixture = static function ( string $slug, string $fixture_title ): void {

	$fixture_post_id = wp_insert_post(
		array(
			'post_content' => '',
			'post_name'    => $slug,
			'post_status'  => 'publish',
			'post_title'   => $fixture_title,
			'post_type'    => 'slideshow',
		),
		true
	);
	if ( is_wp_error( $fixture_post_id ) ) {
		WP_CLI::error( $fixture_post_id );
	}

	add_post_meta(
		$fixture_post_id,
		'_presenter_slides',
		(object) array(
			'number'  => 1,
			'title'   => $fixture_title,
			'content' => '<p>Legacy route sentinel for ' . esc_html( $fixture_title ) . '.</p>',
			'class'   => '',
		)
	);
};

$inventory     = new Presenter\Legacy_Deck_Inventory();
$remainder     = $inventory->count() % 20;
$padding_count = $remainder > 15 ? 20 - $remainder : 0;
for ( $index = 1; $index <= $padding_count; ++$index ) {
	$create_fixture( 'presenter-admin-batch-padding-' . $index, 'Presenter Admin Batch Padding ' . $index );
}

foreach ( $fixtures as $slug => $fixture_title ) {
	$create_fixture( $slug, $fixture_title );
}

WP_CLI::success( 'Presenter admin migration batch fixtures are ready.' );

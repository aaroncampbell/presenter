<?php
/**
 * Create deterministic local fixtures for migration prepare/status CLI checks.
 *
 * @package Presenter
 */

if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Migration fixtures may only be created locally.' );
}

$marker   = 'presenter-m7-prepare-status-v1';
$fixtures = array(
	'presenter-m7-prepare-ready'   => array(
		'post_content' => '',
		'post_title'   => 'Presenter prepare ready fixture',
		'slide'        => (object) array(
			'number'  => 1,
			'title'   => 'Prepare ready slide',
			'content' => '<h2>prepare-private-ready-content-sentinel</h2>',
		),
	),
	'presenter-m7-prepare-blocked' => array(
		'post_content' => '<p>prepare-private-blocked-post-sentinel</p>',
		'post_title'   => 'Presenter prepare blocked fixture',
		'slide'        => (object) array(
			'number'  => 1,
			'title'   => 'Prepare blocked slide',
			'content' => '<p>prepare-private-blocked-slide-sentinel</p>',
		),
	),
);
$records  = array();

foreach ( $fixtures as $slug => $fixture ) {
	$fixture_post = get_page_by_path( $slug, 'OBJECT', 'slideshow' );
	if (
		$fixture_post instanceof WP_Post
		&& get_post_meta( $fixture_post->ID, '_presenter_test_fixture', true ) !== $marker
	) {
		WP_CLI::error( 'Refusing to overwrite an unmarked slideshow with a reserved fixture slug.' );
	}

	$post_data = array(
		'post_content' => $fixture['post_content'],
		'post_name'    => $slug,
		'post_status'  => 'publish',
		'post_title'   => $fixture['post_title'],
		'post_type'    => 'slideshow',
	);
	if ( $fixture_post instanceof WP_Post ) {
		$post_data['ID'] = $fixture_post->ID;
	}

	$fixture_post_id = wp_insert_post( wp_slash( $post_data ), true );
	if ( is_wp_error( $fixture_post_id ) ) {
		WP_CLI::error( $fixture_post_id );
	}

	update_post_meta( $fixture_post_id, '_presenter_test_fixture', $marker );
	delete_post_meta( $fixture_post_id, '_presenter_slides' );
	add_post_meta( $fixture_post_id, '_presenter_slides', $fixture['slide'] );
	delete_post_meta( $fixture_post_id, '_presenter-theme' );
	delete_post_meta( $fixture_post_id, '_presenter-short-url' );
	delete_post_meta( $fixture_post_id, '_presenter_migration_backup_v1' );
	delete_post_meta( $fixture_post_id, '_presenter_migration_journal_v1' );
	delete_post_meta( $fixture_post_id, '_presenter_deck_mode' );
	delete_option( 'presenter_migration_lock_' . $fixture_post_id );

	foreach ( wp_get_post_revisions( $fixture_post_id ) as $revision ) {
		wp_delete_post_revision( $revision->ID );
	}

	$records[ $slug ] = array( 'postId' => $fixture_post_id );
}

update_option(
	'presenter_migration_prepare_status_fixtures',
	array(
		'schemaVersion' => 1,
		'marker'        => $marker,
		'fixtures'      => $records,
	),
	false
);

WP_CLI::success( 'Presenter migration prepare/status fixtures are ready.' );

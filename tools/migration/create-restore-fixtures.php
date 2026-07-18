<?php
/**
 * Create deterministic local fixtures for migration restore CLI checks.
 *
 * @package Presenter
 */

if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Migration fixtures may only be created locally.' );
}

$marker  = 'presenter-m7-restore-v1';
$slides  = array(
	'presenter-m7-restore-ready'     => array(
		'post_title' => 'Presenter restore ready fixture',
		'sentinel'   => 'restore-private-ready-content-sentinel',
	),
	'presenter-m7-restore-unapplied' => array(
		'post_title' => 'Presenter restore unapplied fixture',
		'sentinel'   => 'restore-private-unapplied-content-sentinel',
	),
);
$records = array();

foreach ( $slides as $slug => $fixture ) {
	$fixture_post = get_page_by_path( $slug, 'OBJECT', 'slideshow' );
	if (
		$fixture_post instanceof WP_Post
		&& get_post_meta( $fixture_post->ID, '_presenter_test_fixture', true ) !== $marker
	) {
		WP_CLI::error( 'Refusing to overwrite an unmarked slideshow with a reserved fixture slug.' );
	}

	$post_data = array(
		'post_content'  => '',
		'post_excerpt'  => 'Presenter restore excerpt sentinel',
		'post_name'     => $slug,
		'post_password' => 'restore-fixture-password',
		'post_status'   => 'publish',
		'post_title'    => $fixture['post_title'],
		'post_type'     => 'slideshow',
		'menu_order'    => 37,
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
	add_post_meta(
		$fixture_post_id,
		'_presenter_slides',
		(object) array(
			'number'  => 1,
			'title'   => 'Restore fixture slide',
			'content' => '<h2>' . $fixture['sentinel'] . '</h2>',
			'class'   => '',
		)
	);
	delete_post_meta( $fixture_post_id, '_presenter-theme' );
	add_post_meta( $fixture_post_id, '_presenter-theme', '/plugins/presenter/reveal.js/css/theme/black.css' );
	delete_post_meta( $fixture_post_id, '_presenter-short-url' );
	add_post_meta( $fixture_post_id, '_presenter-short-url', 'restore-fixture-short-url' );
	delete_post_meta( $fixture_post_id, '_presenter_restore_fixture_artifact' );
	add_post_meta(
		$fixture_post_id,
		'_presenter_restore_fixture_artifact',
		array(
			'nested'   => array( 'restore-artifact-private-sentinel' ),
			'preserve' => true,
		)
	);
	delete_post_meta( $fixture_post_id, '_presenter_migration_backup_v1' );
	delete_post_meta( $fixture_post_id, '_presenter_migration_journal_v1' );
	delete_post_meta( $fixture_post_id, '_presenter_deck_mode' );
	delete_post_meta( $fixture_post_id, '_edit_lock' );
	delete_option( 'presenter_migration_lock_' . $fixture_post_id );

	foreach ( wp_get_post_revisions( $fixture_post_id ) as $revision ) {
		wp_delete_post_revision( $revision->ID );
	}

	clean_post_cache( $fixture_post_id );
	$records[ $slug ] = array( 'postId' => $fixture_post_id );
}

update_option(
	'presenter_migration_restore_fixtures',
	array(
		'schemaVersion' => 1,
		'marker'        => $marker,
		'fixtures'      => $records,
	),
	false
);

WP_CLI::success( 'Presenter migration restore fixtures are ready.' );

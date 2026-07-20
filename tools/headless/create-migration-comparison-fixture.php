<?php
/**
 * Create deterministic legacy decks for the migration comparison gate.
 *
 * @package Presenter
 */

if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Migration comparison fixtures may only be created locally.' );
}

if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$companion_plugin = 'aarondcampbell-presenter-themes/aarondcampbell-presenter-themes.php';
if ( ! is_plugin_active( $companion_plugin ) ) {
	$activation = activate_plugin( $companion_plugin );
	if ( is_wp_error( $activation ) ) {
		WP_CLI::error( 'The local Presenter themes companion plugin could not be activated.' );
	}
}

$legacy_reveal_dependencies = apply_filters( 'presenter-reveal-js-dependencies', array( 'RevealMath' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Characterized Presenter 1.x public hook.
if ( in_array( 'RevealMath', $legacy_reveal_dependencies, true ) ) {
	WP_CLI::error( 'The local comparison fixture requires the companion plugin to disable legacy RevealMath.' );
}

$marker   = 'presenter-migration-comparison-v1';
$fixtures = array(
	'presenter-migration-comparison-target'   => array(
		'title'  => 'Presenter migration comparison target',
		'slides' => array(
			(object) array(
				'number'  => 1,
				'title'   => 'Second comparison title',
				'class'   => 'comparison-layout',
				'content' => '<h2>Comparison target first</h2><p class="fragment fade-in" data-fragment-index="1">Fragment sentinel</p>',
				'data'    => array(
					(object) array(
						'name'  => 'background-color',
						'value' => '#123456',
					),
					(object) array(
						'name'  => 'transition',
						'value' => 'fade',
					),
				),
				'notes'   => array(
					'notes'    => '**Markdown comparison notes**',
					'markdown' => true,
				),
			),
			(object) array(
				'number'  => 2,
				'title'   => 'Repeated comparison title',
				'content' => "Bare comparison first paragraph.\n\nBare comparison second paragraph with <span class=\"fragment\">a fragment sentinel</span>.\n\n<img src=\"data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==\" alt=\"\" width=\"64\" height=\"64\">",
				'data'    => array(
					(object) array(
						'name'  => 'chart',
						'value' => 'comparison-chart',
					),
				),
				'notes'   => array(
					'notes'    => "Plain comparison first note.\n\nPlain comparison second note.",
					'markdown' => false,
				),
			),
			(object) array(
				'number'  => 3,
				'title'   => 'Comparison stack',
				'content' => '<section id="comparison-vertical-one"><h2>Vertical one</h2></section><section id="comparison-vertical-two"><h2>Vertical two</h2></section>',
			),
		),
	),
	'presenter-migration-comparison-neighbor' => array(
		'title'  => 'Presenter migration comparison neighbor',
		'slides' => array(
			(object) array(
				'number'  => 1,
				'title'   => 'Untouched neighbor',
				'content' => '<h2>Untouched comparison neighbor</h2>',
			),
		),
	),
);
$records  = array();

foreach ( $fixtures as $slug => $fixture ) {
	$fixture_post = get_page_by_path( $slug, OBJECT, 'slideshow' );
	if (
		$fixture_post instanceof WP_Post
		&& get_post_meta( $fixture_post->ID, '_presenter_test_fixture', true ) !== $marker
	) {
		WP_CLI::error( 'Refusing to overwrite an unmarked slideshow with a reserved fixture slug.' );
	}

	$post_data = array(
		'post_content' => '',
		'post_name'    => $slug,
		'post_status'  => 'publish',
		'post_title'   => $fixture['title'],
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
	foreach ( $fixture['slides'] as $slide ) {
		add_post_meta( $fixture_post_id, '_presenter_slides', $slide );
	}
	delete_post_meta( $fixture_post_id, '_presenter-theme' );
	add_post_meta( $fixture_post_id, '_presenter-theme', '/plugins/presenter/reveal.js/dist/theme/black.css' );
	delete_post_meta( $fixture_post_id, '_presenter-short-url' );
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
	'presenter_migration_comparison_fixtures',
	array(
		'schemaVersion' => 1,
		'marker'        => $marker,
		'fixtures'      => $records,
	),
	false
);

WP_CLI::success( 'Presenter migration comparison fixtures are ready.' );

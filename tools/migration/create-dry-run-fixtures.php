<?php
/**
 * Create deterministic legacy decks for the migration dry-run gate.
 *
 * @package Presenter
 */

if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Migration fixtures may only be created locally.' );
}

$fixtures        = array(
	'presenter-m7-ready'   => array(
		'post_content' => '',
		'post_title'   => 'Presenter M7 Ready Fixture',
		'slides'       => array(
			(object) array(
				'number'  => 2,
				'title'   => 'Second slide',
				'class'   => 'migration-layout',
				'content' => '<section><p>Vertical compatibility fixture.</p></section>',
				'data'    => array(
					(object) array(
						'name'  => 'state',
						'value' => 'migration-ready',
					),
				),
				'notes'   => array(
					'notes'    => 'Plain migration note.',
					'markdown' => false,
				),
			),
			(object) array(
				'number'  => 1,
				'title'   => 'First slide',
				'content' => '<h2>First migration fixture slide.</h2>',
				'data'    => array(
					(object) array(
						'name'  => 'background-size',
						'value' => 'auto 95%',
					),
				),
				'notes'   => array(
					'notes'    => '**Markdown migration note.**',
					'markdown' => true,
				),
			),
		),
	),
	'presenter-m7-blocked' => array(
		'post_content' => '<p>Preserved legacy post content.</p>',
		'post_title'   => 'Presenter M7 Blocked Fixture',
		'theme'        => '/plugins/presenter-themes/legacy-theme.css',
		'slides'       => array(
			(object) array(
				'number'  => 1,
				'title'   => 'Blocked slide',
				'class'   => 'legacy-wrapper',
				'content' => '<p>Mixed root.</p><section><p>Nested legacy slide.</p></section>',
				'data'    => array(
					(object) array(
						'name'  => 'background-video',
						'value' => 'https://example.test/video.mp4',
					),
				),
				'notes'   => array(
					'notes'    => '<strong onclick="alert(1)">Unsafe HTML migration note.</strong>',
					'markdown' => false,
				),
			),
		),
	),
);
$marker          = 'presenter-m7-dry-run-v1';
$fixture_records = array();

foreach ( $fixtures as $slug => $fixture ) {
	$existing = get_page_by_path( $slug, 'OBJECT', 'slideshow' );
	if (
		$existing instanceof WP_Post
		&& get_post_meta( $existing->ID, '_presenter_test_fixture', true ) !== $marker
	) {
		WP_CLI::error( 'Refusing to overwrite an unmarked slideshow with a reserved fixture slug.' );
	}
	$data = array(
		'post_content' => $fixture['post_content'],
		'post_name'    => $slug,
		'post_status'  => 'publish',
		'post_title'   => $fixture['post_title'],
		'post_type'    => 'slideshow',
	);

	if ( $existing instanceof WP_Post ) {
		$data['ID'] = $existing->ID;
	}

	$fixture_post_id = wp_insert_post( $data, true );
	if ( is_wp_error( $fixture_post_id ) ) {
		WP_CLI::error( $fixture_post_id );
	}
	update_post_meta( $fixture_post_id, '_presenter_test_fixture', $marker );

	delete_post_meta( $fixture_post_id, '_presenter_slides' );
	foreach ( $fixture['slides'] as $slide ) {
		add_post_meta( $fixture_post_id, '_presenter_slides', $slide );
	}

	if ( isset( $fixture['theme'] ) ) {
		update_post_meta( $fixture_post_id, '_presenter-theme', $fixture['theme'] );
	} else {
		delete_post_meta( $fixture_post_id, '_presenter-theme' );
	}

	$snapshot = ( new Presenter\Legacy_Deck_Snapshotter( new Presenter\WordPress_Legacy_Slide_Source() ) )->capture( $fixture_post_id );
	if ( null === $snapshot ) {
		WP_CLI::error( 'Could not snapshot a migration dry-run fixture.' );
	}
	$fixture_records[ $slug ] = array(
		'postId'      => $fixture_post_id,
		'fingerprint' => $snapshot->fingerprint(),
	);
}

update_option(
	'presenter_migration_dry_run_fixtures',
	array(
		'schemaVersion' => 1,
		'marker'        => $marker,
		'fixtures'      => $fixture_records,
	),
	false
);

WP_CLI::success( 'Presenter migration dry-run fixtures are ready.' );

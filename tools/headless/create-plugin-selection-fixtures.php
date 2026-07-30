<?php
/**
 * Create content-free decks used to verify feature-aware Reveal plugin loading.
 *
 * @package Presenter
 */

if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'The plugin-selection fixtures may only be created locally.' );
}

$fixtures = array(
	'presenter-plugin-selection-plain'        => array(
		'title'   => 'Presenter Plugin Selection: Plain',
		'content' => '<!-- wp:presenter/deck -->'
			. '<!-- wp:presenter/slide -->'
			. '<!-- wp:heading --><h2 class="wp-block-heading">Plain deck</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>No optional Reveal syntax.</p><!-- /wp:paragraph -->'
			. '<!-- /wp:presenter/slide -->'
			. '<!-- /wp:presenter/deck -->',
	),
	'presenter-plugin-selection-markdown'     => array(
		'title'   => 'Presenter Plugin Selection: Markdown',
		'content' => '<!-- wp:presenter/deck -->'
			. '<!-- wp:presenter/slide {"notes":"**Markdown** speaker note","notesFormat":"markdown"} -->'
			. '<!-- wp:heading --><h2 class="wp-block-heading">Markdown deck</h2><!-- /wp:heading -->'
			. '<!-- /wp:presenter/slide -->'
			. '<!-- /wp:presenter/deck -->',
	),
	'presenter-plugin-selection-code'         => array(
		'title'   => 'Presenter Plugin Selection: Code',
		'content' => '<!-- wp:presenter/deck -->'
			. '<!-- wp:presenter/slide -->'
			. '<!-- wp:heading --><h2 class="wp-block-heading">Code deck</h2><!-- /wp:heading -->'
			. '<!-- wp:code --><pre class="wp-block-code"><code>const presenter = true;</code></pre><!-- /wp:code -->'
			. '<!-- /wp:presenter/slide -->'
			. '<!-- /wp:presenter/deck -->',
	),
	'presenter-plugin-selection-chart'        => array(
		'title'   => 'Presenter Plugin Selection: Historical Chart Fragment',
		'content' => '<!-- wp:presenter/deck -->'
			. '<!-- wp:presenter/slide -->'
			. '<!-- wp:heading --><h2 class="wp-block-heading">Historical chart fragment</h2><!-- /wp:heading -->'
			. '<!-- wp:html --><p class="fragment" data-fragment-graph="presenterSelectionChart" data-fragment-graph-dataset="presenterSelectionDataset">Show dataset</p><!-- /wp:html -->'
			. '<!-- /wp:presenter/slide -->'
			. '<!-- /wp:presenter/deck -->',
	),
	'presenter-plugin-selection-native-chart' => array(
		'title'   => 'Presenter Plugin Selection: Native Chart',
		'content' => '<!-- wp:presenter/deck -->'
			. '<!-- wp:presenter/slide -->'
			. '<!-- wp:heading --><h2 class="wp-block-heading">Native chart</h2><!-- /wp:heading -->'
			. '<!-- wp:presenter/chart {"chartType":"bar","columns":["Year","Percent"],"rows":[["2025",41.3],["2026",42.1]],"caption":"Native chart payload fixture"} /-->'
			. '<!-- /wp:presenter/slide -->'
			. '<!-- /wp:presenter/deck -->',
	),
);

foreach ( $fixtures as $slug => $fixture ) {
	$fixture_post = get_page_by_path( $slug, OBJECT, 'slideshow' );
	$data         = array(
		'post_content' => $fixture['content'],
		'post_name'    => $slug,
		'post_status'  => 'publish',
		'post_title'   => $fixture['title'],
		'post_type'    => 'slideshow',
	);

	if ( $fixture_post instanceof WP_Post ) {
		$data['ID'] = $fixture_post->ID;
	}

	$fixture_post_id = wp_insert_post( $data, true );

	if ( is_wp_error( $fixture_post_id ) ) {
		WP_CLI::error( $fixture_post_id );
	}
}

WP_CLI::success( 'Presenter plugin-selection fixtures are ready.' );

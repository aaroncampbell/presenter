<?php
/**
 * Snapshot-only chart script rewriting tests.
 *
 * @package Presenter
 */

declare( strict_types=1 );

/**
 * Covers the snapshot MU plugin in isolated processes so its safety hooks do
 * not affect the rest of the integration suite.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class Presenter_Snapshot_Safety_Test extends WP_UnitTestCase {
	/**
	 * Load the snapshot MU plugin for each isolated test.
	 */
	public function set_up(): void {
		parent::set_up();

		require_once dirname( __DIR__, 2 ) . '/tools/snapshot/presenter-snapshot-safety.php';
	}

	/**
	 * Rewrites only exact historical chart script source URLs.
	 */
	public function test_rewrites_only_exact_chart_script_sources(): void {
		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Raw script markup is the rewrite fixture under test.
		$html = <<<'HTML'
<!doctype html>
<script src="https://www.gstatic.com/charts/loader.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.5.1/chart.min.js"></script>
<script src="https://www.gstatic.com/charts/loader.js?other-version=1"></script>
<img src="https://www.gstatic.com/charts/loader.js">
HTML;

		$rewritten = presenter_snapshot_rewrite_chart_script_urls( $html );

		$this->assertStringContainsString(
			'<script src="/wp-content/presenter-snapshot-assets/google-charts/loader.js"></script>',
			$rewritten
		);
		$this->assertStringContainsString(
			'<script src="/wp-content/presenter-snapshot-assets/chart.js/3.5.1/chart.min.js"></script>',
			$rewritten
		);
		$this->assertStringContainsString(
			'<script src="https://www.gstatic.com/charts/loader.js?other-version=1"></script>',
			$rewritten
		);
		$this->assertStringContainsString(
			'<img src="https://www.gstatic.com/charts/loader.js">',
			$rewritten
		);
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript
	}

	/**
	 * Starts output buffering only for singular slideshow requests.
	 */
	public function test_starts_output_buffer_only_for_singular_slideshows(): void {
		$starting_level = ob_get_level();

		$this->go_to( home_url( '/' ) );
		presenter_snapshot_start_chart_script_rewrite();
		$this->assertSame( $starting_level, ob_get_level() );

		$slideshow_id = self::factory()->post->create(
			array(
				'post_type'   => 'slideshow',
				'post_status' => 'publish',
			)
		);

		$this->go_to( get_permalink( $slideshow_id ) );
		presenter_snapshot_start_chart_script_rewrite();
		$this->assertSame( $starting_level + 1, ob_get_level() );

		ob_end_clean();
	}
}

<?php
/**
 * Aaron D. Campbell Presenter Themes companion-plugin characterization tests.
 *
 * @package Presenter
 */

/**
 * Characterize the integration points used by the site-specific companion plugin.
 */
class Presenter_Companion_Plugin_Contract_Test extends Presenter_Test_Case {
	/**
	 * Companion plugin instance.
	 *
	 * @var aaronDCampbellPresenterThemes
	 */
	private $companion;

	/**
	 * Companion plugin entry file.
	 *
	 * @var string
	 */
	private $companion_plugin_file;

	/**
	 * Load the companion plugin, then isolate its hooks to this test class.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( 'aaronDCampbellPresenterThemes', false ) ) {
			$plugin_root          = dirname( __DIR__, 3 );
			$companion_candidates = glob( $plugin_root . '/*/aarondcampbell-presenter-themes.php' );

			if ( empty( $companion_candidates ) ) {
				$this->markTestSkipped( 'The optional Aaron D. Campbell Presenter Themes companion plugin is not mounted.' );
			}

			$this->companion_plugin_file = reset( $companion_candidates );
			require_once $this->companion_plugin_file;
		} else {
			$reflection                  = new ReflectionClass( 'aaronDCampbellPresenterThemes' );
			$this->companion_plugin_file = $reflection->getFileName();
		}

		$this->companion = aaronDCampbellPresenterThemes::get_instance();
		$this->remove_companion_hooks();
	}

	/**
	 * Remove registrations and user state that could affect another test.
	 */
	public function tear_down(): void {
		$this->remove_companion_hooks();
		wp_deregister_script( 'RevealChartjs' );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * The companion makes its theme discoverable and selects it by default.
	 */
	public function test_companion_theme_directory_and_default_theme_contract(): void {
		$directories = $this->companion->add_theme_location( array( '/existing/theme/root' ) );
		$theme_path  = $this->companion->presenter_default_theme( '/ignored/default.css' );

		$this->assertSame( '/existing/theme/root', $directories[0] );
		$this->assertSame(
			dirname( $this->companion_plugin_file ),
			wp_normalize_path( $directories[1] )
		);
		$this->assertSame(
			'/plugins/' . basename( dirname( $this->companion_plugin_file ) ) . '/aaron-purple/aaron-purple.css',
			wp_normalize_path( $theme_path )
		);
	}

	/**
	 * The companion registers Aaron Purple under a stable ID and selects it by default.
	 */
	public function test_companion_registers_stable_aaron_purple_theme_and_default(): void {
		$existing_theme = new \Presenter\Theme(
			'existing-theme',
			'Existing Theme',
			'https://themes.example.test/existing.css'
		);
		$themes         = $this->companion->presenter_theme_registry(
			array( 'existing-theme' => $existing_theme )
		);

		$this->assertSame( $existing_theme, $themes['existing-theme'] );
		$this->assertArrayHasKey( 'aaron-purple', $themes );
		$this->assertInstanceOf( \Presenter\Theme::class, $themes['aaron-purple'] );
		$this->assertSame( 'aaron-purple', $themes['aaron-purple']->id() );
		$this->assertSame( 'Aaron Purple', $themes['aaron-purple']->label() );
		$this->assertSame(
			plugins_url( 'aaron-purple/aaron-purple.css', $this->companion_plugin_file ),
			$themes['aaron-purple']->stylesheet_url()
		);
		$this->assertSame(
			'aaron-purple',
			$this->companion->presenter_default_theme_id( 'black', $themes )
		);
		$this->assertSame(
			'black',
			$this->companion->presenter_default_theme_id(
				'black',
				array( 'existing-theme' => $existing_theme )
			)
		);
	}

	/**
	 * A theme URL saved before the companion moved is rewritten to its plugin URL.
	 */
	public function test_companion_migrates_only_its_historical_theme_url(): void {
		$historical_url = content_url( '/themes/aarondcampbell/presenter/aaron-purple/aaron-purple.css' );
		$expected_url   = plugins_url(
			'aaron-purple/aaron-purple.css',
			$this->companion_plugin_file
		);

		$this->assertSame( $expected_url, $this->companion->presenter_theme( $historical_url ) );
		$this->assertSame(
			'https://themes.example.test/unrelated.css',
			$this->companion->presenter_theme( 'https://themes.example.test/unrelated.css' )
		);
	}

	/**
	 * Chart.js replaces the optional Math plugin in Reveal's dependency list.
	 */
	public function test_companion_registers_chart_plugin_and_removes_math_dependency(): void {
		$dependencies = $this->companion->presenter_reveal_js_dependencies(
			array( 'RevealMarkdown', 'RevealMath', 'RevealNotes' )
		);
		$chart_plugin = wp_scripts()->query( 'RevealChartjs', 'registered' );

		$this->assertSame(
			array( 'RevealMarkdown', 'RevealNotes', 'RevealChartjs' ),
			array_values( $dependencies )
		);
		$this->assertInstanceOf( _WP_Dependency::class, $chart_plugin );
		$this->assertSame(
			plugins_url(
				'js/chartjs-plugin.js',
				$this->companion_plugin_file
			),
			$chart_plugin->src
		);
		$this->assertSame( '1.1.0', $chart_plugin->ver );
		$this->assertSame( 1, $chart_plugin->extra['group'] );
	}

	/**
	 * The site theme disables both foreground and background transitions.
	 */
	public function test_companion_disables_reveal_transitions_without_losing_other_settings(): void {
		$settings = (object) array(
			'controls'             => true,
			'transition'           => 'slide',
			'backgroundTransition' => 'fade',
		);

		$filtered_settings = $this->companion->presenter_init_object( $settings );

		$this->assertSame( $settings, $filtered_settings );
		$this->assertTrue( $filtered_settings->controls );
		$this->assertSame( 'none', $filtered_settings->transition );
		$this->assertSame( 'none', $filtered_settings->backgroundTransition ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Reveal.js owns this public configuration key.
	}

	/**
	 * Anonymous slideshow archives exclude password-protected decks.
	 */
	public function test_companion_excludes_protected_decks_from_anonymous_slideshow_archives(): void {
		$query                          = new WP_Query();
		$query->is_post_type_archive    = true;
		$query->query_vars['post_type'] = 'slideshow';

		$this->companion->hide_password_protected_slideshows( $query );

		$this->assertFalse( $query->get( 'has_password' ) );
	}

	/**
	 * Administrators retain the unmodified slideshow archive query.
	 */
	public function test_companion_does_not_restrict_slideshow_archive_for_administrators(): void {
		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator_id );

		$query                          = new WP_Query();
		$query->is_post_type_archive    = true;
		$query->query_vars['post_type'] = 'slideshow';

		$this->companion->hide_password_protected_slideshows( $query );

		$this->assertSame( '', $query->get( 'has_password' ) );
	}

	/**
	 * The persistent footer retains its stable class, destination, and accessible label.
	 */
	public function test_companion_outputs_persistent_footer_markup(): void {
		ob_start();
		$this->companion->presenter_reveal_footer( null );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'class="persistent-twitter-link"', $output );
		$this->assertStringContainsString( 'href="https://twitter.com/aaroncampbell/"', $output );
		$this->assertStringContainsString( '<title>Twitter</title>', $output );
		$this->assertStringContainsString( '@AaronCampbell', $output );
	}

	/**
	 * Remove every hook installed by the companion singleton.
	 */
	private function remove_companion_hooks(): void {
		remove_filter( 'presenter-theme-directories', array( $this->companion, 'add_theme_location' ), 10 );
		remove_filter( 'presenter-reveal-footer', array( $this->companion, 'presenter_reveal_footer' ), 10 );
		remove_filter( 'presenter-default-theme', array( $this->companion, 'presenter_default_theme' ), 10 );
		remove_filter( 'presenter-theme', array( $this->companion, 'presenter_theme' ), 10 );
		remove_filter( 'presenter_theme_registry', array( $this->companion, 'presenter_theme_registry' ), 10 );
		remove_filter( 'presenter_default_theme_id', array( $this->companion, 'presenter_default_theme_id' ), 10 );
		remove_filter( 'presenter-init-object', array( $this->companion, 'presenter_init_object' ), 10 );
		remove_filter( 'presenter-reveal-js-dependencies', array( $this->companion, 'presenter_reveal_js_dependencies' ), 10 );
		remove_filter( 'pre_get_posts', array( $this->companion, 'hide_password_protected_slideshows' ), 10 );
	}
}

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
	 * @var \AaronCampbell\PresenterThemes\Plugin
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
		wp_dequeue_script( 'RevealChartjs' );
		wp_deregister_script( 'RevealChartjs' );
		wp_dequeue_script( 'aaron-presenter-chartjs' );
		wp_deregister_script( 'aaron-presenter-chartjs' );
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
			array(
				'/plugins/aarondcampbell-presenter-themes/aaron-purple/aaron-purple.css',
				'/themes/aarondcampbell/presenter/aaron-purple/aaron-purple.css',
			),
			$themes['aaron-purple']->legacy_aliases()
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
	 * The companion owns resolution of both Aaron Purple storage locations.
	 */
	public function test_companion_legacy_aliases_resolve_to_aaron_purple(): void {
		add_filter( 'presenter_theme_registry', array( $this->companion, 'presenter_theme_registry' ) );

		try {
			$registry = presenter_get_runtime()->themes();

			$this->assertSame(
				'aaron-purple',
				$registry->resolve_legacy_theme_id(
					'/plugins/aarondcampbell-presenter-themes/aaron-purple/aaron-purple.css'
				)
			);
			$this->assertSame(
				'aaron-purple',
				$registry->resolve_legacy_theme_id(
					'/themes/aarondcampbell/presenter/aaron-purple/aaron-purple.css'
				)
			);
		} finally {
			remove_filter( 'presenter_theme_registry', array( $this->companion, 'presenter_theme_registry' ) );
		}
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
		$this->assertSame( '1.3.0', $chart_plugin->ver );
		$this->assertSame( 1, $chart_plugin->extra['group'] );
	}

	/**
	 * The companion subscribes to Presenter's native plugin seam with the post context.
	 */
	public function test_companion_registers_native_reveal_plugin_hook_contract(): void {
		global $wp_filter;

		$companion = new \AaronCampbell\PresenterThemes\Plugin( $this->companion_plugin_file );
		$companion->register_hooks();

		try {
			$callbacks = $wp_filter['presenter_reveal_plugins']->callbacks[10] ?? array();
			$matches   = array_values(
				array_filter(
					$callbacks,
					static function ( array $registered ) use ( $companion ): bool {
						return isset( $registered['function'][0], $registered['function'][1] )
							&& $companion === $registered['function'][0]
							&& 'presenter_reveal_plugins' === $registered['function'][1];
					}
				)
			);

			$this->assertCount( 1, $matches );
			$this->assertSame( 2, $matches[0]['accepted_args'] );
		} finally {
			$this->remove_companion_hooks( $companion );
		}
	}

	/**
	 * Native decks replace Math with one Chart plugin loaded after Presenter.
	 */
	public function test_companion_configures_native_chart_plugin_contract(): void {
		$post = self::factory()->post->create_and_get( array( 'post_type' => 'slideshow' ) );

		$plugins = $this->companion->presenter_reveal_plugins(
			array_merge(
				\Presenter\Reveal_Config::default_plugins(),
				array( 'math', 'chartjs', 'math', 'chartjs' )
			),
			$post
		);
		$script  = wp_scripts()->query( 'aaron-presenter-chartjs', 'registered' );

		$this->assertSame(
			array( 'markdown', 'search', 'notes', 'zoom', 'highlight', 'chartjs' ),
			array_values( $plugins )
		);
		$this->assertSame( 1, count( array_keys( $plugins, 'chartjs', true ) ) );
		$this->assertSame( 'chartjs', end( $plugins ) );
		$this->assertInstanceOf( _WP_Dependency::class, $script );
		$this->assertSame(
			plugins_url( 'js/chartjs-plugin.js', $this->companion_plugin_file ),
			$script->src
		);
		$this->assertSame( array( 'presenter-frontend' ), $script->deps );
		$this->assertSame( 1, $script->extra['group'] );
		$this->assertSame( 'defer', $script->extra['strategy'] );
		$this->assertTrue( wp_script_is( 'aaron-presenter-chartjs', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'RevealChartjs', 'registered' ) );
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
	 * Slideshow archives exclude password-protected decks for visitors without
	 * site-management access.
	 */
	public function test_companion_excludes_protected_decks_from_anonymous_slideshow_archives(): void {
		global $wp_the_query;

		$previous_main_query            = $wp_the_query;
		$query                          = new WP_Query();
		$query->is_post_type_archive    = true;
		$query->query_vars['post_type'] = 'slideshow';
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WP_Query::is_main_query() requires the test fixture to own the main-query global.
		$wp_the_query = $query;

		try {
			$this->companion->hide_password_protected_slideshows( $query );

			$this->assertFalse( $query->get( 'has_password' ) );
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the WordPress test suite's main query.
			$wp_the_query = $previous_main_query;
		}
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
	 * Remove every hook installed by a companion instance.
	 *
	 * @param \AaronCampbell\PresenterThemes\Plugin|null $companion Companion instance.
	 */
	private function remove_companion_hooks( ?\AaronCampbell\PresenterThemes\Plugin $companion = null ): void {
		$companion = $companion ?? $this->companion;

		remove_filter( 'presenter-theme-directories', array( $companion, 'add_theme_location' ), 10 );
		remove_filter( 'presenter-reveal-footer', array( $companion, 'presenter_reveal_footer' ), 10 );
		remove_filter( 'presenter_editor_preview_footer', array( $companion, 'presenter_reveal_footer' ), 10 );
		remove_filter( 'presenter-default-theme', array( $companion, 'presenter_default_theme' ), 10 );
		remove_filter( 'presenter-theme', array( $companion, 'presenter_theme' ), 10 );
		remove_filter( 'presenter_theme_registry', array( $companion, 'presenter_theme_registry' ), 10 );
		remove_filter( 'presenter_default_theme_id', array( $companion, 'presenter_default_theme_id' ), 10 );
		remove_filter( 'presenter-init-object', array( $companion, 'presenter_init_object' ), 10 );
		remove_filter( 'presenter-reveal-js-dependencies', array( $companion, 'presenter_reveal_js_dependencies' ), 10 );
		remove_filter( 'presenter_reveal_plugins', array( $companion, 'presenter_reveal_plugins' ), 10 );
		remove_filter( 'pre_get_posts', array( $companion, 'hide_password_protected_slideshows' ), 10 );
	}
}

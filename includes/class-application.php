<?php
/**
 * Presenter application.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Coordinates the small services that make up the Presenter runtime.
 */
final class Application {
	/**
	 * Plugin context.
	 *
	 * @var Plugin_Context
	 */
	private Plugin_Context $context;

	/**
	 * Registered hook providers.
	 *
	 * @var array<int, Hook_Provider>
	 */
	private array $hook_providers;

	/**
	 * Read-only legacy slide source.
	 *
	 * @var Legacy_Slide_Source
	 */
	private Legacy_Slide_Source $legacy_slides;

	/**
	 * Content-bound legacy HTML trust policy.
	 *
	 * @var Legacy_HTML_Trust
	 */
	private Legacy_HTML_Trust $legacy_html_trust;

	/**
	 * Authoritative deck-mode resolver.
	 *
	 * @var Deck_Mode
	 */
	private Deck_Mode $deck_mode;

	/**
	 * Theme registry.
	 *
	 * @var Theme_Registry
	 */
	private Theme_Registry $themes;

	/**
	 * Presentation renderer.
	 *
	 * @var Presentation_Renderer
	 */
	private Presentation_Renderer $renderer;

	/**
	 * Whether hooks have already been registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Create the application.
	 *
	 * @param Plugin_Context        $context        Plugin context.
	 * @param Legacy_Slide_Source   $legacy_slides  Read-only legacy source.
	 * @param Legacy_HTML_Trust     $legacy_html_trust Content-bound legacy HTML trust policy.
	 * @param Deck_Mode             $deck_mode      Deck-mode resolver.
	 * @param Theme_Registry        $themes         Theme registry.
	 * @param Presentation_Renderer $renderer       Presentation renderer.
	 * @param Hook_Provider         ...$hook_providers Hook providers.
	 */
	public function __construct(
		Plugin_Context $context,
		Legacy_Slide_Source $legacy_slides,
		Legacy_HTML_Trust $legacy_html_trust,
		Deck_Mode $deck_mode,
		Theme_Registry $themes,
		Presentation_Renderer $renderer,
		Hook_Provider ...$hook_providers
	) {
		$this->context           = $context;
		$this->legacy_slides     = $legacy_slides;
		$this->legacy_html_trust = $legacy_html_trust;
		$this->deck_mode         = $deck_mode;
		$this->themes            = $themes;
		$this->renderer          = $renderer;
		$this->hook_providers    = array_merge( array( $themes ), $hook_providers );
	}

	/**
	 * Register all WordPress hooks exactly once.
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		foreach ( $this->hook_providers as $hook_provider ) {
			$hook_provider->register_hooks();
		}

		$this->registered = true;
	}

	/**
	 * Get the plugin context.
	 *
	 * @return Plugin_Context Plugin context.
	 */
	public function context(): Plugin_Context {
		return $this->context;
	}

	/**
	 * Get the read-only Presenter 1.x slide source.
	 *
	 * @return Legacy_Slide_Source Legacy slide source.
	 */
	public function legacy_slides(): Legacy_Slide_Source {
		return $this->legacy_slides;
	}

	/**
	 * Get the content-bound legacy HTML trust policy.
	 *
	 * @return Legacy_HTML_Trust Legacy HTML trust policy.
	 */
	public function legacy_html_trust(): Legacy_HTML_Trust {
		return $this->legacy_html_trust;
	}

	/**
	 * Get the authoritative deck-mode resolver.
	 *
	 * @return Deck_Mode Deck-mode resolver.
	 */
	public function deck_mode(): Deck_Mode {
		return $this->deck_mode;
	}

	/**
	 * Get the presentation theme registry.
	 *
	 * @return Theme_Registry Theme registry.
	 */
	public function themes(): Theme_Registry {
		return $this->themes;
	}

	/**
	 * Get the presentation renderer.
	 *
	 * @return Presentation_Renderer Presentation renderer.
	 */
	public function renderer(): Presentation_Renderer {
		return $this->renderer;
	}
}

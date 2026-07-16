<?php
/**
 * Hook provider contract.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Registers one cohesive group of WordPress hooks.
 */
interface Hook_Provider {
	/**
	 * Register the provider's WordPress hooks.
	 *
	 * Implementations should register hooks only. WordPress should invoke the
	 * provider later to perform request-specific work.
	 */
	public function register_hooks(): void;
}

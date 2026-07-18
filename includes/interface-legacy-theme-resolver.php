<?php
/**
 * Legacy presentation theme resolution contract.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Resolves historical theme identities without guessing or substituting.
 */
interface Legacy_Theme_Resolver {
	/**
	 * Resolve one stored Presenter 1.x theme value.
	 *
	 * @param string $legacy_theme Historical stable ID, path, or URL.
	 * @return string|null Registered stable theme ID, or null when unresolved.
	 */
	public function resolve_legacy_theme_id( string $legacy_theme ): ?string;
}

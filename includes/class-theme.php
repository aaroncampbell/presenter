<?php
/**
 * Presenter theme value object.
 *
 * @package Presenter
 */

namespace Presenter;

use InvalidArgumentException;

/**
 * Describes one registered presentation theme.
 */
final class Theme {
	/**
	 * Stable theme ID.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Human-readable label.
	 *
	 * @var string
	 */
	private string $label;

	/**
	 * Public stylesheet URL.
	 *
	 * @var string
	 */
	private string $stylesheet_url;

	/**
	 * Create a theme.
	 *
	 * @param string $id             Stable lowercase ID.
	 * @param string $label          Human-readable label.
	 * @param string $stylesheet_url Public stylesheet URL.
	 * @throws InvalidArgumentException When a required theme value is invalid.
	 */
	public function __construct( string $id, string $label, string $stylesheet_url ) {
		if ( 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id ) ) {
			throw new InvalidArgumentException( 'Presenter theme IDs must be lowercase slugs.' );
		}

		if ( '' === trim( $label ) || '' === trim( $stylesheet_url ) ) {
			throw new InvalidArgumentException( 'Presenter themes require a label and stylesheet URL.' );
		}

		$this->id             = $id;
		$this->label          = $label;
		$this->stylesheet_url = $stylesheet_url;
	}

	/**
	 * Get the stable theme ID.
	 *
	 * @return string Theme ID.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Get the human-readable label.
	 *
	 * @return string Theme label.
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Get the public stylesheet URL.
	 *
	 * @return string Stylesheet URL.
	 */
	public function stylesheet_url(): string {
		return $this->stylesheet_url;
	}
}

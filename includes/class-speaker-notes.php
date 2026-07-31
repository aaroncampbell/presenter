<?php
/**
 * Speaker notes formats and HTML policy.
 *
 * @package Presenter
 */

namespace Presenter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies Presenter's explicit security policy to speaker notes.
 */
final class Speaker_Notes {
	/**
	 * Standard and historically common HTML elements recognized in legacy text.
	 *
	 * The parser also tokenizes custom-looking angle-bracket notation. Requiring
	 * a known element keeps prose such as `Array<string>` from becoming HTML.
	 *
	 * @var list<string>
	 */
	private const HTML_ELEMENTS = array(
		'a',
		'abbr',
		'acronym',
		'address',
		'applet',
		'area',
		'article',
		'aside',
		'audio',
		'b',
		'base',
		'basefont',
		'bdi',
		'bdo',
		'big',
		'blockquote',
		'body',
		'br',
		'button',
		'canvas',
		'caption',
		'center',
		'cite',
		'code',
		'col',
		'colgroup',
		'data',
		'datalist',
		'dd',
		'del',
		'details',
		'dfn',
		'dialog',
		'dir',
		'div',
		'dl',
		'dt',
		'em',
		'embed',
		'fieldset',
		'figcaption',
		'figure',
		'font',
		'footer',
		'form',
		'frame',
		'frameset',
		'h1',
		'h2',
		'h3',
		'h4',
		'h5',
		'h6',
		'head',
		'header',
		'hgroup',
		'hr',
		'html',
		'i',
		'iframe',
		'img',
		'input',
		'ins',
		'kbd',
		'label',
		'legend',
		'li',
		'link',
		'main',
		'map',
		'mark',
		'marquee',
		'menu',
		'meta',
		'meter',
		'nav',
		'noframes',
		'noscript',
		'object',
		'ol',
		'optgroup',
		'option',
		'output',
		'p',
		'param',
		'picture',
		'pre',
		'progress',
		'q',
		'rp',
		'rt',
		'ruby',
		's',
		'samp',
		'script',
		'search',
		'section',
		'select',
		'slot',
		'small',
		'source',
		'span',
		'strike',
		'strong',
		'style',
		'sub',
		'summary',
		'sup',
		'table',
		'tbody',
		'td',
		'template',
		'textarea',
		'tfoot',
		'th',
		'thead',
		'time',
		'title',
		'tr',
		'track',
		'tt',
		'u',
		'ul',
		'var',
		'video',
		'wbr',
	);

	/**
	 * Return the HTML elements and attributes allowed in speaker notes.
	 *
	 * This deliberately excludes active and embedded content, inline styles,
	 * IDs, and Reveal.js data attributes. Keep this policy stable: migration
	 * uses it as a lossless representation contract.
	 *
	 * @return array<string, array<string, bool>> Allowed HTML policy.
	 */
	public function allowed_html(): array {
		return array(
			'a'          => array(
				'href'   => true,
				'rel'    => true,
				'target' => true,
				'title'  => true,
			),
			'abbr'       => array( 'title' => true ),
			'b'          => array(),
			'blockquote' => array( 'cite' => true ),
			'br'         => array(),
			'cite'       => array(),
			'code'       => array(),
			'del'        => array( 'datetime' => true ),
			'div'        => array( 'class' => true ),
			'em'         => array(),
			'footer'     => array(),
			'h1'         => array(),
			'h2'         => array(),
			'h3'         => array(),
			'h4'         => array(),
			'h5'         => array(),
			'h6'         => array(),
			'hr'         => array(),
			'i'          => array(),
			'kbd'        => array(),
			'li'         => array(),
			'mark'       => array(),
			'ol'         => array(
				'reversed' => true,
				'start'    => true,
				'type'     => true,
			),
			'p'          => array(),
			'pre'        => array(),
			'q'          => array( 'cite' => true ),
			's'          => array(),
			'samp'       => array(),
			'small'      => array(),
			'span'       => array( 'class' => true ),
			'strong'     => array(),
			'sub'        => array(),
			'sup'        => array(),
			'ul'         => array(),
			'var'        => array(),
		);
	}

	/**
	 * Sanitize authored HTML notes using the stable notes policy.
	 *
	 * @param string $notes Authored notes.
	 * @return string Sanitized notes.
	 */
	public function sanitize_html( string $notes ): string {
		return wp_kses( $notes, $this->allowed_html() );
	}

	/**
	 * Determine whether HTML notes survive the notes policy byte-for-byte.
	 *
	 * Migration must use this check before selecting the HTML notes format. A
	 * false result means conversion would alter the legacy source and must be
	 * blocked for explicit review.
	 *
	 * @param string $notes Legacy HTML notes.
	 * @return bool Whether sanitization preserves the exact input.
	 */
	public function allows_lossless_html( string $notes ): bool {
		return $this->sanitize_html( $notes ) === $notes;
	}

	/**
	 * Determine whether notes contain an actual HTML element.
	 *
	 * Parsing avoids treating angle brackets in prose, comparisons, or generic
	 * type notation as HTML merely because a tag-stripping function changes
	 * them.
	 *
	 * @param string $notes Authored or legacy notes.
	 * @return bool Whether the fragment contains an HTML tag token.
	 */
	public function contains_html( string $notes ): bool {
		$processor = \WP_HTML_Processor::create_fragment( $notes );
		if ( null === $processor ) {
			return false;
		}

		while ( $processor->next_token() ) {
			$tag = $processor->get_tag();
			if ( null !== $tag && in_array( strtolower( $tag ), self::HTML_ELEMENTS, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render a notes container for a supported format.
	 *
	 * @param mixed $notes                         Notes value.
	 * @param mixed $format                        Notes format.
	 * @param bool  $legacy_markdown_compatibility Whether the browser should use legacy Markdown rendering.
	 * @param bool  $legacy_auto_paragraph         Whether to restore the legacy notes-only wpautop stage.
	 * @return string Notes markup.
	 */
	public function render( mixed $notes, mixed $format, bool $legacy_markdown_compatibility = false, bool $legacy_auto_paragraph = false ): string {
		if ( ! is_string( $notes ) || '' === $notes ) {
			return '';
		}

		if ( in_array( $format, array( 'html', 'markdown-html' ), true ) ) {
			$markdown = 'markdown-html' === $format ? ' data-markdown=""' : '';
			$legacy   = 'markdown-html' === $format && $legacy_markdown_compatibility ? ' data-presenter-legacy-markdown=""' : '';
			$content  = $this->sanitize_html( $notes );
			if ( $legacy_auto_paragraph ) {
				$content = wpautop( $content );
			}

			return '<aside class="notes"' . $markdown . $legacy . '>' . $content . '</aside>';
		}

		$markdown = 'markdown' === $format ? ' data-markdown=""' : '';
		$legacy   = 'markdown' === $format && $legacy_markdown_compatibility ? ' data-presenter-legacy-markdown=""' : '';
		$content  = esc_html( $notes );
		if ( $legacy_auto_paragraph ) {
			$content = wpautop( $content );
		}

		return '<aside class="notes"' . $markdown . $legacy . '>' . $content . '</aside>';
	}
}

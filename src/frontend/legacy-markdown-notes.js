import { marked } from 'marked-legacy';

export const LEGACY_MARKDOWN_NOTES_SELECTOR =
	'aside.notes[data-presenter-legacy-markdown]';

const CODE_LINE_NUMBER_REGEX = /\[([\s\d,|-]*)\]/u;
const HTML_ESCAPE_MAP = {
	'&': '&amp;',
	'<': '&lt;',
	'>': '&gt;',
	'"': '&quot;',
	"'": '&#39;',
};
const renderer = new marked.Renderer();

const escapeHtml = ( value ) =>
	value.replace( /[&<>'"]/gu, ( character ) => HTML_ESCAPE_MAP[ character ] );

renderer.code = ( code, language = '' ) => {
	let lineNumbers = '';

	if ( CODE_LINE_NUMBER_REGEX.test( language ) ) {
		lineNumbers = language.match( CODE_LINE_NUMBER_REGEX )[ 1 ].trim();
		lineNumbers = `data-line-numbers="${ lineNumbers }"`;
		language = language.replace( CODE_LINE_NUMBER_REGEX, '' ).trim();
	}

	code = escapeHtml( code );

	return `<pre><code ${ lineNumbers } class="${ language }">${ code }</code></pre>`;
};
renderer.html = escapeHtml;

marked.setOptions( { renderer } );

/**
 * Read Markdown using the whitespace normalization shipped with Reveal 4.
 *
 * @param {Element} notes Speaker notes element.
 * @return {string} Normalized Markdown source.
 */
function legacyMarkdownSource( notes ) {
	let source = notes.textContent || '';
	const leadingWhitespace = source.match( /^\n?(\s*)/u )[ 1 ].length;
	const leadingTabs = source.match( /^\n?(\t*)/u )[ 1 ].length;

	if ( leadingTabs > 0 ) {
		source = source.replace(
			new RegExp( `\\n?\\t{${ leadingTabs }}`, 'gu' ),
			'\n'
		);
	} else if ( leadingWhitespace > 1 ) {
		source = source.replace(
			new RegExp( `\\n? {${ leadingWhitespace }}`, 'gu' ),
			'\n'
		);
	}

	return source;
}

/**
 * Render migrated Markdown notes with the parser bundled in Reveal 4.
 *
 * The data-markdown attribute is temporarily removed so Reveal 6 does not
 * parse the compatibility output a second time.
 *
 * @param {Document|Element} root Document or element containing the deck.
 */
export function prepareLegacyMarkdownNotes( root ) {
	for ( const notes of root.querySelectorAll(
		LEGACY_MARKDOWN_NOTES_SELECTOR
	) ) {
		notes.innerHTML = marked.parse( legacyMarkdownSource( notes ) );
		notes.removeAttribute( 'data-markdown' );
	}
}

/**
 * Restore the public Markdown flag and remove the private migration marker.
 *
 * @param {Document|Element} root Document or element containing the deck.
 */
export function finishLegacyMarkdownNotes( root ) {
	for ( const notes of root.querySelectorAll(
		LEGACY_MARKDOWN_NOTES_SELECTOR
	) ) {
		notes.setAttribute( 'data-markdown', '' );
		notes.setAttribute( 'data-markdown-parsed', 'true' );
		notes.removeAttribute( 'data-presenter-legacy-markdown' );
	}
}

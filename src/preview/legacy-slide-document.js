import { getPreviewBackgroundImageUrl } from '../blocks/slide/settings';

const PREVIEW_LAYOUT_CSS = `
html,body,.reveal-viewport,.reveal,.slides{box-sizing:border-box;width:100%;height:100%;margin:0;}
html,body{overflow:hidden;}
.reveal-viewport{position:relative;overflow:hidden;}
.presenter-preview-background{position:absolute;inset:0;background-position:center;background-repeat:no-repeat;background-size:cover;}
.reveal{position:relative;overflow:hidden;}
.reveal .slides{position:absolute;inset:0;text-align:center;}
.reveal .slides>section{box-sizing:border-box;display:block;position:absolute;top:50%;width:100%;margin:0;transform:translateY(-50%);}
.reveal .slides>section.presenter-preview-top{top:0;transform:none;}
`;

/**
 * Escape text placed inside one style element.
 *
 * @param {string} value CSS text.
 * @return {string} Safe style-element text.
 */
function escapeStyleText( value ) {
	return value.replace( /<\/style/giu, '<\\/style' );
}

/**
 * Escape one HTML attribute value.
 *
 * @param {string} value Attribute value.
 * @return {string} Escaped attribute value.
 */
function escapeAttribute( value ) {
	return value
		.replaceAll( '&', '&amp;' )
		.replaceAll( '"', '&quot;' )
		.replaceAll( '<', '&lt;' )
		.replaceAll( '>', '&gt;' );
}

/**
 * Build controlled background declarations for an isolated preview.
 *
 * @param {Object} attributes Slide attributes.
 * @return {string} Inline background declarations.
 */
function backgroundStyle( attributes ) {
	const declarations = [];
	const backgroundImageUrl = getPreviewBackgroundImageUrl( attributes );

	if ( attributes.backgroundColor ) {
		declarations.push( `background-color:${ attributes.backgroundColor }` );
	}
	if ( backgroundImageUrl ) {
		const url = backgroundImageUrl
			.replaceAll( '\\', '\\\\' )
			.replaceAll( '"', '\\"' );
		declarations.push( `background-image:url("${ url }")` );
		declarations.push(
			`background-position:${ attributes.backgroundPosition || 'center' }`
		);
		declarations.push(
			`background-repeat:${ attributes.backgroundRepeat || 'no-repeat' }`
		);
		declarations.push(
			`background-size:${ attributes.backgroundSize || 'cover' }`
		);
	}
	if ( undefined !== attributes.backgroundOpacity ) {
		declarations.push( `opacity:${ attributes.backgroundOpacity }` );
	}

	return declarations.join( ';' );
}

/**
 * Build a script-disabled Reveal document for one migrated HTML slide.
 *
 * @param {Object}  props            Preview properties.
 * @param {Object}  props.attributes Slide attributes.
 * @param {boolean} props.center     Whether the deck centers slides.
 * @param {string}  props.footerHtml Trusted presentation footer markup.
 * @param {string}  props.html       Serialized slide content.
 * @param {string}  props.themeCss   Rebased Reveal theme CSS.
 * @return {string} Complete iframe document.
 */
export function buildLegacySlidePreviewDocument( {
	attributes,
	center,
	footerHtml,
	html,
	themeCss,
} ) {
	const classNames = [
		attributes.className || '',
		center ? '' : 'presenter-preview-top',
	]
		.filter( Boolean )
		.join( ' ' );
	const background = backgroundStyle( attributes );

	return `<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="script-src 'none'; object-src 'none'; form-action 'none'; base-uri 'none'"><style>${ escapeStyleText(
		PREVIEW_LAYOUT_CSS
	) }</style><style>${ escapeStyleText(
		themeCss
	) }</style></head><body><div class="reveal-viewport"><div class="presenter-preview-background" style="${ escapeAttribute(
		background
	) }"></div><div class="reveal"><div class="slides"><section class="${ escapeAttribute(
		classNames
	) }">${ html }</section></div>${ footerHtml }</div></div></body></html>`;
}

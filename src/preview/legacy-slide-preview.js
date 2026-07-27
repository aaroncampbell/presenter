import { useResizeObserver } from '@wordpress/compose';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { fetchThemeStylesheet } from '../blocks/deck/theme-preview';
import { buildLegacySlidePreviewDocument } from './legacy-slide-document';

/**
 * Render one migrated HTML slide at authored dimensions and scale it as a unit.
 *
 * @param {Object}           props            Preview properties.
 * @param {Object}           props.attributes Slide attributes.
 * @param {boolean}          props.center     Whether the deck centers slides.
 * @param {string}           props.footerHtml Trusted presentation footer markup.
 * @param {number}           props.height     Authored deck height.
 * @param {string}           props.html       Serialized slide content.
 * @param {Object|undefined} props.theme      Resolved theme description.
 * @param {number}           props.width      Authored deck width.
 * @return {Element} Isolated visual preview.
 */
export default function LegacySlidePreview( {
	attributes,
	center,
	footerHtml = '',
	height,
	html,
	theme,
	width,
} ) {
	const [ themeCss, setThemeCss ] = useState( null );
	const [ resizeListener, size ] = useResizeObserver();

	useEffect( () => {
		let isCurrent = true;

		setThemeCss( null );
		if ( ! theme?.stylesheetUrl ) {
			setThemeCss( '' );
			return () => {
				isCurrent = false;
			};
		}

		fetchThemeStylesheet( theme.stylesheetUrl )
			.then( ( css ) => {
				if ( isCurrent ) {
					setThemeCss( css );
				}
			} )
			.catch( () => {
				if ( isCurrent ) {
					setThemeCss( '' );
				}
			} );

		return () => {
			isCurrent = false;
		};
	}, [ theme?.stylesheetUrl ] );

	const document = useMemo(
		() =>
			buildLegacySlidePreviewDocument( {
				attributes,
				center,
				footerHtml,
				html,
				themeCss: themeCss ?? '',
			} ),
		[ attributes, center, footerHtml, html, themeCss ]
	);
	const scale = size.width ? size.width / width : 0;

	return (
		<div
			className="presenter-legacy-slide-preview"
			aria-hidden="true"
			style={ {
				'--presenter-preview-height': `${ height }px`,
				'--presenter-preview-width': `${ width }px`,
			} }
		>
			<div className="presenter-legacy-slide-preview-observer">
				{ resizeListener }
			</div>
			{ 0 < scale && null !== themeCss && (
				<iframe
					title={ __( 'Slide preview', 'presenter' ) }
					referrerPolicy="no-referrer"
					sandbox=""
					srcDoc={ document }
					tabIndex={ -1 }
					style={ {
						transform: `scale(${ scale })`,
					} }
				/>
			) }
		</div>
	);
}

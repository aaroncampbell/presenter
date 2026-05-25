/**
 * Adds a "Presentation Settings" panel to the slideshow document sidebar.
 *
 * Lets the author pick a reveal.js theme (with a live preview in the editor)
 * and set an optional short URL. Both values are stored as post meta and saved
 * through the REST API.
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { SelectControl, TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

const PresenterSettingsPanel = () => {
	const { editPost } = useDispatch( 'core/editor' );

	// presenterData is provided from PHP via wp_localize_script().
	const themes = ( window.presenterData && window.presenterData.themes ) || [];

	const [ presenterStylesheet, setPresenterStylesheet ] = useState(
		( window.presenterData && window.presenterData.theme ) || ''
	);
	const [ presenterShortURL, setPresenterShortURL ] = useState(
		( window.presenterData && window.presenterData.short_url ) || ''
	);

	// Inject the selected theme stylesheet so the editor previews it live.
	useEffect( () => {
		const managedDocuments = new Set();
		const managedLinks = new Map();
		const backgroundProperties = [
			'background-color',
			'background-image',
			'background-position',
			'background-repeat',
			'background-size',
		];

		const getCanvasDocuments = () => {
			const iframeDocuments = Array.from(
				document.querySelectorAll( 'iframe' )
			)
				.map( ( iframe ) => {
					try {
						return iframe.contentDocument;
					} catch {
						return null;
					}
				} )
				.filter(
					( iframeDocument ) =>
						iframeDocument &&
						iframeDocument.body &&
						iframeDocument.body.querySelector(
							'.block-editor-block-list__layout, .presenter-slide-viewport'
						)
				);

			return iframeDocuments.length ? iframeDocuments : [ document ];
		};

		const clearThemeBackground = ( targetDocument ) => {
			backgroundProperties.forEach( ( property ) => {
				targetDocument.documentElement.style.removeProperty(
					`--presenter-editor-theme-${ property }`
				);
			} );
		};

		const syncThemeBackground = ( targetDocument ) => {
			const bodyStyle =
				targetDocument.defaultView.getComputedStyle( targetDocument.body );

			backgroundProperties.forEach( ( property ) => {
				targetDocument.documentElement.style.setProperty(
					`--presenter-editor-theme-${ property }`,
					bodyStyle.getPropertyValue( property )
				);
			} );
		};

		const removeManagedAssets = () => {
			managedDocuments.forEach( clearThemeBackground );
			managedLinks.forEach( ( link ) => link.remove() );
			managedDocuments.clear();
			managedLinks.clear();
		};

		const selected = themes.find(
			( theme ) => theme.value === presenterStylesheet
		);
		if ( ! selected ) {
			removeManagedAssets();
			return undefined;
		}

		const syncThemeAssets = () => {
			const canvasDocuments = getCanvasDocuments();

			managedDocuments.forEach( ( targetDocument ) => {
				if ( ! canvasDocuments.includes( targetDocument ) ) {
					clearThemeBackground( targetDocument );
					managedLinks.get( targetDocument )?.remove();
					managedDocuments.delete( targetDocument );
					managedLinks.delete( targetDocument );
				}
			} );

			canvasDocuments.forEach( ( targetDocument ) => {
				managedDocuments.add( targetDocument );

				let link = managedLinks.get( targetDocument );
				if ( ! link ) {
					link = targetDocument.createElement( 'link' );
					link.rel = 'stylesheet';
					link.type = 'text/css';
					link.title = 'presenter-editor-theme';
					link.addEventListener( 'load', () =>
						syncThemeBackground( targetDocument )
					);
					targetDocument.head.appendChild( link );
					managedLinks.set( targetDocument, link );
				}

				if ( link.href !== selected.url ) {
					link.href = selected.url;
				}

				syncThemeBackground( targetDocument );
			} );
		};

		syncThemeAssets();
		const syncInterval = window.setInterval( syncThemeAssets, 500 );

		editPost( { meta: { '_presenter-theme': presenterStylesheet } } );

		return () => {
			window.clearInterval( syncInterval );
			removeManagedAssets();
		};
	}, [ presenterStylesheet ] );

	useEffect( () => {
		editPost( { meta: { '_presenter-short-url': presenterShortURL } } );
	}, [ presenterShortURL ] );

	return (
		<PluginDocumentSettingPanel
			name="presenter-settings"
			title={ __( 'Presentation Settings', 'presenter' ) }
			className="presenter-settings"
		>
			<SelectControl
				label={ __( 'Theme', 'presenter' ) }
				value={ presenterStylesheet }
				options={ themes }
				onChange={ setPresenterStylesheet }
			/>
			<TextControl
				label={ __( 'Short URL', 'presenter' ) }
				value={ presenterShortURL }
				onChange={ setPresenterShortURL }
			/>
		</PluginDocumentSettingPanel>
	);
};

registerPlugin( 'presenter-settings-plugin', {
	render: PresenterSettingsPanel,
	icon: 'slides',
} );

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
		const selected = themes.find(
			( theme ) => theme.value === presenterStylesheet
		);
		if ( ! selected ) {
			return undefined;
		}

		const link = document.createElement( 'link' );
		link.rel = 'stylesheet';
		link.type = 'text/css';
		link.title = 'presenter-editor-theme';
		link.href = selected.url;
		document.head.appendChild( link );

		editPost( { meta: { '_presenter-theme': presenterStylesheet } } );

		return () => {
			document.head.removeChild( link );
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

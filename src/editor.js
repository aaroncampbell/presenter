import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { SelectControl, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect } from 'react';
import { __ } from '@wordpress/i18n';

 
const presenterSettingsPanel = () => {
	const { editPost } = useDispatch( 'core/editor' );

	// Theme is passed in the presenterData variable via PHP using localize script
	const [ presenterStylesheet, setPresenterStylesheet ] = useState( presenterData.theme );

	const [ presenterShortURL, setPresenterShortURL ] = useState( presenterData.short_url );

	useEffect(() => {
		var head = document.head;

		var link   = document.createElement('link');
		link.rel   = 'stylesheet';
		link.type  = 'text/css';
		link.title = 'presenter-editor-theme';
		link.href  = _.find( presenterData.themes, { value: presenterStylesheet } ).url;

		head.appendChild(link);

		editPost( {
			meta: { '_presenter-theme': presenterStylesheet },
		} );

		return () => { head.removeChild(link); }
	}, [presenterStylesheet]);

	useEffect(() => {
		editPost( {
			meta: { '_presenter-short-url': presenterShortURL },
		} );
	}, [presenterShortURL]);

	return (
		<PluginDocumentSettingPanel
			name="presenter-settings"
			title={ __( 'Presentation Settings', 'presenter' ) }
			className="presenter-settings"
		>
			<SelectControl
				label={ __( 'Theme', 'presenter' ) }
				value={ presenterStylesheet }
				options={ presenterData.themes }
				onChange={ setPresenterStylesheet }
			/>
			<TextControl
					label={ __( 'Short URL', 'presenter' ) }
					value={ presenterShortURL }
					onChange={ setPresenterShortURL }
			/>
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'presenter-settings-plugin', {
    render: presenterSettingsPanel,
    icon: 'slides',
} );

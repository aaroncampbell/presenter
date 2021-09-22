import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { SelectControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect } from 'react';
import { __ } from '@wordpress/i18n';

 
const presenterSettingsPanel = () => {
	const { editPost } = useDispatch( 'core/editor' );

	// If there is no theme specified in post meta, use the default (set via 'presenter-default-theme' filter in plugin)
	const [ presenterStylesheet, setPresenterStylesheet ] = useState( presenterThemeData.theme );

	useEffect(() => {
		var head = document.head;

		var link   = document.createElement('link');
		link.rel   = 'stylesheet';
		link.type  = 'text/css';
		link.title = 'presenter-editor-theme';
		link.href  = _.find( presenterThemeData.themes, { value: presenterStylesheet } ).url;

		head.appendChild(link);

		editPost( {
			meta: { '_presenter-theme': presenterStylesheet },
		} );

		return () => { head.removeChild(link); }
	}, [presenterStylesheet]);

	return (
		<PluginDocumentSettingPanel
			name="presenter-theme"
			title={ __( 'Presentation Theme', 'presenter' ) }
			className="presenter-theme"
		>
			<SelectControl
				label={ __( 'Theme', 'presenter' ) }
				value={ presenterStylesheet }
				options={ presenterThemeData.themes }
				onChange={ setPresenterStylesheet }
			/>
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'presenter-settings-plugin', {
    render: presenterSettingsPanel,
    icon: 'slides',
} );

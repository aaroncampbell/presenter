import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { SelectControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect } from 'react';

 
const PluginDocumentSettingPanelDemo = () => {
	console.info( 'presenterThemeData', presenterThemeData );

	// Get the background image id there is one
	const { postMeta } = useSelect( ( select ) => {
		return {
			postMeta: select( 'core/editor' ).getEditedPostAttribute( 'meta' )
		};
	});
	const { editPost } = useDispatch( 'core/editor' );

	// If there is no theme specified in post meta, use the default (set via 'presenter-default-theme' filter in plugin)
	const [ presenterStylesheet, setPresenterStylesheet ] = useState( postMeta['_presenter-theme'] || presenterThemeData.default );

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

	console.info( 'postMeta', postMeta );

	return (
		<PluginDocumentSettingPanel
			name="custom-panel"
			title="Custom Panel"
			className="custom-panel"
		>
			<SelectControl
				label="Size"
				value={ presenterStylesheet }
				options={ presenterThemeData.themes }
				onChange={ setPresenterStylesheet }
			/>
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'plugin-document-setting-panel-demo', {
    render: PluginDocumentSettingPanelDemo,
    icon: 'palmtree',
} );

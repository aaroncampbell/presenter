import { registerPlugin } from '@wordpress/plugins';

import SlideNavigator from './slide-navigator';

registerPlugin( 'presenter-slide-navigator', {
	render: SlideNavigator,
} );

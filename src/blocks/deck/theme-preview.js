import { transformStyles } from '@wordpress/block-editor';

const stylesheetRequests = new Map();

/**
 * Fetch and scope a Reveal theme for the Presenter editor canvas.
 *
 * Requests are cached by URL for the current editor session. Failed requests
 * are removed so a temporary network failure can be retried.
 *
 * @param {string}   stylesheetUrl Theme stylesheet URL.
 * @param {Function} fetchStyles   Fetch implementation.
 * @return {Promise<string>} Scoped CSS.
 */
export async function fetchThemePreview(
	stylesheetUrl,
	fetchStyles = window.fetch
) {
	if ( ! stylesheetRequests.has( stylesheetUrl ) ) {
		const request = fetchStyles( stylesheetUrl, {
			credentials: 'same-origin',
		} )
			.then( ( response ) => {
				if ( ! response.ok ) {
					throw new Error(
						`Theme stylesheet returned HTTP ${ response.status }.`
					);
				}

				return response.text();
			} )
			.then( ( css ) => {
				const [ transformed ] = transformStyles(
					[ { baseURL: stylesheetUrl, css } ],
					'.presenter-theme-preview'
				);

				if ( ! transformed ) {
					throw new Error( 'Theme stylesheet could not be scoped.' );
				}

				return transformed;
			} )
			.catch( ( error ) => {
				stylesheetRequests.delete( stylesheetUrl );
				throw error;
			} );

		stylesheetRequests.set( stylesheetUrl, request );
	}

	return stylesheetRequests.get( stylesheetUrl );
}

/**
 * Clear the module cache for focused tests.
 */
export function clearThemePreviewCache() {
	stylesheetRequests.clear();
}

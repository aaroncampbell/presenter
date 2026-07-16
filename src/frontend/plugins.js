const plugins = new Map( [
	[ 'highlight', () => import( 'reveal.js/plugin/highlight' ) ],
	[ 'markdown', () => import( 'reveal.js/plugin/markdown' ) ],
	[ 'math', () => import( 'reveal.js/plugin/math' ) ],
	[ 'notes', () => import( 'reveal.js/plugin/notes' ) ],
	[ 'search', () => import( 'reveal.js/plugin/search' ) ],
	[ 'zoom', () => import( 'reveal.js/plugin/zoom' ) ],
] );

let registrationClosed = false;

/**
 * Register a Reveal plugin supplied by a WordPress extension.
 *
 * Extension scripts should depend on the Presenter front-end asset and call
 * this before Presenter initializes at DOM ready.
 *
 * @param {string} id     Stable plugin ID used by server configuration.
 * @param {Object} plugin Reveal plugin object.
 */
export function registerPresenterRevealPlugin( id, plugin ) {
	if ( registrationClosed ) {
		throw new Error(
			'Presenter Reveal plugins must be registered before initialization.'
		);
	}

	if ( typeof id !== 'string' || id.trim() === '' ) {
		throw new Error( 'A Presenter Reveal plugin requires a stable ID.' );
	}

	if ( ! plugin || typeof plugin !== 'object' ) {
		throw new Error( 'A Presenter Reveal plugin must be an object.' );
	}

	const normalizedId = id.trim();

	if ( plugins.has( normalizedId ) ) {
		throw new Error(
			`A Presenter Reveal plugin is already registered as ${ normalizedId }.`
		);
	}

	plugins.set( normalizedId, () => Promise.resolve( { default: plugin } ) );
}

/**
 * Resolve configured plugin IDs and prevent subsequent registration.
 *
 * Built-in plugins are loaded on demand so an optional feature does not add to
 * the initial front-end payload.
 *
 * @param {string[]} pluginIds Ordered plugin IDs from server configuration.
 * @return {Promise<Object[]>} Reveal plugin objects.
 */
export async function resolvePresenterRevealPlugins( pluginIds ) {
	registrationClosed = true;

	return Promise.all(
		pluginIds.map( async ( pluginId ) => {
			const loadPlugin = plugins.get( pluginId );

			if ( ! loadPlugin ) {
				throw new Error(
					`Presenter Reveal plugin is not registered: ${ pluginId }.`
				);
			}

			const pluginModule = await loadPlugin();
			return pluginModule.default;
		} )
	);
}

/**
 * Read and normalize the server-provided editor theme registry.
 *
 * @param {Object} settings Candidate editor settings.
 * @return {Object} Normalized settings.
 */
export function normalizeThemeSettings( settings ) {
	const themes = Array.isArray( settings?.themes )
		? settings.themes.filter( isTheme )
		: [];
	const defaultTheme = isTheme( settings?.defaultTheme )
		? settings.defaultTheme
		: themes[ 0 ];

	return { defaultTheme, themes };
}

/**
 * Build the theme selector choices shown in the Deck inspector.
 *
 * @param {Object}   settings  Normalized editor settings.
 * @param {Function} translate Translation function.
 * @return {Array<Object>} Select options.
 */
export function getThemeOptions( settings, translate ) {
	const { defaultTheme, themes } = normalizeThemeSettings( settings );
	const defaultLabel = defaultTheme?.label
		? `${ translate( 'Site default', 'presenter' ) } (${
				defaultTheme.label
		  })`
		: translate( 'Site default', 'presenter' );

	return [
		{ label: defaultLabel, value: '' },
		...themes.map( ( theme ) => ( {
			label: theme.label,
			value: theme.id,
		} ) ),
	];
}

/**
 * Resolve a stored theme ID to the stylesheet used for its editor preview.
 *
 * @param {string} themeId  Stored Deck theme ID.
 * @param {Object} settings Server-provided editor settings.
 * @return {Object|undefined} Resolved theme.
 */
export function resolveTheme( themeId, settings ) {
	const { defaultTheme, themes } = normalizeThemeSettings( settings );

	if ( themeId ) {
		return themes.find( ( theme ) => theme.id === themeId ) ?? defaultTheme;
	}

	return defaultTheme;
}

/**
 * Get Presenter editor settings without assuming a browser during tests.
 *
 * @return {Object} Server-provided settings or an empty object.
 */
export function getGlobalThemeSettings() {
	return window.presenterEditorSettings ?? {};
}

/**
 * Confirm an object is a safe, usable theme description.
 *
 * @param {*} theme Candidate theme.
 * @return {boolean} Whether it is usable.
 */
function isTheme( theme ) {
	return (
		theme &&
		'object' === typeof theme &&
		'string' === typeof theme.id &&
		'' !== theme.id &&
		'string' === typeof theme.label &&
		'' !== theme.label &&
		'string' === typeof theme.stylesheetUrl &&
		/^https?:\/\//i.test( theme.stylesheetUrl )
	);
}

/**
 * The default presentation geometry and behavior for Presenter 2.0 decks.
 *
 * These values intentionally preserve the Presenter 1.x interaction defaults,
 * while using Reveal's current hash option and Presenter's 16:9 canvas.
 */
export const DEFAULT_REVEAL_CONFIG = Object.freeze( {
	width: 1280,
	height: 720,
	margin: 0.04,
	controls: true,
	progress: true,
	hash: true,
	center: true,
	keyboard: true,
	transition: 'slide',
	backgroundTransition: 'fade',
} );

/**
 * Plugins enabled when the server does not provide an explicit plugin list.
 */
export const DEFAULT_PLUGIN_IDS = Object.freeze( [
	'markdown',
	'search',
	'notes',
	'math',
	'zoom',
	'highlight',
] );

export const CONFIG_ELEMENT_SELECTOR =
	'script[type="application/json"][data-presenter-reveal-config]';

const UNSAFE_PROPERTY_NAMES = new Set( [
	'__proto__',
	'constructor',
	'prototype',
] );

/**
 * Determine whether a value is a plain JSON object.
 *
 * @param {*} value Candidate value.
 * @return {boolean} Whether the value is a plain object.
 */
function isPlainObject( value ) {
	return (
		value !== null && typeof value === 'object' && ! Array.isArray( value )
	);
}

/**
 * Copy parsed JSON without carrying prototype-sensitive property names.
 *
 * JSON is the only supported configuration transport. Functions, script
 * fragments, and JavaScript expressions therefore cannot enter this path.
 *
 * @param {*} value Parsed JSON value.
 * @return {*} A recursively copied JSON value.
 */
function copySafeJsonValue( value ) {
	if ( Array.isArray( value ) ) {
		return value.map( copySafeJsonValue );
	}

	if ( ! isPlainObject( value ) ) {
		return value;
	}

	return Object.entries( value ).reduce( ( result, [ key, item ] ) => {
		if ( UNSAFE_PROPERTY_NAMES.has( key ) ) {
			throw new Error(
				`Presenter Reveal configuration contains an unsupported property: ${ key }.`
			);
		}

		result[ key ] = copySafeJsonValue( item );
		return result;
	}, {} );
}

/**
 * Normalize a list of registered plugin IDs.
 *
 * @param {*} pluginIds Candidate plugin list.
 * @return {string[]} Unique plugin IDs in their configured order.
 */
function normalizePluginIds( pluginIds ) {
	if ( ! Array.isArray( pluginIds ) ) {
		throw new Error( 'Presenter Reveal plugins must be a JSON array.' );
	}

	const normalizedIds = pluginIds.map( ( pluginId ) => {
		if ( typeof pluginId !== 'string' || pluginId.trim() === '' ) {
			throw new Error(
				'Each Presenter Reveal plugin must have a non-empty string ID.'
			);
		}

		return pluginId.trim();
	} );

	return [ ...new Set( normalizedIds ) ];
}

/**
 * Parse a server-rendered Presenter configuration envelope.
 *
 * Expected JSON shape:
 *
 *     { "reveal": { "width": 1280 }, "plugins": [ "notes" ] }
 *
 * @param {string} source JSON source text.
 * @return {{ reveal: Object, plugins: string[] }} Normalized configuration.
 */
export function parsePresenterRevealConfig( source ) {
	let parsed;

	try {
		parsed = JSON.parse( source );
	} catch ( error ) {
		throw new Error( 'Presenter Reveal configuration is not valid JSON.', {
			cause: error,
		} );
	}

	if ( ! isPlainObject( parsed ) ) {
		throw new Error(
			'Presenter Reveal configuration must be a JSON object.'
		);
	}

	const revealConfig = parsed.reveal ?? {};
	const pluginIds = parsed.plugins ?? DEFAULT_PLUGIN_IDS;

	if ( ! isPlainObject( revealConfig ) ) {
		throw new Error( 'Presenter Reveal settings must be a JSON object.' );
	}

	return {
		reveal: {
			...DEFAULT_REVEAL_CONFIG,
			...copySafeJsonValue( revealConfig ),
		},
		plugins: normalizePluginIds( pluginIds ),
	};
}

/**
 * Read Presenter configuration from a non-executable JSON script element.
 *
 * @param {Document} documentObject Document containing the configuration.
 * @return {{ reveal: Object, plugins: string[] }} Normalized configuration.
 */
export function readPresenterRevealConfig( documentObject ) {
	const configElement = documentObject.querySelector(
		CONFIG_ELEMENT_SELECTOR
	);

	if ( ! configElement ) {
		return {
			reveal: { ...DEFAULT_REVEAL_CONFIG },
			plugins: [ ...DEFAULT_PLUGIN_IDS ],
		};
	}

	return parsePresenterRevealConfig( configElement.textContent || '{}' );
}

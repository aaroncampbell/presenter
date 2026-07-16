import { cleanForSlug } from '@wordpress/url';

/**
 * Normalize an authored anchor before it reaches persisted block attributes.
 *
 * @param {string} value Candidate anchor.
 * @return {string} URL-safe anchor compatible with WordPress slug handling.
 */
export function normalizeSlideAnchor( value ) {
	return cleanForSlug( value );
}

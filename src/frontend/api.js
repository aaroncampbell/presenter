/**
 * Install the public bridge once, even if the entry bundle is evaluated twice.
 *
 * @param {Window|Object} target Browser global or test target.
 * @param {Object}        api    Frozen Presenter Reveal API.
 * @return {boolean} Whether this call installed the API.
 */
export function installPresenterRevealApi( target, api ) {
	if ( Object.prototype.hasOwnProperty.call( target, 'presenterReveal' ) ) {
		return false;
	}

	Object.defineProperty( target, 'presenterReveal', {
		configurable: false,
		enumerable: true,
		value: api,
		writable: false,
	} );

	return true;
}

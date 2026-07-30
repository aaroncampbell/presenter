/**
 * External dependencies
 */
const AxeBuilder = require( '@axe-core/playwright' ).default;

/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const fixtureSlug = 'presenter-e2e-public-presentation';
const fixtureContent =
	'<!-- wp:presenter/deck {"controls":false,"progress":false,"theme":"white"} -->' +
	'<!-- wp:presenter/slide {"label":"Introduction","anchor":"introduction"} -->' +
	'<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Accessible Presenter deck</h1><!-- /wp:heading -->' +
	'<!-- wp:paragraph --><p>A deterministic public presentation.</p><!-- /wp:paragraph -->' +
	'<!-- /wp:presenter/slide -->' +
	'<!-- wp:presenter/slide {"label":"Conclusion","anchor":"conclusion"} -->' +
	'<!-- wp:heading --><h2 class="wp-block-heading">Conclusion</h2><!-- /wp:heading -->' +
	'<!-- wp:paragraph --><p>The second accessible slide.</p><!-- /wp:paragraph -->' +
	'<!-- /wp:presenter/slide -->' +
	'<!-- wp:presenter/slide {"label":"Hidden appendix","anchor":"hidden-appendix","hidden":true} -->' +
	'<!-- wp:paragraph --><p>This hidden slide must not be rendered.</p><!-- /wp:paragraph -->' +
	'<!-- /wp:presenter/slide -->' +
	'<!-- /wp:presenter/deck -->';

test.describe( 'public native presentation', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.activateTheme( 'twentytwentyfive' );
		await requestUtils.activatePlugin( 'presenter' );

		const fixtures = await requestUtils.rest( {
			path: '/wp/v2/slideshow',
			params: {
				context: 'edit',
				slug: fixtureSlug,
			},
		} );
		const payload = {
			content: fixtureContent,
			slug: fixtureSlug,
			status: 'publish',
			title: 'Presenter E2E Public Presentation',
		};
		const path = fixtures[ 0 ]
			? `/wp/v2/slideshow/${ fixtures[ 0 ].id }`
			: '/wp/v2/slideshow';

		await requestUtils.rest( {
			data: payload,
			method: 'POST',
			path,
		} );
	} );

	test( 'is keyboard-accessible and passes an automated WCAG scan', async ( {
		browserName,
		page,
	} ) => {
		const consoleErrors = [];
		const pageErrors = [];

		page.on( 'console', ( message ) => {
			if ( 'error' === message.type() ) {
				consoleErrors.push( message.text() );
			}
		} );
		page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );
		await page.context().clearCookies();

		const response = await page.goto( `/?slideshow=${ fixtureSlug }`, {
			waitUntil: 'networkidle',
		} );

		expect( response?.ok() ).toBe( true );
		await page.waitForFunction(
			() => window.presenterReveal?.getInstance()?.isReady() === true
		);
		expect(
			await page.evaluate(
				() =>
					window.matchMedia( '(prefers-reduced-motion: reduce)' )
						.matches
			)
		).toBe( true );
		await expect( page.locator( 'html' ) ).toHaveAttribute( 'lang', /.+/ );
		await expect( page ).toHaveTitle( /Presenter E2E Public Presentation/ );
		await expect( page.locator( 'head title' ) ).toHaveCount( 1 );
		await expect( page.locator( 'meta[name="viewport"]' ) ).toHaveCount(
			1
		);
		await expect(
			page.locator(
				'.aria-status[aria-live="polite"][aria-atomic="true"]'
			)
		).toHaveCount( 1 );
		await expect( page.locator( '#introduction' ) ).toHaveAttribute(
			'aria-label',
			'Introduction'
		);
		await expect( page.locator( '#hidden-appendix' ) ).toHaveCount( 0 );

		const skipLink = page.locator( '.skip-link' );

		if ( 'webkit' === browserName ) {
			// Playwright WebKit cannot enable Safari's full keyboard access setting.
			await skipLink.focus();
		} else {
			await page.keyboard.press( 'Tab' );
		}
		await expect( skipLink ).toBeFocused();
		await page.keyboard.press( 'Enter' );
		await expect( page.locator( '#presenter-presentation' ) ).toBeFocused();

		await page.keyboard.press( 'ArrowRight' );
		await expect( page.locator( '#conclusion' ) ).toHaveClass( /present/ );
		await expect( page.locator( '#conclusion' ) ).not.toHaveAttribute(
			'aria-hidden',
			'true'
		);
		await expect( page.locator( '#introduction' ) ).toHaveAttribute(
			'aria-hidden',
			'true'
		);

		const scan = await new AxeBuilder( { page } )
			.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ] )
			.analyze();

		expect( scan.violations ).toEqual( [] );
		expect( pageErrors ).toEqual( [] );
		expect( consoleErrors ).toEqual( [] );
	} );
} );

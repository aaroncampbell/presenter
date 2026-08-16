/**
 * External dependencies
 */
const AxeBuilder = require( '@axe-core/playwright' ).default;

/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const fixtureSlug = 'presenter-e2e-public-presentation';
const nestedFixtureSlug = 'presenter-e2e-nested-presentation';
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
const nestedFixtureContent =
	'<!-- wp:presenter/deck {"theme":"white"} -->' +
	'<!-- wp:presenter/slide {"label":"Before nested slides","anchor":"before-nested"} -->' +
	'<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Before nested slides</h1><!-- /wp:heading -->' +
	'<!-- /wp:presenter/slide -->' +
	'<!-- wp:presenter/stack {"anchor":"nested-group","label":"Nested case study"} -->' +
	'<!-- wp:presenter/slide {"label":"Nested overview","anchor":"nested-overview"} -->' +
	'<!-- wp:heading --><h2 class="wp-block-heading">Nested overview</h2><!-- /wp:heading -->' +
	'<!-- wp:paragraph --><p>The first nested slide.</p><!-- /wp:paragraph -->' +
	'<!-- /wp:presenter/slide -->' +
	'<!-- wp:presenter/slide {"label":"Nested findings","anchor":"nested-findings"} -->' +
	'<!-- wp:heading --><h2 class="wp-block-heading">Nested findings</h2><!-- /wp:heading -->' +
	'<!-- wp:paragraph --><p>Searchable nested runtime sentinel.</p><!-- /wp:paragraph -->' +
	'<!-- /wp:presenter/slide -->' +
	'<!-- /wp:presenter/stack -->' +
	'<!-- wp:presenter/slide {"label":"After nested slides","anchor":"after-nested"} -->' +
	'<!-- wp:heading --><h2 class="wp-block-heading">After nested slides</h2><!-- /wp:heading -->' +
	'<!-- /wp:presenter/slide -->' +
	'<!-- /wp:presenter/deck -->';

test.describe( 'public native presentation', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.activateTheme( 'twentytwentyfive' );
		await requestUtils.activatePlugin( 'presenter' );

		for ( const fixture of [
			{
				content: fixtureContent,
				slug: fixtureSlug,
				title: 'Presenter E2E Public Presentation',
			},
			{
				content: nestedFixtureContent,
				slug: nestedFixtureSlug,
				title: 'Presenter E2E Nested Presentation',
			},
		] ) {
			const fixtures = await requestUtils.rest( {
				path: '/wp/v2/slideshow',
				params: {
					context: 'edit',
					slug: fixture.slug,
				},
			} );
			const path = fixtures[ 0 ]
				? `/wp/v2/slideshow/${ fixtures[ 0 ].id }`
				: '/wp/v2/slideshow';

			await requestUtils.rest( {
				data: {
					content: fixture.content,
					slug: fixture.slug,
					status: 'publish',
					title: fixture.title,
				},
				method: 'POST',
				path,
			} );
		}
	} );

	test( 'supports nested runtime interactions across browsers', async ( {
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

		const response = await page.goto(
			`/?slideshow=${ nestedFixtureSlug }#/nested-overview`,
			{ waitUntil: 'networkidle' }
		);

		expect( response?.ok() ).toBe( true );
		await page.waitForFunction(
			() => window.presenterReveal?.getInstance()?.isReady() === true
		);
		await expect( page.locator( '#nested-overview' ) ).toHaveClass(
			/present/
		);

		const initial = await page.evaluate( () => {
			const instance = window.presenterReveal.getInstance();
			return {
				config: {
					controls: instance.getConfig().controls,
					progress: instance.getConfig().progress,
					rtl: instance.getConfig().rtl,
					touch: instance.getConfig().touch,
				},
				plugins: Object.keys( instance.getPlugins() ).sort(),
				progress: instance.getProgress(),
				readyMs: performance.now(),
				routes: instance.availableRoutes(),
				totalSlides: instance.getTotalSlides(),
			};
		} );
		expect( initial.config ).toEqual( {
			controls: true,
			progress: true,
			rtl: false,
			touch: true,
		} );
		expect( initial.plugins ).toEqual( [ 'notes', 'search', 'zoom' ] );
		expect( initial.readyMs ).toBeLessThan( 10_000 );
		expect( initial.routes.down ).toBe( true );
		expect( initial.totalSlides ).toBe( 4 );

		await page.keyboard.press( 'ArrowDown' );
		await expect( page.locator( '#nested-findings' ) ).toHaveClass(
			/present/
		);
		await expect( page ).toHaveURL( /#\/nested-findings$/ );
		expect(
			await page.evaluate( () =>
				window.presenterReveal.getInstance().getProgress()
			)
		).toBeGreaterThan( initial.progress );

		await page.keyboard.press( 'ArrowRight' );
		await expect( page.locator( '#after-nested' ) ).toHaveClass(
			/present/
		);

		await page.evaluate( () => {
			const instance = window.presenterReveal.getInstance();
			instance.toggleOverview( true );
		} );
		await expect( page.locator( '.reveal' ) ).toHaveClass( /overview/ );
		expect(
			await page.evaluate( () =>
				window.presenterReveal.getInstance().isOverview()
			)
		).toBe( true );
		await page.evaluate( () =>
			window.presenterReveal.getInstance().toggleOverview( false )
		);

		await page.evaluate( () =>
			window.presenterReveal.getInstance().getPlugin( 'search' ).open()
		);
		const searchInput = page.locator( '.searchbox .searchinput' );
		await expect( searchInput ).toBeVisible();
		await searchInput.fill( 'Searchable nested runtime sentinel' );
		await searchInput.press( 'Enter' );
		await expect( page.locator( '#nested-findings' ) ).toHaveClass(
			/present/
		);
		await page.evaluate( () =>
			window.presenterReveal.getInstance().getPlugin( 'search' ).close()
		);

		const zoomTarget = page.locator( '#nested-findings h2' );
		const zoomBox = await zoomTarget.boundingBox();
		expect( zoomBox ).not.toBeNull();
		await zoomTarget.dispatchEvent( 'mousedown', {
			altKey: true,
			clientX: zoomBox.x + zoomBox.width / 2,
			clientY: zoomBox.y + zoomBox.height / 2,
		} );
		await expect( page.locator( 'html' ) ).toHaveClass( /zoomed/ );
		await page.keyboard.press( 'Escape' );
		await expect( page.locator( 'html' ) ).not.toHaveClass( /zoomed/ );

		await page.evaluate( () =>
			window.presenterReveal.getInstance().slide( 1, 0 )
		);
		const reveal = page.locator( '.reveal' );
		await reveal.dispatchEvent( 'pointerdown', {
			clientX: 500,
			clientY: 500,
			pointerType: 'touch',
		} );
		await reveal.dispatchEvent( 'pointermove', {
			clientX: 500,
			clientY: 400,
			pointerType: 'touch',
		} );
		await reveal.dispatchEvent( 'pointerup', {
			clientX: 500,
			clientY: 400,
			pointerType: 'touch',
		} );
		await expect( page.locator( '#nested-findings' ) ).toHaveClass(
			/present/
		);

		await page.evaluate( () => {
			const instance = window.presenterReveal.getInstance();
			instance.configure( { rtl: true } );
			instance.slide( 0, 0 );
		} );
		await page.keyboard.press( 'ArrowLeft' );
		await expect( page.locator( '#nested-overview' ) ).toHaveClass(
			/present/
		);
		expect(
			await page.evaluate(
				() => window.presenterReveal.getInstance().getConfig().rtl
			)
		).toBe( true );

		await page.evaluate( () => {
			window.location.hash = '#/1/1';
		} );
		await expect( page.locator( '#nested-findings' ) ).toHaveClass(
			/present/
		);

		const scan = await new AxeBuilder( { page } )
			.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ] )
			.analyze();

		expect( scan.violations ).toEqual( [] );
		expect( pageErrors ).toEqual( [] );
		expect( consoleErrors ).toEqual( [] );
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
				'link[href*="/wp-content/themes/"], script[src*="/wp-content/themes/"]'
			)
		).toHaveCount( 0 );
		await expect(
			page.locator(
				'style#global-styles-inline-css, style[id^="wp-block-"][id$="-theme-inline-css"]'
			)
		).toHaveCount( 0 );
		await expect(
			page.locator( 'style#wp-block-heading-inline-css' )
		).toHaveCount( 1 );
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

	test( 'uses the full viewport without authenticated admin chrome', async ( {
		page,
	} ) => {
		const response = await page.goto( `/?slideshow=${ fixtureSlug }`, {
			waitUntil: 'networkidle',
		} );

		expect( response?.ok() ).toBe( true );
		await page.waitForFunction(
			() => window.presenterReveal?.getInstance()?.isReady() === true
		);
		await expect( page.locator( '#wpadminbar' ) ).toHaveCount( 0 );

		const geometry = await page.evaluate( () => {
			const bounds = ( selector ) => {
				const rectangle = document
					.querySelector( selector )
					?.getBoundingClientRect();

				return rectangle
					? { bottom: rectangle.bottom, height: rectangle.height }
					: null;
			};

			return {
				presentation: bounds( '#presenter-presentation' ),
				reveal: bounds( '[data-presenter-reveal-root]' ),
				bodyHasAdminBarClass:
					document.body.classList.contains( 'admin-bar' ),
				viewportHeight: window.innerHeight,
			};
		} );

		expect( geometry.bodyHasAdminBarClass ).toBe( false );
		expect( geometry.presentation ).not.toBeNull();
		expect( geometry.reveal ).not.toBeNull();
		expect( geometry.presentation.height ).toBe( geometry.viewportHeight );
		expect( geometry.presentation.bottom ).toBe( geometry.viewportHeight );
		expect( geometry.reveal.bottom ).toBe( geometry.viewportHeight );
	} );
} );

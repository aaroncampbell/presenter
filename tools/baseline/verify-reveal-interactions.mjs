/* eslint-disable no-console -- This command emits content-free characterization results. */
import { chromium } from '@playwright/test';

const snapshotOrigin = 'http://localhost:8890';
const defaultUrl = `${ snapshotOrigin }/?post_type=slideshow&p=106`;
const targetUrl = new URL( process.argv[ 2 ] || defaultUrl );

if ( targetUrl.origin !== snapshotOrigin ) {
	throw new Error(
		`Refusing to inspect anything except the isolated snapshot at ${ snapshotOrigin }.`
	);
}

const browser = await chromium.launch();
const page = await browser.newPage( {
	viewport: { height: 720, width: 1280 },
} );
const diagnostics = {
	consoleErrors: 0,
	internalResponseWarnings: 0,
	internalRequestFailures: 0,
	pageErrors: 0,
	scriptOrStyleResponseErrors: 0,
};

page.on( 'console', ( message ) => {
	if ( message.type() === 'error' ) {
		diagnostics.consoleErrors += 1;
	}
} );
page.on( 'pageerror', () => {
	diagnostics.pageErrors += 1;
} );
page.on( 'requestfailed', ( request ) => {
	if ( new URL( request.url() ).origin === snapshotOrigin ) {
		diagnostics.internalRequestFailures += 1;
	}
} );
page.on( 'response', ( response ) => {
	if (
		new URL( response.url() ).origin === snapshotOrigin &&
		response.status() >= 400
	) {
		if (
			[ 'script', 'stylesheet' ].includes(
				response.request().resourceType()
			)
		) {
			diagnostics.scriptOrStyleResponseErrors += 1;
		} else {
			diagnostics.internalResponseWarnings += 1;
		}
	}
} );

const response = await page.goto( targetUrl.href, {
	waitUntil: 'networkidle',
} );

await page.waitForFunction(
	() =>
		typeof window.Reveal !== 'undefined' &&
		typeof window.Reveal.isReady === 'function' &&
		window.Reveal.isReady(),
	undefined,
	{ timeout: 15_000 }
);

const fixture = await page.evaluate( () => {
	const slides = window.Reveal.getSlides();
	const fragmentSlide = slides.find( ( slide ) =>
		slide.querySelector( '.fragment' )
	);
	const navigationSlide = slides.find(
		( slide, index ) =>
			index < slides.length - 1 &&
			window.Reveal.getIndices( slide ) !== undefined
	);

	if ( ! fragmentSlide || ! navigationSlide ) {
		return {
			fragmentCount:
				fragmentSlide?.querySelectorAll( '.fragment' ).length || 0,
			slideCount: slides.length,
		};
	}

	return {
		fragmentCount: fragmentSlide.querySelectorAll( '.fragment' ).length,
		fragmentIndices: window.Reveal.getIndices( fragmentSlide ),
		navigationFragmentCount:
			navigationSlide.querySelectorAll( '.fragment' ).length,
		navigationIndices: window.Reveal.getIndices( navigationSlide ),
		slideCount: slides.length,
	};
} );

if ( ! fixture.fragmentIndices || ! fixture.navigationIndices ) {
	await browser.close();
	console.log(
		JSON.stringify(
			{
				diagnostics,
				fixture: {
					fragmentCount: fixture.fragmentCount,
					slideCount: fixture.slideCount,
				},
				httpStatus: response?.status() || null,
				passed: false,
				reason: 'The deck needs at least two slides and one fragment.',
			},
			null,
			2
		)
	);
	process.exitCode = 1;
} else {
	await page.evaluate( ( indices ) => {
		window.Reveal.slide( indices.h, indices.v, -1 );
	}, fixture.fragmentIndices );
	await page.waitForTimeout( 250 );

	const fragmentBefore = await page.evaluate( () => ( {
		indices: window.Reveal.getIndices(),
		visibleCount: document.querySelectorAll(
			'.reveal .slides section.present .fragment.visible'
		).length,
	} ) );
	await page.keyboard.press( 'ArrowRight' );
	await page.waitForTimeout( 250 );
	const fragmentAfterForward = await page.evaluate( () => ( {
		indices: window.Reveal.getIndices(),
		visibleCount: document.querySelectorAll(
			'.reveal .slides section.present .fragment.visible'
		).length,
	} ) );
	await page.keyboard.press( 'ArrowLeft' );
	await page.waitForTimeout( 250 );
	const fragmentAfterBackward = await page.evaluate( () => ( {
		indices: window.Reveal.getIndices(),
		visibleCount: document.querySelectorAll(
			'.reveal .slides section.present .fragment.visible'
		).length,
	} ) );

	await page.evaluate(
		( { fragmentCount, indices } ) => {
			window.Reveal.slide(
				indices.h,
				indices.v,
				Math.max( fragmentCount, 0 )
			);
		},
		{
			fragmentCount: fixture.navigationFragmentCount,
			indices: fixture.navigationIndices,
		}
	);
	await page.waitForTimeout( 250 );
	const navigationBefore = await page.evaluate( () =>
		window.Reveal.getIndices()
	);
	await page.keyboard.press( 'ArrowRight' );
	await page.waitForTimeout( 500 );
	const navigationAfter = await page.evaluate( () =>
		window.Reveal.getIndices()
	);

	const sameSlide = ( first, second ) =>
		first.h === second.h && first.v === second.v;
	const fragmentForwardPassed =
		sameSlide( fragmentBefore.indices, fragmentAfterForward.indices ) &&
		fragmentAfterForward.visibleCount > fragmentBefore.visibleCount;
	const fragmentBackwardPassed =
		sameSlide(
			fragmentAfterForward.indices,
			fragmentAfterBackward.indices
		) &&
		fragmentAfterBackward.visibleCount < fragmentAfterForward.visibleCount;
	const navigationPassed = ! sameSlide( navigationBefore, navigationAfter );
	const passed =
		response?.status() === 200 &&
		fixture.slideCount > 1 &&
		fixture.fragmentCount > 0 &&
		fragmentForwardPassed &&
		fragmentBackwardPassed &&
		navigationPassed &&
		diagnostics.internalRequestFailures === 0 &&
		diagnostics.pageErrors === 0 &&
		diagnostics.scriptOrStyleResponseErrors === 0;

	console.log(
		JSON.stringify(
			{
				diagnostics,
				fixture: {
					fragmentCount: fixture.fragmentCount,
					slideCount: fixture.slideCount,
				},
				fragmentBackward: {
					passed: fragmentBackwardPassed,
					visibleAfter: fragmentAfterBackward.visibleCount,
					visibleBefore: fragmentAfterForward.visibleCount,
				},
				fragmentForward: {
					passed: fragmentForwardPassed,
					visibleAfter: fragmentAfterForward.visibleCount,
					visibleBefore: fragmentBefore.visibleCount,
				},
				httpStatus: response?.status() || null,
				navigation: {
					passed: navigationPassed,
				},
				passed,
			},
			null,
			2
		)
	);

	await browser.close();

	if ( ! passed ) {
		process.exitCode = 1;
	}
}

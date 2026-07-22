import {
	finishLegacyMarkdownNotes,
	prepareLegacyMarkdownNotes,
} from '../../../src/frontend/legacy-markdown-notes';

describe( 'legacy Markdown speaker notes', () => {
	it( 'uses Reveal 4 parsing only for marked migrated notes', () => {
		document.body.innerHTML = `
			<aside class="notes" data-markdown data-presenter-legacy-markdown>
				### All over the world
				* US
				* Italy
				### Many companies
				* WordPress
			</aside>
			<aside class="notes" data-markdown># Native notes</aside>
		`;

		prepareLegacyMarkdownNotes( document );

		const migrated = document.querySelector( 'aside' );
		const headings = migrated.querySelectorAll( 'h3' );

		expect( headings[ 0 ].id ).toBe( 'all-over-the-world' );
		expect( headings[ 1 ].id ).toBe( 'many-companies' );
		expect( headings[ 1 ].closest( 'li' ) ).not.toBeNull();
		expect( migrated.hasAttribute( 'data-markdown' ) ).toBe( false );
		expect( document.querySelectorAll( 'aside' )[ 1 ].innerHTML ).toBe(
			'# Native notes'
		);

		finishLegacyMarkdownNotes( document );

		expect(
			migrated.hasAttribute(
				'data-presenter-legacy-markdown'
			)
		).toBe( false );
		expect( migrated.hasAttribute( 'data-markdown' ) ).toBe( true );
		expect( migrated.getAttribute( 'data-markdown-parsed' ) ).toBe( 'true' );
	} );

	it( 'keeps raw HTML in a forged compatibility source inert', () => {
		document.body.innerHTML = `
			<aside class="notes" data-markdown data-presenter-legacy-markdown>
				<script>alert( 'unsafe' )</script>
			</aside>
		`;
		const notes = document.querySelector( 'aside' );

		prepareLegacyMarkdownNotes( document );

		expect( notes.querySelector( 'script' ) ).toBeNull();
		expect( notes.textContent ).toContain( "alert( 'unsafe' )" );
	} );
} );

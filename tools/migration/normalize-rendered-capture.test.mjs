import assert from 'node:assert/strict';
import test from 'node:test';

import { normalizeRenderedCapture } from './normalize-rendered-capture.mjs';

const rawCapture = () => ( {
	schemaVersion: 1,
	slideCount: 1,
	metadata: {
		dimensions: { height: 700, width: 960 },
		hierarchy: [
			{
				horizontalIndex: 0,
				kind: 'slide',
				leaves: [ { slideOrdinal: 1, verticalIndex: 0 } ],
			},
		],
		semanticConfig: { controls: true },
		themeStylesheets: [
			{ href: 'private-theme-url', id: 'theme', media: '' },
		],
	},
	slides: [
		{
			attributes: {
				class: 'private-class second-class',
				'data-chart': 'private-chart',
				id: 'private-anchor',
			},
			canonicalHtml: '<section>private-content</section>',
			fragments: [
				{
					attributes: { class: 'fragment' },
					canonicalHtml: '<span>private-fragment</span>',
					fragmentOrdinal: 1,
				},
			],
			horizontalIndex: 0,
			notes: [ { html: 'private-notes', markdown: true } ],
			verticalIndex: 0,
		},
	],
} );

test( 'normalizes all authored values to domain-separated digests', () => {
	const normalized = normalizeRenderedCapture(
		rawCapture(),
		Buffer.alloc( 32, 9 )
	);
	const serialized = JSON.stringify( normalized );

	assert.equal( normalized.width, 960 );
	assert.equal( normalized.height, 700 );
	assert.equal( normalized.runtimeReady, true );
	assert.equal( normalized.slides[ 0 ].notesFormat, 'markdown' );
	assert.match( normalized.slides[ 0 ].notesDigest, /^[a-f0-9]{64}$/ );
	for ( const sentinel of [
		'private-theme-url',
		'private-class',
		'private-chart',
		'private-anchor',
		'private-content',
		'private-fragment',
		'private-notes',
	] ) {
		assert.equal( serialized.includes( sentinel ), false );
	}
} );

test( 'rejects incomplete captures with a fixed error', () => {
	const capture = rawCapture();
	delete capture.slides[ 0 ].attributes;
	assert.throws(
		() => normalizeRenderedCapture( capture, Buffer.alloc( 32, 9 ) ),
		( error ) =>
			error.code === 'rendered_capture_schema' &&
			error.message === 'rendered_capture_schema'
	);
} );

import assert from 'node:assert/strict';
import { mkdir, mkdtemp, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';

import { PNG } from 'pngjs';

import {
	compareVisualArtifacts,
	VisualArtifactError,
} from './compare-visual-artifacts.mjs';

const png = ( changed = false, uniform = false ) => {
	const image = new PNG( { height: 2, width: 2 } );
	const colors = uniform
		? [
				[ 12, 34, 56 ],
				[ 12, 34, 56 ],
				[ 12, 34, 56 ],
				[ 12, 34, 56 ],
		  ]
		: [
				[ 255, 0, 0 ],
				[ 0, 255, 0 ],
				[ 0, 0, 255 ],
				[ 255, 255, 255 ],
		  ];
	if ( changed ) {
		colors[ 3 ] = [ 0, 0, 0 ];
	}
	colors.forEach( ( color, index ) => {
		image.data.set( [ ...color, 255 ], index * 4 );
	} );
	return PNG.sync.write( image );
};

const fixture = async ( candidate, baseline = png() ) => {
	const root = await mkdtemp( path.join( tmpdir(), 'presenter-visual-' ) );
	await Promise.all( [
		mkdir( path.join( root, 'capture-000001' ) ),
		mkdir( path.join( root, 'capture-000002' ) ),
		mkdir( path.join( root, 'diff-000001' ) ),
	] );
	await Promise.all( [
		writeFile(
			path.join( root, 'capture-000001', 'frame-000001.png' ),
			baseline
		),
		writeFile(
			path.join( root, 'capture-000002', 'frame-000001.png' ),
			candidate
		),
	] );
	return root;
};

const compare = ( root ) =>
	compareVisualArtifacts( {
		baselineFiles: [ 'capture-000001/frame-000001.png' ],
		candidateFiles: [ 'capture-000002/frame-000001.png' ],
		diffDirectory: 'diff-000001',
		privateRoot: root,
	} );

test( 'passes only pixel-identical nonuniform frames', async () => {
	const result = await compare( await fixture( png() ) );
	assert.equal( result.state, 'passed' );
	assert.equal( result.changedFrames, 0 );
	assert.equal( result.maximumChangedPixelRatio, 0 );
} );

test( 'requires review for any exact RGBA difference', async () => {
	const result = await compare( await fixture( png( true ) ) );
	assert.equal( result.state, 'review_required' );
	assert.equal( result.changedFrames, 1 );
	assert.equal( result.frames[ 0 ].differingPixels, 1 );
	assert.equal( result.maximumChangedPixelRatio, 0.25 );
} );

test( 'fails closed for uniform captures and untrusted paths', async () => {
	await assert.rejects(
		compare( await fixture( png( false, true ), png( false, true ) ) ),
		( error ) =>
			error instanceof VisualArtifactError &&
			error.code === 'uniform-capture'
	);
	const root = await fixture( png() );
	await assert.rejects(
		compareVisualArtifacts( {
			baselineFiles: [ '../private.png' ],
			candidateFiles: [ 'capture-000002/frame-000001.png' ],
			diffDirectory: 'diff-000001',
			privateRoot: root,
		} ),
		/invalid-artifact-path/
	);
} );

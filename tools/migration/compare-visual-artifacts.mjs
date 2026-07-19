import { lstat, readFile, realpath, writeFile } from 'node:fs/promises';
import path from 'node:path';

import pixelmatch from 'pixelmatch';
import { PNG } from 'pngjs';

const artifactPattern = /^capture-[0-9]{6}\/frame-[0-9]{6}\.png$/;

export class VisualArtifactError extends Error {
	constructor( code ) {
		super( code );
		this.name = 'VisualArtifactError';
		this.code = code;
	}
}

const resolvePrivateArtifact = async ( root, relative ) => {
	if (
		typeof relative !== 'string' ||
		! artifactPattern.test( relative.replaceAll( '\\', '/' ) )
	) {
		throw new VisualArtifactError( 'invalid-artifact-path' );
	}
	const candidate = path.resolve( root, relative );
	const canonical = await realpath( candidate ).catch( () => null );
	if (
		canonical === null ||
		! canonical.startsWith( `${ root }${ path.sep }` ) ||
		( await lstat( candidate ) ).isSymbolicLink()
	) {
		throw new VisualArtifactError( 'invalid-artifact-path' );
	}
	return canonical;
};

const decode = async ( filename ) => {
	try {
		return PNG.sync.read( await readFile( filename ), {
			checkCRC: true,
		} );
	} catch {
		throw new VisualArtifactError( 'invalid-png' );
	}
};

const isUniform = ( png ) => {
	for ( let offset = 4; offset < png.data.length; offset += 4 ) {
		for ( let channel = 0; channel < 4; channel++ ) {
			if ( png.data[ offset + channel ] !== png.data[ channel ] ) {
				return false;
			}
		}
	}
	return true;
};

/**
 * Compare two ordered private PNG sets and write opaque diff frames.
 *
 * Exact RGBA differences determine review status. Pixelmatch is used only to
 * produce a readable heatmap; its perceptual tolerance cannot turn a changed
 * frame into an automatic pass.
 *
 * @param {Object}   options                Comparison options.
 * @param {string[]} options.baselineFiles  Relative baseline frame paths.
 * @param {string[]} options.candidateFiles Relative candidate frame paths.
 * @param {string}   options.privateRoot    Canonical private artifact root.
 * @param {string}   options.diffDirectory  Existing relative diff directory.
 * @return {Promise<Object>} Content-free aggregate metrics and relative files.
 */
export const compareVisualArtifacts = async ( {
	baselineFiles,
	candidateFiles,
	privateRoot,
	diffDirectory,
} ) => {
	const root = await realpath( privateRoot ).catch( () => null );
	if (
		root === null ||
		! Array.isArray( baselineFiles ) ||
		! Array.isArray( candidateFiles ) ||
		baselineFiles.length === 0 ||
		baselineFiles.length !== candidateFiles.length ||
		! /^diff-[0-9]{6}$/.test( diffDirectory )
	) {
		throw new VisualArtifactError( 'invalid-artifact-set' );
	}
	const diffRoot = path.resolve( root, diffDirectory );
	const canonicalDiffRoot = await realpath( diffRoot ).catch( () => null );
	if (
		canonicalDiffRoot === null ||
		path.dirname( canonicalDiffRoot ) !== root ||
		( await lstat( diffRoot ) ).isSymbolicLink()
	) {
		throw new VisualArtifactError( 'invalid-artifact-path' );
	}

	const frames = [];
	for ( let index = 0; index < baselineFiles.length; index++ ) {
		const baseline = await decode(
			await resolvePrivateArtifact( root, baselineFiles[ index ] )
		);
		const candidate = await decode(
			await resolvePrivateArtifact( root, candidateFiles[ index ] )
		);
		if (
			baseline.width !== candidate.width ||
			baseline.height !== candidate.height ||
			baseline.width < 1 ||
			baseline.height < 1
		) {
			throw new VisualArtifactError( 'image-dimensions-changed' );
		}
		if ( isUniform( baseline ) || isUniform( candidate ) ) {
			throw new VisualArtifactError( 'uniform-capture' );
		}

		let differingPixels = 0;
		for ( let offset = 0; offset < baseline.data.length; offset += 4 ) {
			if (
				baseline.data[ offset ] !== candidate.data[ offset ] ||
				baseline.data[ offset + 1 ] !== candidate.data[ offset + 1 ] ||
				baseline.data[ offset + 2 ] !== candidate.data[ offset + 2 ] ||
				baseline.data[ offset + 3 ] !== candidate.data[ offset + 3 ]
			) {
				differingPixels++;
			}
		}
		const diff = new PNG( {
			height: baseline.height,
			width: baseline.width,
		} );
		const perceptualDifferingPixels = pixelmatch(
			baseline.data,
			candidate.data,
			diff.data,
			baseline.width,
			baseline.height,
			{ threshold: 0.1 }
		);
		const relativeDiff = `${ diffDirectory }/frame-${ String(
			index + 1
		).padStart( 6, '0' ) }.png`;
		await writeFile(
			path.join( root, relativeDiff ),
			PNG.sync.write( diff ),
			{ flag: 'wx', mode: 0o600 }
		);
		const totalPixels = baseline.width * baseline.height;
		frames.push( {
			differingPixels,
			file: relativeDiff,
			perceptualDifferingPixels,
			ratio: differingPixels / totalPixels,
			totalPixels,
		} );
	}

	return {
		changedFrames: frames.filter( ( frame ) => frame.differingPixels > 0 )
			.length,
		comparedFrames: frames.length,
		frames,
		maximumChangedPixelRatio: Math.max(
			...frames.map( ( frame ) => frame.ratio )
		),
		state: frames.some( ( frame ) => frame.differingPixels > 0 )
			? 'review_required'
			: 'passed',
	};
};

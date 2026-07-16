/* eslint-disable no-console -- Build commands report copied dependency metadata. */
import { cp, copyFile, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = path.resolve(
	path.dirname( fileURLToPath( import.meta.url ) ),
	'../..'
);
const revealRoot = path.join( repositoryRoot, 'node_modules', 'reveal.js' );
const buildRoot = path.join( repositoryRoot, 'build' );
const outputRoot = path.join( buildRoot, 'reveal' );

if ( path.dirname( outputRoot ) !== buildRoot ) {
	throw new Error( 'Refusing to copy Reveal assets outside the build directory.' );
}

const packageMetadata = JSON.parse(
	await readFile( path.join( revealRoot, 'package.json' ), 'utf8' )
);

await rm( outputRoot, { force: true, recursive: true } );
await mkdir( outputRoot, { recursive: true } );
await copyFile(
	path.join( revealRoot, 'dist', 'reveal.css' ),
	path.join( outputRoot, 'reveal.css' )
);
await cp(
	path.join( revealRoot, 'dist', 'theme' ),
	path.join( outputRoot, 'theme' ),
	{ recursive: true }
);
await copyFile(
	path.join( revealRoot, 'LICENSE' ),
	path.join( outputRoot, 'LICENSE' )
);
await writeFile(
	path.join( outputRoot, 'SOURCE.json' ),
	`${ JSON.stringify(
		{
			name: packageMetadata.name,
			version: packageMetadata.version,
		},
		null,
		'\t'
	) }\n`,
	'utf8'
);

console.log(
	`Copied Reveal.js ${ packageMetadata.version } styles, themes, and license.`
);

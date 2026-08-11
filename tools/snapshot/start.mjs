/* eslint-disable no-console -- This command reports environment safety. */
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire( import.meta.url );
const wpEnvRoot = dirname( require.resolve( '@wordpress/env' ) );
const repositoryRoot = resolve(
	dirname( fileURLToPath( import.meta.url ) ),
	'../..'
);
const snapshotEnvironment = resolve( repositoryRoot, 'tools/snapshot/env' );
const builderPath = resolve(
	wpEnvRoot,
	'runtime/docker/build-docker-compose-config.js'
);
const originalBuilder = require( builderPath );

function bindPublishedPortsForLanTesting( config ) {
	const compose = originalBuilder( config );

	for ( const [ serviceName, service ] of Object.entries(
		compose.services
	) ) {
		if ( ! Array.isArray( service.ports ) ) {
			continue;
		}

		service.ports = service.ports.map( ( port ) => {
			if ( typeof port !== 'string' || port.startsWith( '127.0.0.1:' ) ) {
				throw new Error(
					'Unexpected wp-env port configuration; refusing to start.'
				);
			}

			return serviceName === 'wordpress' ? port : `127.0.0.1:${ port }`;
		} );
	}

	return compose;
}

require.cache[ builderPath ].exports = bindPublishedPortsForLanTesting;

const start = require( resolve( wpEnvRoot, 'commands/start.js' ) );
const { loadConfig } = require( '@wordpress/env/lib/config' );
const { getRuntime } = require( resolve( wpEnvRoot, 'runtime/index.js' ) );

const spinner = {
	prefixText: '',
	text: '',
	fail( message ) {
		console.error( message );
	},
	info( message ) {
		console.log( message );
	},
	start() {},
	succeed( message ) {
		console.log( message );
	},
	warn( message ) {
		console.warn( message );
	},
};

function projectContainerIds( projectName ) {
	return execFileSync(
		'docker',
		[
			'ps',
			'--filter',
			`label=com.docker.compose.project=${ projectName }`,
			'--format',
			'{{.ID}}',
		],
		{ encoding: 'utf8', windowsHide: true }
	)
		.split( /\r?\n/ )
		.filter( Boolean );
}

function inspectBindings( projectName ) {
	const containerIds = projectContainerIds( projectName );

	if ( containerIds.length === 0 ) {
		throw new Error( 'Snapshot Docker containers are missing.' );
	}

	const publishedBindings = containerIds.flatMap( ( containerId ) => {
		const ports = JSON.parse(
			execFileSync(
				'docker',
				[
					'inspect',
					containerId,
					'--format',
					'{{json .NetworkSettings.Ports}}',
				],
				{ encoding: 'utf8', windowsHide: true }
			).trim()
		);

		return Object.values( ports ).flatMap( ( bindings ) => bindings || [] );
	} );

	if (
		publishedBindings.length === 0 ||
		publishedBindings.some( ( binding ) => {
			if ( ! /^[0-9]+$/.test( binding.HostPort ) ) {
				return true;
			}

			return binding.HostPort === '8890'
				? ! [ '0.0.0.0', '::' ].includes( binding.HostIp )
				: binding.HostIp !== '127.0.0.1';
		} )
	) {
		throw new Error(
			'Snapshot Docker bindings exceed the approved LAN web endpoint.'
		);
	}

	if (
		! publishedBindings.some(
			( binding ) =>
				binding.HostPort === '8890' && binding.HostIp === '0.0.0.0'
		)
	) {
		throw new Error(
			'Snapshot web endpoint is not bound to all IPv4 interfaces.'
		);
	}

	return publishedBindings.length;
}

function forceStopProject( projectName ) {
	let containerIds = projectContainerIds( projectName );
	if ( containerIds.length > 0 ) {
		execFileSync( 'docker', [ 'stop', ...containerIds ], {
			encoding: 'utf8',
			windowsHide: true,
		} );
	}

	containerIds = projectContainerIds( projectName );
	if ( containerIds.length > 0 ) {
		throw new Error(
			'CRITICAL: snapshot containers remain running after unsafe start cleanup.'
		);
	}
}

const previousDirectory = process.cwd();
let runtime;
let config;

try {
	console.log( 'Starting isolated snapshot environment.' );
	process.chdir( snapshotEnvironment );
	config = await loadConfig( snapshotEnvironment );
	runtime = getRuntime( 'docker' );

	await start( {
		debug: false,
		runtime: 'docker',
		scripts: false,
		spinner,
		spx: 'off',
		update: false,
		xdebug: 'off',
	} );

	const projectName = config.workDirectoryPath
		.replace( /[\\/]+$/, '' )
		.split( /[\\/]/ )
		.pop();
	const bindingCount = inspectBindings( projectName );

	spinner.succeed(
		`Snapshot environment started with one LAN web endpoint and ${ bindingCount } verified published bindings.`
	);
} catch ( error ) {
	if ( runtime && config ) {
		for ( let attempt = 1; attempt <= 3; attempt += 1 ) {
			try {
				await runtime.stop( config, { debug: false, spinner } );
				break;
			} catch {
				// Fall through to a verified force-stop after bounded retries.
			}
		}

		const projectName = config.workDirectoryPath
			.replace( /[\\/]+$/, '' )
			.split( /[\\/]/ )
			.pop();
		try {
			forceStopProject( projectName );
		} catch ( cleanupError ) {
			spinner.fail(
				'CRITICAL: snapshot environment cleanup could not be verified.'
			);
			throw new Error( 'Snapshot environment cleanup failed.', {
				cause: cleanupError,
			} );
		}
	}

	spinner.fail( 'Snapshot environment start failed closed.' );
	throw error;
} finally {
	process.chdir( previousDirectory );
}

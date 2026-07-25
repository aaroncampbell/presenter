import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = await readFile(
	new URL( './assets/google-line-chart-compat.js', import.meta.url ),
	'utf8'
);

class FakeElement {
	constructor( name, width = 0, height = 0 ) {
		this.attributes = {};
		this.children = [];
		this.clientHeight = height;
		this.clientWidth = width;
		this.name = name;
		this.style = {};
		this.textContent = '';
	}

	appendChild( child ) {
		this.children.push( child );
		return child;
	}

	getBoundingClientRect() {
		return { height: this.clientHeight, width: this.clientWidth };
	}

	replaceChildren( ...children ) {
		this.children = children;
	}

	setAttribute( name, value ) {
		this.attributes[ name ] = value;
	}
}

const createRuntime = () => {
	const context = vm.createContext( {
		Date,
		Intl,
		console,
		document: {
			createElementNS: ( namespace, name ) => new FakeElement( name ),
		},
		setTimeout,
	} );
	context.window = context;
	vm.runInContext( source, context );
	return context;
};

const descendants = ( element, name ) => {
	const matches = [];
	for ( const child of element.children ) {
		if ( child.name === name ) {
			matches.push( child );
		}
		matches.push( ...descendants( child, name ) );
	}
	return matches;
};

const chartOptions = {
	backgroundColor: 'transparent',
	colors: [ '#808080' ],
	hAxis: {
		baselineColor: '#8377D1',
		gridlines: { color: '#ABA1E6' },
		slantedText: true,
		slantedTextAngle: 45,
		textStyle: { bold: true, color: '#8377D1' },
		ticks: [
			{ f: '2012', v: new Date( 2012, 0, 1 ) },
			{ f: 'Build Tools', v: new Date( 2013, 7, 1 ) },
		],
	},
	vAxis: {
		baselineColor: '#8377D1',
		gridlines: { color: '#ABA1E6' },
		textStyle: { bold: true, color: '#8377D1' },
	},
};

test( 'publishes the historical API and executes every queued callback', async () => {
	const runtime = createRuntime();
	const calls = [];
	runtime.calls = calls;

	runtime.google.charts.load( 'current', { packages: [ 'corechart' ] } );
	vm.runInContext(
		"function drawChart() { calls.push( 'first' ); } google.charts.setOnLoadCallback( drawChart );",
		runtime
	);
	vm.runInContext(
		"function drawChart() { calls.push( 'second' ); } google.charts.setOnLoadCallback( drawChart );",
		runtime
	);

	await new Promise( ( resolve ) => setTimeout( resolve, 10 ) );
	assert.deepEqual( calls, [ 'first', 'second' ] );
	assert.equal(
		typeof runtime.google.visualization.arrayToDataTable,
		'function'
	);
	assert.equal( typeof runtime.google.visualization.LineChart, 'function' );
} );

test( 'draws a deterministic responsive SVG with historical styling', () => {
	const runtime = createRuntime();
	const container = new FakeElement( 'div', 800, 400 );
	const table = runtime.google.visualization.arrayToDataTable( [
		[ 'Year', 'Percent' ],
		[ new Date( 2011, 0, 1 ), 13.1 ],
		[ new Date( 2018, 10, 27 ), 32.4 ],
	] );

	new runtime.google.visualization.LineChart( container ).draw(
		table,
		chartOptions
	);

	const [ svg ] = container.children;
	assert.equal( svg.name, 'svg' );
	assert.equal( svg.attributes.width, '800' );
	assert.equal( svg.attributes.height, '400' );
	assert.equal( svg.attributes.viewBox, '0 0 800 400' );
	assert.equal( svg.style.maxWidth, '100%' );
	assert.equal( descendants( svg, 'rect' ).length, 0 );

	const paths = descendants( svg, 'path' );
	assert.equal( paths.length, 1 );
	assert.equal( paths[ 0 ].attributes.stroke, '#808080' );
	assert.match( paths[ 0 ].attributes.d, /^M .* L /u );

	const labels = descendants( svg, 'text' );
	assert.ok(
		labels.some( ( label ) => label.textContent === 'Build Tools' )
	);
	assert.ok( labels.some( ( label ) => label.textContent === '40' ) );
	assert.ok(
		labels.some( ( label ) =>
			label.attributes.transform?.startsWith( 'rotate(-45' )
		)
	);
	assert.ok(
		descendants( svg, 'line' ).some(
			( line ) => line.attributes.stroke === '#ABA1E6'
		)
	);
} );

test( 'formats large vertical-axis values with commas and honors CSS dimensions', () => {
	const runtime = createRuntime();
	const container = new FakeElement( 'div' );
	container.style.width = '640px';
	container.style.height = '320px';
	const data = runtime.google.visualization.arrayToDataTable( [
		[ 'Year', 'Sites' ],
		[ new Date( 2011, 0, 1 ), 45_326_576 ],
		[ new Date( 2018, 10, 27 ), 596_393_731 ],
	] );

	new runtime.google.visualization.LineChart( container ).draw(
		data,
		chartOptions
	);

	const [ svg ] = container.children;
	assert.equal( svg.attributes.viewBox, '0 0 640 320' );
	assert.ok(
		descendants( svg, 'text' ).some(
			( label ) => label.textContent === '600,000,000'
		)
	);
	assert.ok(
		descendants( svg, 'text' ).some(
			( label ) => label.textContent === '100,000,000'
		)
	);
} );

test( 'replaces a previous drawing and supports a solid background', () => {
	const runtime = createRuntime();
	const container = new FakeElement( 'div', 800, 400 );
	const chart = new runtime.google.visualization.LineChart( container );
	const data = runtime.google.visualization.arrayToDataTable( [
		[ 'Year', 'Value' ],
		[ new Date( 2020, 0, 1 ), 1 ],
		[ new Date( 2021, 0, 1 ), 2 ],
	] );

	chart.draw( data, chartOptions );
	const firstSvg = container.children[ 0 ];
	chart.draw( data, { ...chartOptions, backgroundColor: '#ffffff' } );

	assert.equal( container.children.length, 1 );
	assert.notEqual( container.children[ 0 ], firstSvg );
	assert.equal(
		descendants( container.children[ 0 ], 'rect' )[ 0 ].attributes.fill,
		'#ffffff'
	);
} );

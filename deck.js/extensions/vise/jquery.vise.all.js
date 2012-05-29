/*! Vise - v0.1.0 - 2012-05-04
* https://github.com/koop/vise
* Copyright (c) 2012 Daryl Koopersmith; Licensed GPL */
;(function($){
	var $doc = $(document),
		cmp  = function( a, b ) { return a < b ? -1 : a > b ? 1 : 0; },
		rclasses = /vise-(orientation|size|width|height)-\S*/g,
		Vise;

	Vise = function( element, options ) {
		this.sizes   = $.extend( true, {}, this.sizes );
		this.options = $.extend( true, {}, this.options, options );

		this.element = element;
		this.clamp   = $('<div/>').appendTo( this.element );
		this.loaded  = $.proxy( this.loaded, this );
		this.resizer = $.proxy( this.resizer, this );

		element.addClass('vise');

		this.on( 'resize', this.resizer );
		this.trigger('init');
	};

	$.fn.vise = function( options ) {
		var element = this.first(),
			vise;

		if ( ! element.length )
			return;

		if ( vise = element.data('vise') )
			return vise;

		vise = new Vise( element, options );
		element.data( 'vise', vise );
		return vise;
	};

	$.fn.vise.fn = Vise.prototype;

	Vise.prototype.trigger = function( id, data ) {
		data = data || [];
		data.unshift( this );
		this.element.trigger( 'vise.' + id, data );
		return this;
	};

	$.each([ 'on', 'off' ], function( i, method ) {
		Vise.prototype[ method ] = function( id ) {
			var args = $.makeArray( arguments );
			args[0] = 'vise.' + id;
			this.element[ method ].apply( this.element, args );
			return this;
		};
	});

	Vise.prototype.options = {
		scrollbars: false,
		mask:       false
	};

	Vise.prototype.sizes = {
		iPhone: [ 320, 480 ],
		iPad:   [ 1024, 768 ],
		kindle: [ 1024, 600 ]
	};

	Vise.prototype.load = function( url ) {
		this.url = url || this.url;

		if ( this.iframe )
			this.iframe.remove();

		this.element.addClass('loading');

		this.iframe = $('<iframe />', {
			src:  this.url
		}).one( 'load', this.loaded ).appendTo( this.clamp );

		this.trigger('load');

		return this;
	};

	Vise.prototype.loaded = function( url ) {
		this.element.removeClass('loading');
		this.trigger('loaded');
	};

	/**
	 * Resizes the iframe.
	 *
	 * dimensions  - array | string - Optional. Default 100%, 100%.
	 * orientation - string         - Optional. Default false. Use 'portrait' or 'landscape'.
	 */
	Vise.prototype.resize = function( dimensions, orientation ) {
		var width, height, classes, self = this;

		if ( typeof dimensions === 'string' ) {
			this.size  = dimensions;
			dimensions = this.sizes[ dimensions ] || false;
		} else {
			delete this.size;
		}

		dimensions = dimensions || [ '100%', '100%' ];

		if ( orientation ) {
			dimensions = dimensions.sort( cmp );
			if ( 'landscape' === orientation )
				dimensions = dimensions.reverse();
		}

		this.width  = dimensions[0];
		this.height = dimensions[1];

		if ( this.options.scrollbars && typeof width === 'number' )
			this.width += 15; // @todo: measure scrollbar width.

		this.orientation = this.width > this.height ? 'landscape' : 'portrait';

		// Remove current classes
		this.element[0].className = this.element[0].className.replace( rclasses, '' );

		// Generate new classes
		classes = $.map([ 'orientation', 'size', 'width', 'height' ], function( param ) {
			return self[ param ] ? 'vise-' + param + '-' + self[ param ] : '';
		});
		this.element.addClass( classes.join(' ') );

		this.trigger('resize');
		return this;
	};

	Vise.prototype.resizer = function( e, vise ) {
		vise.clamp.css({
			width:  vise.width,
			height: vise.height
		});
	};

	$doc.ready( function() {
		$doc.trigger( 'vise.ready' );
	});
}(jQuery));;(function( $ ) {
	$.extend( $.fn.vise.fn, {
		frame: function() {
			if ( ! this._frame ) {
				this._frame = $('<div class="vise-frame" />').appendTo( this.clamp );
				this._frame.update = function() {

				};
			}

			this._frame.show();
			this.on( 'resize', this._frame.update );
			this.trigger('resize');
		},

		unframe: function() {
			if ( ! this._frame )
				return;

			this._frame.hide();
			this.off( 'resize', this._frame.update );
		}
	});
}( jQuery ));;jQuery(document).on( 'vise.init', function( e, vise ) {
	vise.loader = jQuery('<div class="vise-loader" />').appendTo( vise.clamp );
});;(function( $ ) {
	$.extend( $.fn.vise.fn, {
		mask: function() {
			if ( ! this._mask ) {
				this._mask = $('<div class="vise-mask" />').appendTo( this.clamp );
				this._mask.resizers = {
					original: this.resizer
				};

				this._mask.resizers.mask = function( e, vise ) {
					vise._mask.css( 'top', vise.height );
					vise.clamp.width( vise.width );
				};
			}

			this.off( 'resize', this.resizer );
			this.resizer = this._mask.resizers.mask;
			this.on( 'resize', this.resizer );

			this._mask.show();
			this.clamp.height('100%');
			this.trigger('resize');
		},

		unmask: function() {
			if ( ! this._mask )
				return;

			this.off( 'resize', this.resizer );
			this.resizer = this._mask.resizers.original;
			this.on( 'resize', this.resizer );

			this._mask.hide();
			this.trigger('resize');
		}
	});
}( jQuery ));;(function( $ ) {
	$.extend( $.fn.vise.fn, {
		_center: function( e, vise ) {
			var difference = vise.element.height() - vise.height;

			difference = difference > 0 ? difference : 0;
			vise.clamp.css( 'marginTop', difference / 2 );
		},
		center: function() {
			this.on( 'resize', this._center );
			this.trigger('resize');
		},

		uncenter: function() {
			this.clamp.css( 'marginTop', 0 );
			this.off( 'resize', this._center );
		}
	});
}( jQuery ));
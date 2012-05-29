

(function($){
	$doc = $(document);

	/*
	Deck core removes the src attribute of iframes when transitioning between slides.
	Unfortunately, there is no way to prevent this behavior other than to unbind
	all events tied to deck.change.
	 */
	$doc.off('deck.change');

	$doc.on('deck.init', function() {
		var slides = $.deck('getSlides');

		/*
		Deck core likes to flash the opacity of iframes. We'll handle vise iframes ourselves.
		 */
		$.each(slides, function(i, $el) {
			if ( this.closest('.vise-slide').length )
				this.unbind('webkitTransitionEnd.deck');
		});

		$.each( slides, function() {
			var container, vise, url, size;

			if ( ! this.hasClass('vise-slide') )
				return;

			console.log( 'found vise slide', this );

			title = $('<div class="title" />').appendTo( this );

			vise = $('<div />').appendTo( this ).vise();

			vise.frame();

			vise.on( 'load', function() {
				title.text( vise.url.replace(/https?:\/\//, '') );

				console.log( 'loading', vise.url );
			}).on( 'loaded', function() {
				console.log( 'loaded', vise.url );
			});

			if ( url = this.data('viseUrl') )
				vise.load( url );

			if ( size = this.data('viseSize') )
				vise.resize( size, this.data('viseOrientation') );
		});
	});

	$doc.on('deck.change', function(e, from, to) {
		var slide = $.deck('getSlide', to),
			container = slide.closest('.vise-slide'),
			vise;

		if ( ! container.length )
			return;

		vise = container.find('.vise').vise();
		vise.resize( slide.data('viseSize'), slide.data('viseOrientation') );
	});
}(jQuery));
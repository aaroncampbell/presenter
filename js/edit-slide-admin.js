jQuery( document ).ready( function( $ ) {
	// Make the title hint work on all our slide titles
	$( 'input.title', '#slides' ).each( function () { wptitlehint(this.id)} );

	$( '#slides' ).on( 'click.remove-slide', '.slide .button.remove', function() {
		$( this ).closest( '.slide' ).remove();
	} );

	$( '#slides' ).on( 'blur.update-slide-title', '.slide input.title', _.throttle( presenter_update_slide_title, 500 ) );

	$( '#slides' ).on( 'keyup.update-slide-title', '.slide input.title', _.throttle( presenter_update_slide_title, 500 ) );

	function presenter_update_slide_title() {
		$( this ).closest( '.slide' ).find( 'h3.slide-hndle span.title' ).text( $( this ).val() );
	}

	// Initialize "added" data to track how many slides we've added
	$( '#slides' ).data( 'added', 0 );

	$( '#slides' ).on( 'click.add-slide', '.button.add', function( e ) {
		// Grab the HTML of a blank slide by cloning, appending to an element and grabbing the innerHTML
		var blank_slide = $('<p>').append( $('#slide-__i__').clone() ).html();

		var added = $( '#slides' ).data( 'added' ) + 1;

		// Replace our special __i__ with the new slide number
		blank_slide = $( blank_slide.replace( /__i__/g, 'new-' + added ).replace( /__new__/g, '' ) );

		var title_id = 'slide-title-new-' + added;

		if ( $(this).hasClass( 'before' ) ) {
			// Insert adjusted HTML before current slide
			blank_slide.insertBefore( $(this).closest( '.stuffbox' ) ).find( '#' + title_id ).val( '' );
		} else if ( $(this).hasClass( 'after' ) ) {
			// Insert adjusted HTML after current slide
			blank_slide.insertAfter( $(this).closest( '.stuffbox' ) ).find( '#' + title_id ).val( '' );
		} else {
			// Insert adjusted HTML after the last slide
			blank_slide.insertAfter( '#slides .stuffbox:last' ).find( '#' + title_id ).val( '' );
		}
		wptitlehint( title_id );

		// Update the number of slides we've added
		$( '#slides' ).data( 'added', added );
	} );

	$( '#slides' ).on( 'click.postboxes', '.stuffbox .slide-hndle', function(e) {
		// Don't do this if the click was to move
		if ( ! $( e.target ).hasClass( 'move' ) ) {
			$(this).parent( '.stuffbox' ).toggleClass('closed');
		}
	});

	$( '#slides' ).on( 'click.move-slide', '.stuffbox .move', function() {
		if ( $(this).hasClass( 'up' ) ) {
			// Going up
			var $slide = $(this).closest( '.stuffbox' ),
				$prev_slide = $slide.prev( '.stuffbox' );

			if ( $prev_slide ) {
				$prev_slide.before( $slide );
			}
		} else if ( $(this).hasClass( 'down' ) ) {
			// Going down
			var $slide = $(this).closest( '.stuffbox' ),
				$next_slide = $slide.next( '.stuffbox' );

			if ( $next_slide ) {
				$next_slide.after( $slide );
			}
		}
	});

	var isMobile = $(document.body).hasClass('mobile');
	$( '#slides' ).sortable( {
		placeholder: 'sortable-placeholder',
		items: '.slide',
		handle: '.slide-hndle',
		cursor: 'move',
		delay: ( isMobile ? 200 : 0 ),
		distance: 2,
		tolerance: 'pointer',
		forcePlaceholderSize: true,
		helper: 'clone',
		opacity: 0.65
	} );
});

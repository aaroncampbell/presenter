jQuery( document ).ready( function( $ ) {
	// Make the title hint work on all our slide titles
	$( 'input.title', '#slides' ).each( function () { wptitlehint(this.id)} );

	$( '#slides' ).on( 'click.remove-slide', '.slide .button.remove', function() {
		$( this ).closest( '.slide' ).remove();
	} );

	$( '#slides' ).on( 'blur.update-slide-title', '.slide input.title', _.throttle( presenter_update_slide_title, 500 ) );

	$( '#slides' ).on( 'keyup.update-slide-title', '.slide input.title', _.throttle( presenter_update_slide_title, 500 ) );

	function presenter_update_slide_title() {
		$( this ).closest( '.slide' ).find( 'h3.hndle span' ).text( $( this ).val() );
	}

	$( '#slides .button.add' ).on( 'click.add-slide', function() {
		// Grab the HTML of a blank slide by cloning, appending to an element and grabbing the innerHTML
		var blank_slide = $('<p>').append( $('#slide-__i__').clone() ).html();

		var added = $(this).data( 'added' ) + 1;

		// Replace our special __i__ with the new slide number
		blank_slide = $( blank_slide.replace( /__i__/g, 'new-' + added ).replace( /__new__/g, '' ) );

		var title_id = 'slide-title-new-' + added;

		// Insert adjusted HTML after the last slide
		blank_slide.insertAfter( '#slides .stuffbox:last' ).find( '#' + title_id ).val( '' );
		wptitlehint( title_id );

		// Update the number of slides we've added
		$(this).data( 'added', added );
	} );

	$( '#slides .stuffbox .slide-hndle, #slides .stuffbox .handlediv' ).bind( 'click.postboxes', function() {
		$(this).parent( '.stuffbox' ).toggleClass('closed');
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

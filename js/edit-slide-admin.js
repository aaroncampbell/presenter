'use strict';

/* global _, jQuery, wptitlehint */

jQuery( document ).ready( function( $ ) {
	// Make the title hint work on all our slide titles
	$( 'input.title', '#slides' ).each( function() {
		wptitlehint( this.id );
	} );

	$( '#slides' ).on( 'click.show-hide-advanced', '.show-hide-advanced', function() {
		$( this ).toggleClass( 'show' ).toggleClass( 'hide' ).next( '.presenter-advanced' ).toggle( 400 );
	} );

	$( '#slides' ).on( 'click.remove-slide', '.slide .button.remove', function() {
		$( this ).closest( '.slide' ).remove();
	} );

	$( '#slides' ).on( 'blur.update-slide-title', '.slide input.title', _.throttle( updateSlideTitle, 500 ) );

	$( '#slides' ).on( 'keyup.update-slide-title', '.slide input.title', _.throttle( updateSlideTitle, 500 ) );

	function updateSlideTitle() {
		$( this ).closest( '.slide' ).find( 'h3.slide-hndle span.title' ).text( $( this ).val() );
	}

	// Initialize "added" data to track how many slides we've added
	$( '#slides' ).data( 'added', 0 );

	$( '#slides' ).on( 'click.add-slide', '.button.add', function() {
		// Grab the HTML of a blank slide by cloning, appending to an element and grabbing the innerHTML
		let blankSlide = $( '<p>' ).append( $( '#slide-__i__' ).clone() ).html();

		const added = $( '#slides' ).data( 'added' ) + 1;

		// Replace our special __i__ with the new slide number
		blankSlide = $( blankSlide.replace( /__(i|new)__/g, 'new-' + added ) );

		const titleId = 'slide-title-new-' + added;

		if ( $( this ).hasClass( 'before' ) ) {
			// Insert adjusted HTML before current slide
			blankSlide.insertBefore( $( this ).closest( '.stuffbox' ) ).find( '#' + titleId ).val( '' );
		} else if ( $( this ).hasClass( 'after' ) ) {
			// Insert adjusted HTML after current slide
			blankSlide.insertAfter( $( this ).closest( '.stuffbox' ) ).find( '#' + titleId ).val( '' );
		} else {
			// Insert adjusted HTML after the last slide
			blankSlide.insertAfter( $( '#slides .stuffbox' ).last() ).find( '#' + titleId ).val( '' );
		}
		wptitlehint( titleId );
		wp.editor.initialize( 'slide-content-new-' + added, {
			tinymce: {
				wpautop: true,
				setup( editor ) {
					editor.settings.toolbar1 = 'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,wp_more,spellchecker,wp_adv';
					editor.settings.toolbar2 = 'strikethrough,hr,forecolor,pastetext,removeformat,charmap,outdent,indent,undo,redo';
				}
			},
			quicktags: true
		});

		// Update the number of slides we've added
		$( '#slides' ).data( 'added', added );
	} );

	$( '#slides' ).on( 'click.add-data', '.button.add-data', function() {
		const tableBody = $( this ).closest( 'table.slide-data-attributes-table' ).find( 'tbody' );
		const slideIndex = $( this ).closest( '.stuffbox' ).find( 'input[name="slide-index"]' ).val();
		const dataRow = '<tr><td class="left newdataleft"><input type="text" name="slide-data[' + slideIndex + '][]"></td><td><input type="text" name="slide-data-value[' + slideIndex + '][]"></td></tr>';

		tableBody.append( dataRow );
	} );

	$( '#slides' ).on( 'click.postboxes', '.stuffbox .slide-hndle', function(e) {
		// Don't do this if the click was to move
		if ( ! $( e.target ).hasClass( 'move' ) ) {
			$( this ).parent( '.stuffbox' ).toggleClass( 'closed' );
		}
	});

	$( '#slides' ).on( 'click.move-slide', '.stuffbox .move', function() {
		if ( $( this ).hasClass( 'up' ) ) {
			// Going up
			const $slide = $( this ).closest( '.stuffbox' );
			const $prevSlide = $slide.prev( '.stuffbox' );

			if ( $prevSlide.length ) {
				$prevSlide.before( $slide );
			}
		} else if ( $( this ).hasClass( 'down' ) ) {
			// Going down
			const $slide = $( this ).closest( '.stuffbox' );
			const $nextSlide = $slide.next( '.stuffbox' );

			if ( $nextSlide.length ) {
				$nextSlide.after( $slide );
			}
		}
	});

	const isMobile = $( document.body ).hasClass( 'mobile' );
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

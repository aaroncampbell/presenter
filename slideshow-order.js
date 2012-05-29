jQuery('table.widefat tbody th, table.widefat tbody td').css('cursor','move');

jQuery("table.widefat tbody").sortable({
	items: 'tr:not(.inline-edit-row)',
	cursor: 'move',
	axis: 'y',
	containment: 'table.widefat',
	scrollSensitivity: 40,
	helper: function(e, ui) {
		// Keep the column widths from jumping
		ui.children().each( function() {
			jQuery(this).width( jQuery(this).width() );
		});
		return ui;
	},
	start: function(event, ui) {
		if ( ! ui.item.hasClass('alternate') ) ui.item.css( 'background-color', '#ffffff' );
		ui.item.children('td,th').css('border-bottom-width','0');
		ui.item.css( 'outline', '1px solid #dfdfdf' );
	},
	stop: function(event, ui) {
		ui.item.removeAttr('style');
		ui.item.children('td,th').css('border-bottom-width','1px');
	},
	update: function(event, ui) {
		jQuery('table.widefat tbody th, table.widefat tbody td').css('cursor','default');
		jQuery("table.widefat tbody").sortable('disable');

		var postid = ui.item.find('.check-column input').val();	// this post id
		var postparent = ui.item.find('.post_parent').html(); 	// post parent

		var prevpostid = ui.item.prev().find('.check-column input').val();
		var nextpostid = ui.item.next().find('.check-column input').val();

		// can only sort in same tree

		var prevpostparent = undefined;
		if ( prevpostid != undefined ) {
			var prevpostparent = ui.item.prev().find('.post_parent').html()
			if ( prevpostparent != postparent) prevpostid = undefined;
		}

		var nextpostparent = undefined;
		if ( nextpostid != undefined ) {
			nextpostparent = ui.item.next().find('.post_parent').html();
			if ( nextpostparent != postparent) nextpostid = undefined;
		}

		// show spinner
		ui.item.find('.check-column input').hide().after('<img alt="processing" src="images/wpspin_light.gif" class="waiting" style="margin-left: 6px;" />');

		// go do the sorting stuff via ajax
		jQuery.post( ajaxurl, { action: 'order-slides', id: postid, previd: prevpostid, nextid: nextpostid }, function(response){
			console.log(response);
			jQuery.each( response, function( key, val ) {
				jQuery('#post-'+key+' .menu_order').html(val);
				jQuery('#inline_'+key+' .menu_order').html(val);
			});
			ui.item.find('.check-column input').show().siblings('img').remove();
			jQuery('table.widefat tbody th, table.widefat tbody td').css('cursor','move');
			jQuery("table.widefat tbody").sortable('enable');
		});

		// fix row colors
		jQuery( 'table.widefat tbody tr:even' ).addClass('alternate');
		jQuery( 'table.widefat tbody tr:odd' ).removeClass('alternate');
	}
});

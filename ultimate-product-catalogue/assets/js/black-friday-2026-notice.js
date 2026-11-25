(function( $ ) {
	'use strict';
	if ( typeof ewd_upcp_bf2026_notice === 'undefined' ) return;
	$( function() {
		$( '.ewd-upcp-bf2026-notice' ).on( 'click', '.ewd-upcp-bf2026-dismiss', function() {
			var notice = $( this ).closest( '.ewd-upcp-bf2026-notice' );
			var buttons = notice.find( '.ewd-upcp-bf2026-dismiss' );
			var status = notice.find( '.ewd-upcp-bf2026-status' );
			var mode = $( this ).attr( 'data-dismissal' );
			buttons.prop( 'disabled', true );
			notice.attr( 'aria-busy', 'true' );
			status.text( '' );
			function failed( response ) {
				buttons.prop( 'disabled', false );
				notice.removeAttr( 'aria-busy' );
				status.text( response && response.data && response.data.message ? response.data.message : ewd_upcp_bf2026_notice.error );
			}
			$.ajax( {
				url: ewd_upcp_bf2026_notice.url,
				method: 'POST',
				dataType: 'json',
				data: { action: ewd_upcp_bf2026_notice.action, nonce: ewd_upcp_bf2026_notice.nonce, dismissal: mode }
			} ).done( function( response ) {
				if ( response && response.success ) notice.remove();
				else failed( response );
			} ).fail( function( xhr ) { failed( xhr.responseJSON ); } );
		} );
	} );
})( jQuery );

jQuery( document ).ready( function( $ ) {

  jQuery(document).on( 'click', '.ewd-upcp-helper-install-notice .notice-dismiss', function( event ) {
    var data = jQuery.param({
      action: 'ewd_upcp_hide_helper_notice',
      nonce: ewd_upcp_helper_notice.nonce
    });

    jQuery.post( ajaxurl, data, function() {} );
  });
});

/* NEW PLUGIN NOTICE */

jQuery( document ).ready( function( $ ) {

  jQuery(document).on( 'click', '.ait-aiaa-new-plugin-notice .notice-dismiss', function( event ) {
    var data = jQuery.param({
      action: 'ewd_upcp_hide_new_plugin_notice',
      plugin: 'ait_aiaa',
      nonce: ewd_upcp_helper_notice.nonce
    });

    jQuery.post( ajaxurl, data, function() {} );
  });
});
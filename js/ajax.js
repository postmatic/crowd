function safe_report_comments_flag_comment( comment_id, nonce, result_id ) {
	jQuery.post( 
		SafeCommentsAjax.ajaxurl,
		{
			comment_id : comment_id,
			sc_nonce : nonce,
			result_id : result_id,
			action : 'safe_report_comments_flag_comment',
			xhrFields: {
				withCredentials: true
			}
		},
		function(data) { 
    		$parent = jQuery( '#comment-' + comment_id );
    		$child = $parent.find( '#' + result_id ).html( data );
    		setTimeout( function() {
        		$child.fadeOut( 'slow', function() {
            		//todo maybe do some ajax and see if flagged here for Epoch
                } );
            }, 2000 );
        }
	);
	return false;
}

jQuery( document ).ready( function() {
	jQuery( '.hide-if-js' ).hide();
	jQuery( '.hide-if-no-js' ).show();
});

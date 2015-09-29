function crowd_control_comments_flag_comment( comment_id, nonce, result_id ) {
	/* http://www.quirksmode.org/js/cookies.html */
	function createCookie(name,value,days) {
    	if (days) {
    		var date = new Date();
    		date.setTime(date.getTime()+(days*24*60*60*1000));
    		var expires = "; expires="+date.toGMTString();
    	}
    	else var expires = "";
    	document.cookie = name+"="+value+expires+"; path=/";
    }
    
    function readCookie(name) {
    	var nameEQ = name + "=";
    	var ca = document.cookie.split(';');
    	for(var i=0;i < ca.length;i++) {
    		var c = ca[i];
    		while (c.charAt(0)==' ') c = c.substring(1,c.length);
    		if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length,c.length);
    	}
    	return null;
    }
    
    function eraseCookie(name) {
    	createCookie(name,"",-1);
    }
	
	/* Don't Allow Double Reporting */
	if ( 'true' == readCookie( 'cc_report_' + comment_id ) ) {
    	alert( pmcc_ajax.errors.already_flagged_message );
        return false;	
    }
	
	jQuery.post( 
		pmcc_ajax.ajaxurl,
		{
			comment_id : comment_id,
			sc_nonce : nonce,
			result_id : result_id,
			action : 'pmcc_report_comments_flag_comment',
			xhrFields: {
				withCredentials: true
			}
		},
		function(data) { 
    		if ( !data.errors ) {
        		createCookie( 'cc_report_' + comment_id, 'true', 30 );
        		$parent = jQuery( '#comment-' + comment_id );
        		$child = $parent.find( '.pmcc-comments-report-link:first' ).fadeOut( 'fast', function() {
            		jQuery( this ).html( '<strong>&#x2713;</strong>' );
            		jQuery( this ).fadeIn();
                } );
            } else {
                alert( pmcc_ajax.errors[ data.code ] );
            }
    		
        }
	);
	return false;
}

jQuery( document ).ready( function() {
	jQuery( '.hide-if-js' ).hide();
	jQuery( '.hide-if-no-js' ).show();
});

(function( $ ) {
	'use strict';
    $( document ).ready(function() {
        jQuery("#checkview_update_cache").click( function(e){ 

            e.preventDefault();
            var $thisButton = $(this);
            $thisButton.removeClass('success error').addClass('loading');
            Swal.fire({
                title: 'Are you sure?',
                text:  'Cache will be updated permanently!',
                icon:  'warning',
                showCancelButton: true,
                confirmButtonText: 'Update cache',
                cancelButtonText: 'Cancel'
              }).then((result) => {
                if ( result.value ) {
    
                    $.ajax({
                        url: checkview_ajax_obj.ajaxurl,
                        type: 'post',
                        data: {
                            'action':'checkview_update_cache',
                            'user_id': checkview_ajax_obj.user_id,
                            _nonce   : $thisButton.data('nonce')
                        },beforeSend: function() {
                            Swal.fire({
                                title: 'Success',
                                text: 'Update in progress it will take some time!',
                                icon: 'success',
                                showCancelButton: false,
                                confirmButtonText: 'Ok',
                                timer: 3000,
                            })
                            $thisButton.removeClass('loading error').addClass('success');
                        },
                        success: function( response ) {
    
                                // jQuery hands us a parsed object when the server sends
                                // application/json, or a raw string otherwise; accept both.
                                var tokenObj = ( typeof response === 'string' ) ? JSON.parse( response ) : response;
                                 if( !tokenObj.success && tokenObj != '0'){
    
                                    Swal.fire({
                                        title: 'Error',
                                        text: tokenObj.message,
                                        icon: 'warning',
                                        showCancelButton: false,
                                        confirmButtonText: 'Ok',
    
                                    })
                                    $thisButton.removeClass('loading success').addClass('error');
    
                                } else {
                                    Swal.fire({
                                        title: 'Success',
                                        text:  (tokenObj != '0' ? tokenObj.message : 'Updated Successfully.'),
                                        icon: 'success',
                                        showCancelButton: false,
                                        confirmButtonText: 'Ok',
                                    })
                                    $thisButton.removeClass('loading error').addClass('success');
                                }

                        },
                        // Non-2xx responses (capability or nonce rejection) never reach
                        // `success`; without this the button stays stuck in `loading`.
                        error: function( xhr ) {
                            var message = 'Cache could not be updated.';
                            try {
                                var errObj = JSON.parse( xhr.responseText );
                                if ( errObj && errObj.message ) {
                                    message = errObj.message;
                                }
                            } catch ( e ) {}
                            Swal.fire({
                                title: 'Error',
                                text: message,
                                icon: 'warning',
                                showCancelButton: false,
                                confirmButtonText: 'Ok',
                            })
                            $thisButton.removeClass('loading success').addClass('error');
                        },
                    });
                } else {
                    $thisButton.removeClass('loading success error');
                } //endif
            })
        });
    });
})( jQuery );
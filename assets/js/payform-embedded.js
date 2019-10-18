(function ($) {
	var pay_type;
	function handleSubmitPayformPayment() {
		if($('#payment_method_bambora_payform_embedded_card').is(':checked')){
			var $form = $('form.checkout, form#order_review');
			var form_data = $form.data();
			$form.addClass( 'processing' );
			var send_data = $form.serialize();
			var send_url = wc_checkout_params.checkout_url;
			if(pay_type == 2)
				send_url = $('input[name=_wp_http_referer]').val();

			if ( 1 !== form_data['blockUI.isBlocked'] ) {
				$form.block({
					message: null,
					overlayCSS: {
						background: '#fff',
						opacity: 0.6
					}
				});
			}

			$.ajax({
				type:		'POST',
				url:		send_url,
				data:		send_data,
				dataType:   'json',
				success:	function( result ) {
					try {
						if ( 'success' === result.result ) {
							if ( undefined !== result.bpf_token) {
								var paymessage = {
									action: 'pay',
									token: result.bpf_token,
								};
								document.getElementById('pf-cc-iframe').contentWindow.postMessage(JSON.stringify(paymessage), 'https://payform.bambora.com');
								return false;
							} else {
								throw 'Invalid response';
							}
						} else if ( 'failure' === result.result ) {
							throw 'Result failure';
						} else {
							throw 'Invalid response';
						}
					} catch( err ) {
						// Reload page
						if(result === null)
						{
							window.location.reload();
							return false;
						}
						
						if ( true === result.reload ) {
							window.location.reload();
							return;
						}

						// Trigger update in case we need a fresh nonce
						if ( true === result.refresh ) {
							$( document.body ).trigger( 'update_checkout' );
						}

						// Add new errors
						if ( result.messages ) {
							submit_error( result.messages );
						} else {
							submit_error( '<div class="woocommerce-error">' + wc_checkout_params.i18n_checkout_error + '</div>' );
						}
					}
				},
				error:	function( jqXHR, textStatus, errorThrown ) {
					submit_error( '<div class="woocommerce-error">' + errorThrown + '</div>' );
				}
			});
			return false;
		}
		return true;
	}

	function submit_error( error_message ) {
		$( '.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message' ).remove();
		$( 'form.checkout' ).prepend( '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' + error_message + '</div>' );
		$( 'form.checkout' ).removeClass( 'processing' ).unblock();
		$( 'form.checkout' ).find( '.input-text, select, input:checkbox' ).trigger( 'validate' ).blur();
		scroll_to_notices();
		$( document.body ).trigger( 'checkout_error' );
	}

	function scroll_to_notices() {
		var scrollElement = $( '.woocommerce-NoticeGroup-updateOrderReview, .woocommerce-NoticeGroup-checkout' );

		if ( ! scrollElement.length ) {
			scrollElement = $( 'form.checkout' );
		}
		$.scroll_to_notices( scrollElement );
	}

	$(function () {
		/* override checkout form, should probably unbind the normal checkout trigger*/
		$('form.checkout').on( 'checkout_place_order_bambora_payform_embedded_card', function () {
			if($('#payment_method_bambora_payform_embedded_card').is(':checked')){
				var validatemessage = {
					action: 'validate'
				};
				pay_type = 1;
				document.getElementById('pf-cc-iframe').contentWindow.postMessage(JSON.stringify(validatemessage), 'https://payform.bambora.com');
				return false; // prevents the default functionality
			}
			return true;
		});

		$('form#order_review').on( 'submit', function () {
			if($('#payment_method_bambora_payform_embedded_card').is(':checked')){
				var validatemessage = {
					action: 'validate'
				};
				pay_type = 2;
				document.getElementById('pf-cc-iframe').contentWindow.postMessage(JSON.stringify(validatemessage), 'https://payform.bambora.com');
				return false; // prevents the default functionality
			}
			return true;
		});


		window.addEventListener('message',function(event) {
			if ( event.origin !== 'https://payform.bambora.com' ) { return false; }
			
			var data = JSON.parse(event.data);
			if(data !== null && typeof data.valid !== 'undefined' && data.valid === true) {
				handleSubmitPayformPayment();
			}
			else {
				return false;
			}

		},false);
	});

}(jQuery));
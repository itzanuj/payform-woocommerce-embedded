<?php
/**
 * Plugin Name: Bambora PayForm Embedded Card Payment Gateway
 * Plugin URI: https://payform.bambora.com/docs
 * Description: Bambora PayForm Payment Gateway Embedded Card Integration for Woocommerce
 * Version: 1.4.0
 * Author: Bambora
 * Author URI: https://www.bambora.com/fi/fi/Verkkokauppa/Payform/
 * Text Domain: bambora-payform-embedded-card-payment-gateway
 * Domain Path: /languages
 * WC requires at least: 3.0.0
 * WC tested up to: 4.5.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action('plugins_loaded', 'init_bambora_payform_embedded_card_gateway', 0);

function woocommerce_add_WC_Gateway_bambora_payform_embedded_card($methods)
{
	$methods[] = 'WC_Gateway_bambora_payform_embedded_card';
	return $methods;
}
add_filter('woocommerce_payment_gateways', 'woocommerce_add_WC_Gateway_bambora_payform_embedded_card');

function init_bambora_payform_embedded_card_gateway()
{
	load_plugin_textdomain('bambora-payform-embedded-card-payment-gateway', false, dirname(plugin_basename(__FILE__)) . '/languages/' );

	if(!class_exists('WC_Payment_Gateway'))
		return;

	class WC_Gateway_bambora_payform_embedded_card extends WC_Payment_Gateway
	{
		public function __construct()
		{
			$this->id = 'bambora_payform_embedded_card';
			$this->has_fields = true;
			$this->method_title = __( 'Bambora PayForm (Embedded Card)', 'bambora-payform-embedded-card-payment-gateway' );
			$this->method_description = __( 'Bambora PayForm (Embedded Card) w3-API Payment Gateway integration for Woocommerce', 'bambora-payform-embedded-card-payment-gateway' );

			$this->supports = array(
				'products', 
				'subscriptions',
				'subscription_cancellation', 
				'subscription_suspension', 
				'subscription_reactivation',
				'subscription_amount_changes',
				'subscription_date_changes',
				'subscription_payment_method_change',
				'subscription_payment_method_change_customer',
				'multiple_subscriptions' 
			);

			$this->init_form_fields();
			$this->init_settings();

			$this->enabled = $this->settings['enabled'];
			$this->title = $this->get_option('title');

			$this->api_key = $this->get_option('api_key');
			$this->private_key = $this->get_option('private_key');

			$this->ordernumber_prefix = $this->get_option('ordernumber_prefix');

			$this->send_items = $this->get_option('send_items');
			$this->send_receipt = $this->get_option('send_receipt');

			$this->cancel_url = $this->get_option('cancel_url');
			$this->limit_currencies = $this->get_option('limit_currencies');

			$this->visa_logo = $this->get_option('visa_logo');
			$this->mc_logo = $this->get_option('mc_logo');
			$this->amex_logo = $this->get_option('amex_logo');
			$this->diners_logo = $this->get_option('diners_logo');

			add_action('admin_notices', array($this, 'payform_admin_notices'));
			add_action('wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options' ) );
			add_action('woocommerce_api_wc_gateway_bambora_payform_embedded_card', array($this, 'check_bambora_payform_embedded_card_response' ) );
			add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'bambora_payform_embedded_card_settle_payment'), 1, 1);

			add_action('woocommerce_scheduled_subscription_payment_' . $this->id, array($this, 'scheduled_subscription_payment'), 10, 2);
			add_action('woocommerce_subscription_cancelled_' . $this->id, array($this, 'subscription_cancellation'));

			// registering a new card token with 0 amount is not supported at the moment so don't allow this option
			add_filter('wcs_view_subscription_actions', array (__CLASS__, 'remove_change_payment_method_button'), 100, 2);

			if(!$this->is_valid_currency() && $this->limit_currencies == 'yes')
				$this->enabled = false;

			$this->logger = new WC_Logger();
		}

		public function payform_admin_notices() 
		{
			if($this->settings['enabled'] == 'no')
				return;
		}

		public function is_valid_currency()
		{
			return in_array(get_woocommerce_currency(), array('EUR'));
		}

		public function init_form_fields()
		{
			$this->form_fields = array(
				'general' => array(
					'title' => __( 'General options', 'bambora-payform-embedded-card-payment-gateway' ),
					'type' => 'title',
					'description' => '',
				),
				'enabled' => array(
					'title' => __( 'Enable/Disable', 'bambora-payform-embedded-card-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( 'Enable Bambora PayForm (Embedded Card)', 'bambora-payform-embedded-card-payment-gateway' ),					
					'default' => 'yes'
				),
				'title' => array(
					'title' => __( 'Title', 'bambora-payform-embedded-card-payment-gateway' ),
					'type' => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'bambora-payform-embedded-card-payment-gateway' ),
					'default' => __( 'Bambora PayForm (Embedded Card)', 'bambora-payform-embedded-card-payment-gateway' )
				),				
				'private_key' => array(
					'title' => __( 'Private key', 'bambora-payform-embedded-card-payment-gateway' ),
					'type' => 'text',
					'description' => __( 'Private key of the sub-merchant', 'bambora-payform-embedded-card-payment-gateway' ),
					'default' => ''
				),
				'api_key' => array(
					'title' => __( 'API key', 'bambora-payform-embedded-card-payment-gateway' ),
					'type' => 'text',
					'description' => __( 'API key of the sub-merchant', 'bambora-payform-embedded-card-payment-gateway' ),
					'default' => ''
				),
				'ordernumber_prefix' => array(
					'title' => __( 'Order number prefix', 'bambora-payform-embedded-card-payment-gateway' ),
					'type' => 'text',
					'description' => __( 'Prefix to avoid order number duplication', 'bambora-payform-embedded-card-payment-gateway' ),
					'default' => ''
				),
				'send_items' => array(
					'title' => __( 'Send products', 'bambora-payform-embedded-card-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( "Send product breakdown to Bambora PayForm (Embedded Card).", 'bambora-payform-embedded-card-payment-gateway' ),
					'default' => 'yes'
				),
				'send_receipt' => array(
					'title' => __( 'Send payment confirmation', 'bambora-payform-embedded-card-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( "Send Bambora PayForm (Embedded Card)'s payment confirmation email to the customer's billing e-mail.", 'bambora-payform-embedded-card-payment-gateway' ),
					'default' => 'yes',
				),
				'limit_currencies' => array(
					'title' => __( 'Only allow payments in EUR', 'bambora-payform-embedded-card-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( "Enable this option if you want to allow payments only in EUR.", 'bambora-payform-embedded-card-payment-gateway' ),
					'default' => 'yes',
				),
				'cancel_url' => array(
					'title' => __( 'Cancel Page', 'bambora-payform-embedded-card-payment-gateway' ),
					'type' => 'select',
					'description' => 
						__( 'Choose the page where the customer is redirected after a canceled/failed payment.', 'bambora-payform-embedded-card-payment-gateway' ) . '<br>'.
						' - ' . __( 'Order Received: Shows the customer information about their order and a notice that the payment failed. Customer has an opportunity to try payment again.', 'bambora-payform-embedded-card-payment-gateway' ) . '<br>'.
						' - ' .__( 'Pay for Order: Returns user to a page where they can try to pay their unpaid order again. ', 'bambora-payform-embedded-card-payment-gateway' ) . '<br>'.
						' - ' .__( 'Cart: Customer is redirected back to the shopping cart.' , 'bambora-payform-embedded-card-payment-gateway' ) . '<br>'.
						' - ' .__( 'Checkout: Customer is redirected back to the checkout.', 'bambora-payform-embedded-card-payment-gateway' ) . '<br>'.
						'<br>' .__( '(When using Cart or Checkout as the return page for failed orders, the customer\'s cart will not be emptied during checkout.)', 'bambora-payform-embedded-card-payment-gateway' ),
					'default' => 'order_new_checkout',
					'options' => array(
						'order_received' => __('Order Received', 'bambora-payform-embedded-card-payment-gateway'),
						'order_pay' => __('Pay for Order', 'bambora-payform-embedded-card-payment-gateway'),
						'order_new_cart' => __('Cart', 'bambora-payform-embedded-card-payment-gateway'),
						'order_new_checkout' => __('Checkout', 'bambora-payform-embedded-card-payment-gateway')
					)
				),
				'displaylogos' => array(
					'title' => __( 'Display card logos', 'bambora-payform-embedded-card-payment-gateway' ),
					'type' => 'title',
					'description' => '',
				),
				'visa_logo' => array(
					'title' => __( 'Visa', 'bambora-payform-embedded-card-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( 'Display Visa and Verified by Visa logo below the form.', 'bambora-payform-embedded-card-payment-gateway' ),					
					'default' => 'yes'
				),
				'mc_logo' => array(
					'title' => __( 'Mastercard', 'bambora-payform-embedded-card-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( 'Display Mastercard and Mastercard SecureCode logo below the form.', 'bambora-payform-embedded-card-payment-gateway' ),					
					'default' => 'yes'
				),
				'amex_logo' => array(
					'title' => __( 'American Express', 'bambora-payform-embedded-card-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( 'Display American Express logo below the form.', 'bambora-payform-embedded-card-payment-gateway' ),					
					'default' => 'no'
				),
				'diners_logo' => array(
					'title' => __( 'Diners Club', 'bambora-payform-embedded-card-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( 'Display Diners Club logo below the form.', 'bambora-payform-embedded-card-payment-gateway' ),					
					'default' => 'no'
				)
			);
		}

		public function payment_scripts()
		{
			if(!(is_checkout() || $this->is_available()))
				return;
			wp_enqueue_style( 'woocommerce_bambora_payform_embedded_card', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) . '/assets/css/payform-embedded.css', '', '', 'all');
			wp_enqueue_script( 'woocommerce_bambora_payform_embedded_card', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) . '/assets/js/payform-embedded.js', array( 'jquery' ), '', true );
		}

		public function payment_fields()
		{
			$img_url = untrailingslashit(plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__))) . '/assets/images/';
			$clear_both = '<div style="display: block; clear: both;"></div>';

			echo '<div id="bambora-payform-embedded-card-payment-content">'.wpautop(wptexturize(__( 'Payment card', 'bambora-payform-embedded-card-payment-gateway' ))) . "<div id='pf-cc-form'><iframe frameBorder='0' scrolling='no' id='pf-cc-iframe' class='intrinsic-ignore' height='220px' style='width:100%' src='https://payform.bambora.com/e-payments/embedded_card_form?lang=".$this->get_lang()."'></iframe></div>" . $clear_both;

			if($this->visa_logo === 'yes' || $this->mc_logo === 'yes' || $this->amex_logo === 'yes' || $this->diners_logo === 'yes')
			{
				echo '<div class="bpfe-card-brand-row">';
				if($this->visa_logo === 'yes')
				{
					echo '<div class="bpfe-card-brand-container"><img class="bpfe-card-brand-logo visa" src="' . $img_url . 'visa.png" alt="Visa"/></div>';
					echo '<div class="bpfe-card-brand-container"><img class="bpfe-card-brand-logo verified" src="' . $img_url . 'verified.png" alt="Verified By Visa"/></div>';
				}

				if($this->mc_logo === 'yes')
				{
					echo '<div class="bpfe-card-brand-container"><img class="bpfe-card-brand-logo" src="' . $img_url . 'mastercard.png" alt="MasterCard" /></div>';
					echo '<div class="bpfe-card-brand-container"><img class="bpfe-card-brand-logo" src="' . $img_url . 'securecode.png" alt="MasterCard SecureCode"/></div>';
				}

				if($this->amex_logo === 'yes')
					echo '<div class="bpfe-card-brand-container"><img class="bpfe-card-brand-logo" src="' . $img_url . 'dinersclub.png" alt="American Express" /></div>';

				if($this->diners_logo === 'yes')
					echo '<div class="bpfe-card-brand-container"><img class="bpfe-card-brand-logo" src="' . $img_url . 'americanexpress.png" alt="Diners" /></div>';

				echo $clear_both . '</div>' . $clear_both;
			}

			echo '</div>';
		}

		protected function get_lang()
		{
			$finn_langs = array('fi-FI', 'fi', 'fi_FI');
			$sv_langs = array('sv-SE', 'sv', 'sv_SE');
			$current_locale = get_locale();

			if(in_array($current_locale, $finn_langs))
				$lang = 'fi';
			else if (in_array($current_locale, $sv_langs))
				$lang = 'sv';
			else
				$lang = 'en';
			
			return $lang;
		}

		public function process_payment($order_id)
		{
			if (sanitize_key($_POST['payment_method']) != 'bambora_payform_embedded_card')
				return false;

			require_once(plugin_dir_path( __FILE__ ).'includes/lib/bambora_payform_loader.php');

			$order = new WC_Order($order_id);
			$wc_order_id = $order->get_id();
			$wc_order_total = $order->get_total();

			$order_number = (strlen($this->ordernumber_prefix)  > 0) ?  $this->ordernumber_prefix. '_'  .$order_id : $order_id;
			$order_number .=  '-' . str_pad(time().rand(0,9999), 5, "1", STR_PAD_RIGHT);

			$redirect_url = $this->get_return_url($order);

			$return_url = add_query_arg( array('wc-api' => get_class( $this ) ,'order_id' => $order_id), $redirect_url );

			$amount =  (int)(round($wc_order_total*100, 0));

			$order->update_meta_data('bambora_payform_embedded_card_is_settled', 99);
			$order->update_meta_data('bambora_payform_embedded_card_return_code', 99);
			$order->save();

			$lang = $this->get_lang();

			$payment = new Bambora\PayForm($this->api_key, $this->private_key, 'w3.1', new Bambora\PayFormWPConnector());

			if($this->send_receipt == 'yes')
				$receipt_mail = $wc_b_email;
			else
				$receipt_mail = '';

			$payment->addCharge(
				array(
					'order_number' => $order_number,
					'amount' => $amount,
					'currency' => get_woocommerce_currency(),
					'email' =>  $receipt_mail
				)
			);

			$payment = $this->add_customer_to_payment($payment, $order);

			if($this->send_items == 'yes')
			{
				$payment = $this->add_products_to_payment($payment, $order, $amount);
			}

			$register_card_token = 0;

			if(function_exists('wcs_order_contains_subscription')) 
			{
				if(wcs_order_contains_subscription($order) || wcs_order_contains_renewal($order) || wcs_is_subscription($order))
				{
            		$register_card_token =  1;
				}
        	}

			$payment->addPaymentMethod(
				array(
					'type' => 'embedded', 
					'return_url' => $return_url,
					'notify_url' => $return_url,
					'lang' => $lang,
					'token_valid_until' => strtotime('+1 hour'),
					'register_card_token' => $register_card_token
				)
			);

			if(!$this->is_valid_currency() && $this->limit_currencies == 'no')
			{
				$available = false;
				$payment_methods = new Bambora\PayForm($this->api_key, $this->private_key, 'w3.1', new Bambora\PayFormWPConnector());
				try
				{
					$response = $payment_methods->getMerchantPaymentMethods(get_woocommerce_currency());

					if($response->result == 0)
					{
						if(count($response->payment_methods) > 0)
						{
							foreach ($response->payment_methods as $method)
							{
								if($method->group == 'creditcards')
									$available = true;
							}
						}

						if(!$available)
						{
							$this->logger->add( 'bambora-payform-embedded-card-payment-gateway', 'Bambora PayForm no payment methods available for order: ' . $order_number . ', currency: ' . get_woocommerce_currency());
							wc_add_notice(__('Bambora PayForm: No payment methods available for the currency: ', 'bambora-payform-embedded-card-payment-gateway') . get_woocommerce_currency(), 'error');
							$order_number_text = __('Bambora PayForm: No payment methods available for the currency: ', 'bambora-payform-embedded-card-payment-gateway') .  get_woocommerce_currency();
							$order->add_order_note($order_number_text);
							return;
						}
					}
				}
				catch (Bambora\PayFormException $e) 
				{
					$this->logger->add( 'bambora-payform-embedded-card-payment-gateway', 'Bambora PayForm getMerchantPaymentMethods failed for order: ' . $order_number . ', exception: ' . $e->getCode().' '.$e->getMessage());
				}
			}
			else if(!$this->is_valid_currency())
			{
				$error_text = __('Bambora PayForm: "Only allow payments in EUR" is enabled and currency was not EUR for order: ', 'bambora-payform-embedded-card-payment-gateway');
				$this->logger->add( $error_text . $order_number);
				wc_add_notice(__('Bambora PayForm: No payment methods available for the currency: ', 'bambora-payform-embedded-card-payment-gateway') . get_woocommerce_currency(), 'notice');
				$order->add_order_note($error_text . $order_number);
				return;
			}

			try
			{
				$response = $payment->createCharge();

				if($response->result == 0)
				{
					$order_number_text = __('Bambora PayForm (Embedded Card) order', 'bambora-payform-embedded-card-payment-gateway') . ": " . $order_number . "<br>-<br>" . __('Payment pending. Waiting for result.', 'bambora-payform-embedded-card-payment-gateway');
					$order->add_order_note($order_number_text);

					$order->update_meta_data('bambora_payform_embedded_card_order_number', $order_number);
					$order_numbers = get_post_meta($order_id, 'bambora_payform_embedded_card_order_numbers', true);
					$order_numbers = ($order_numbers) ? array_values($order_numbers) : array();
					$order_numbers[] = $order_number;
					$order->update_meta_data('bambora_payform_embedded_card_order_numbers', $order_numbers);
					$order->save();
					
					if(!in_array($this->cancel_url, array('order_new_cart', 'order_new_checkout')))
						WC()->cart->empty_cart();

					$return = array(
						'result'   => 'success',
						'bpf_token' => $response->token
					);				
				}
				else if($response->result == 10)
				{
					$errors = '';
					wc_add_notice(__('Bambora PayForm (Embedded Card) system is currently in maintenance. Please try again in a few minutes.', 'bambora-payform-embedded-card-payment-gateway'), 'notice');
					$this->logger->add( 'bambora-payform-embedded-card-payment-gateway', 'Bambora PayForm (Embedded Card)::CreateCharge. PayForm system maintenance in progress.');
					$return = null;
				}
				else
				{
					$errors = '';
					wc_add_notice(__('Payment failed due to an error.', 'bambora-payform-embedded-card-payment-gateway'), 'error');
					if(isset($response->errors))
					{
						foreach ($response->errors as $error) 
						{
							$errors .= ' '.$error;
						}
					}
					$this->logger->add( 'bambora-payform-embedded-card-payment-gateway', 'Bambora PayForm (Embedded Card)::CreateCharge failed, response: ' . $response->result . ' - Errors:'.$errors);
					$return = null;
				}

			}
			catch (Bambora\PayFormException $e) 
			{
				wc_add_notice(__('Payment failed due to an error.', 'bambora-payform-embedded-card-payment-gateway'), 'error');
				$this->logger->add( 'bambora-payform-embedded-card-payment-gateway', 'Bambora PayForm (Embedded Card)::CreateCharge failed, exception: ' . $e->getCode().' '.$e->getMessage());
				$return = null;
			}

			if($_REQUEST['pay_for_order'] == true)
			{
				echo json_encode($return);
				exit();
			}

			return $return;
		}

		protected function get_order_by_id_and_order_number($order_id, $order_number)
		{
			$order = New WC_Order($order_id);

			$order_numbers = get_post_meta($order_id, 'bambora_payform_embedded_card_order_numbers', true);

			if(!$order_numbers)
			{
				$current_order_number = get_post_meta($order_id, 'bambora_payform_embedded_card_order_number', true);
				$order_numbers = array($current_order_number);
			}

			if(in_array($order_number, $order_numbers, true));
				return $order;

			return null;
		}

		protected function sanitize_payform_order_number($order_number)
		{
			return preg_replace('/[^\-\p{L}\p{N}_\s@&\/\\()?!=+£$€.,;:*%]/', '', $order_number);
		}

		public function check_bambora_payform_embedded_card_response()
		{
			if(count($_GET))
			{
				require_once(plugin_dir_path( __FILE__ ).'includes/lib/bambora_payform_loader.php');
				$return_code = isset($_GET['RETURN_CODE']) ? sanitize_text_field($_GET['RETURN_CODE']) : -999;
				$incident_id = isset($_GET['INCIDENT_ID']) ? sanitize_text_field($_GET['INCIDENT_ID']) : null;
				$settled = isset($_GET['SETTLED']) ? sanitize_text_field($_GET['SETTLED']) : null;
				$authcode = isset($_GET['AUTHCODE']) ? sanitize_text_field($_GET['AUTHCODE']) : null;
				$contact_id = isset($_GET['CONTACT_ID']) ? sanitize_text_field($_GET['CONTACT_ID']) : null;
				$order_number = isset($_GET['ORDER_NUMBER']) ? $this->sanitize_payform_order_number($_GET['ORDER_NUMBER']) : null;

				$authcode_confirm = $return_code .'|'. $order_number;

				if(isset($return_code) && $return_code == 0)
				{
					$authcode_confirm .= '|' . $settled;
					if(isset($contact_id) && !empty($contact_id))
						$authcode_confirm .= '|' . $contact_id;
				}
				else if(isset($incident_id) && !empty($incident_id))
					$authcode_confirm .= '|' . $incident_id;

				$authcode_confirm = strtoupper(hash_hmac('sha256', $authcode_confirm, $this->private_key));

				$order_id = isset($_GET['order_id']) ? sanitize_text_field($_GET['order_id']) : null;
				
				if($order_id === null || $order_number === null)
					$this->bambora_payform_embedded_die("No order_id nor order_number given.");

				$order = $this->get_order_by_id_and_order_number($order_id, $order_number);
				
				if($order === null)
					$this->bambora_payform_embedded_die("Order not found.");

				$wc_order_id = $order->get_id();
				$wc_order_status = $order->get_status();

				if($authcode_confirm === $authcode && $order)
				{
					$current_return_code = get_post_meta($wc_order_id, 'bambora_payform_embedded_card_return_code', true);

					if(!$order->is_paid() && $current_return_code != 0)
					{
						$pbw_extra_info = '';

						$payment = new Bambora\PayForm($this->api_key, $this->private_key, 'w3.1', new Bambora\PayFormWPConnector());
						try
						{
							$result = $payment->checkStatusWithOrderNumber($order_number);
							if(isset($result->source->object) && $result->source->object === 'card')
							{
								$pbw_extra_info .=  "<br>-<br>" . __('Payment method: Card payment', 'bambora-payform-embedded-card-payment-gateway') . "<br>";
								$pbw_extra_info .=  "<br>-<br>" . __('Card payment info: ', 'bambora-payform-embedded-card-payment-gateway') . "<br>";

								if(isset($result->source->card_verified))
								{
									$pbw_verified = $this->bambora_payform_embedded_card_translate_verified_code($result->source->card_verified);
									$pbw_extra_info .= isset($pbw_verified) ? __('Verified: ', 'bambora-payform-embedded-card-payment-gateway') . $pbw_verified . "<br>" : '';
								}

								$pbw_extra_info .= isset($result->source->card_country) ? __('Card country: ', 'bambora-payform-embedded-card-payment-gateway') . $result->source->card_country . "<br>" : '';
								$pbw_extra_info .= isset($result->source->client_ip_country) ? __('Client IP country: ', 'bambora-payform-embedded-card-payment-gateway') . $result->source->client_ip_country . "<br>" : '';

								if(isset($result->source->error_code))
								{
									$pbw_error = $this->bambora_payform_embedded_card_translate_error_code($result->source->error_code);
									$pbw_extra_info .= isset($pbw_error) ? __('Error: ', 'bambora-payform-embedded-card-payment-gateway') . $pbw_error . "<br>" : '';
								}

							}
							elseif (isset($result->source->brand))
								$pbw_extra_info .=  "<br>-<br>" . __('Payment method: ', 'bambora-payform-embedded-card-payment-gateway') . ' ' . $result->source->brand . "<br>";
						}
						catch(Bambora\PayFormException $e)
						{
							$message = $e->getMessage();
							$this->logger->add( 'bambora-payform-embedded-card-payment-gateway', 'Bambora PayForm (Embedded Card) REST::checkStatusWithOrderNumber failed, message: ' . $message);
						}

						switch($return_code)
						{
							case 0:
								if($settled == 0)
									$pbw_note = __('Bambora PayForm (Embedded Card) order', 'bambora-payform-embedded-card-payment-gateway') . ' ' . $order_number . "<br>-<br>" . __('Payment is authorized. Use settle option to capture funds.', 'bambora-payform-embedded-card-payment-gateway') . "<br>";
								else
									$pbw_note = __('Bambora PayForm (Embedded Card) order', 'bambora-payform-embedded-card-payment-gateway') . ' ' . $order_number . "<br>-<br>" . __('Payment accepted.', 'bambora-payform-embedded-card-payment-gateway') . "<br>";

								$is_settled = ($settled == 0) ? 0 : 1;
								$order->update_meta_data('bambora_payform_embedded_card_order_number', $order_number);
								$order->update_meta_data('bambora_payform_embedded_card_is_settled', $is_settled);
								$order->save();

								if(isset($result->source->card_token))
								{
									$order->update_meta_data('bambora_payform_embedded_card_token', $result->source->card_token);

									$pbw_extra_info .= __('Card token: ', 'bambora-payform-embedded-card-payment-gateway') . ' ' . $result->source->card_token . "<br>";
									$pbw_extra_info .= __('Expiration: ', 'bambora-payform-embedded-card-payment-gateway') . ' ' . $result->source->exp_month . '/' . $result->source->exp_year;

									if(function_exists('wcs_get_subscriptions_for_order')) 
									{
										$subscriptions = wcs_get_subscriptions_for_order($order_id, array( 'order_type' => 'any'));

										foreach ($subscriptions as $subscription)
										{
											$card_token = get_post_meta($subscription->get_id(), 'bambora_payform_embedded_card_token', true);

											if(!empty($card_token))
												update_post_meta($subscription->get_id(), 'bambora_payform_embedded_card_token_old', $card_token);

											update_post_meta( $subscription->get_id(), 'bambora_payform_embedded_card_token', $result->source->card_token );
											$subscription->add_order_note($pbw_note . $pbw_extra_info);
										}
									}
								}

								$order->add_order_note($pbw_note . $pbw_extra_info);
								$order->payment_complete();
								WC()->cart->empty_cart();
								break;

							case 1:
								$pbw_note = __('Payment was not accepted.', 'bambora-payform-embedded-card-payment-gateway') . $pbw_extra_info;
								if($wc_order_status == 'failed')
									$order->add_order_note($pbw_note);
								else
									$order->update_status('failed', $pbw_note);
								break;

							case 4:
								$note = __('Transaction status could not be updated after customer returned from the web page of a bank. Please use the merchant UI to resolve the payment status.', 'bambora-payform-embedded-card-payment-gateway');
								if($wc_order_status == 'failed')
									$order->add_order_note($note);
								else
									$order->update_status('failed', $note);
								break;

							case 10:
								$note = __('Maintenance break. The transaction is not created and the user has been notified and transferred back to the cancel address.', 'bambora-payform-embedded-card-payment-gateway');
								if($wc_order_status == 'failed')
									$order->add_order_note($note);
								else
									$order->update_status('failed', $note);
								break;
						}

						$order->update_meta_data('bambora_payform_embedded_card_return_code', $return_code);
						$order->save();
					}
				}
				else
					$this->bambora_payform_embedded_die("MAC check failed");

				$cancel_url_option = $this->get_option('cancel_url', '');
				$card = ($result->source->object === 'card') ? true : false;
				$redirect_url = $this->bambora_payform_embedded_card_url($return_code, $order, $cancel_url_option, $card);
				wp_redirect($redirect_url);
				exit('Ok');
			}
		}

		public function bambora_payform_embedded_card_url($return_code, $order, $cancel_url_option = '', $card = false)
		{
			if($return_code == 0)
				$redirect_url = $this->get_return_url($order);
			else
			{
				if($card)
					$error_msg = __('Card payment failed. Your card has not been charged.', 'bambora-payform-embedded-card-payment-gateway');
				else
					$error_msg = __('Payment was canceled or charge was not accepted.', 'bambora-payform-embedded-card-payment-gateway');
				switch ($cancel_url_option)
				{
					case 'order_pay':
						do_action( 'woocommerce_set_cart_cookies',  true );
						$redirect_url = $order->get_checkout_payment_url();
						break;
					case 'order_new_cart':
						$redirect_url = wc_get_cart_url();
						break;
					case 'order_new_checkout':
						$redirect_url = wc_get_checkout_url();
						break;
					default:
						do_action( 'woocommerce_set_cart_cookies',  true );
						$redirect_url = $this->get_return_url($order);
						break;
				}
				wc_add_notice($error_msg, 'error');
			}
			
			return $redirect_url;
		}

		protected function bambora_payform_embedded_card_translate_error_code($pbw_error_code)
		{
			switch ($pbw_error_code)
			{
				case '04':
					return ' 04 - ' . __('The card is reported lost or stolen.', 'bambora-payform-embedded-card-payment-gateway');
				case '05':
					return ' 05 - ' . __('General decline. The card holder should contact the issuer to find out why the payment failed.', 'bambora-payform-embedded-card-payment-gateway');
				case '51':
					return ' 51 - ' . __('Insufficient funds. The card holder should verify that there is balance on the account and the online payments are actived.', 'bambora-payform-embedded-card-payment-gateway');
				case '54':
					return ' 54 - ' . __('Expired card.', 'bambora-payform-embedded-card-payment-gateway');
				case '61':
					return ' 61 - ' . __('Withdrawal amount limit exceeded.', 'bambora-payform-embedded-card-payment-gateway');
				case '62':
					return ' 62 - ' . __('Restricted card. The card holder should verify that the online payments are actived.', 'bambora-payform-embedded-card-payment-gateway');
				case '1000':
					return ' 1000 - ' . __('Timeout communicating with the acquirer. The payment should be tried again later.', 'bambora-payform-embedded-card-payment-gateway');
				default:
					return null;
			}
		}

		protected function bambora_payform_embedded_card_translate_verified_code($pbw_verified_code)
		{
			switch ($pbw_verified_code)
			{
				case 'Y':
					return ' Y - ' . __('3-D Secure was used.', 'bambora-payform-embedded-card-payment-gateway');
				case 'N':
					return ' N - ' . __('3-D Secure was not used.', 'bambora-payform-embedded-card-payment-gateway');
				case 'A':
					return ' A - ' . __('3-D Secure was attempted but not supported by the card issuer or the card holder is not participating.', 'bambora-payform-embedded-card-payment-gateway');
				default:
					return null;
			}
		}

		public function bambora_payform_embedded_card_settle_payment($order)
		{
			$wc_order_id = $order->get_id();			

			$settle_field = get_post_meta($wc_order_id, 'bambora_payform_embedded_card_is_settled', true);
			$settle_check = $settle_field === '0';

			if(!$settle_check)
				return;

			$url = admin_url('post.php?post=' . absint( $wc_order_id ) . '&action=edit');

			if(isset($_GET['bambora_payform_embedded_card_settle']))
			{
				$order_number = get_post_meta( $wc_order_id, 'bambora_payform_embedded_card_order_number', true );
				$settlement_msg = '';

				if($this->bambora_payform_embedded_card_process_settlement($order_number, $settlement_msg))
				{
					$order->add_order_note(__('Payment settled.', 'bambora-payform-embedded-card-payment-gateway'));
					$order->update_meta_data('bambora_payform_embedded_card_is_settled', 1);
					$order->save();
					$settlement_result = '1';
				}
				else
					$settlement_result = '0';

				if(!$settlement_result)
					echo '<div id="message" class="error">'.$settlement_msg.' <p class="form-field"><a href="'.$url.'" class="button button-primary">OK</a></p></div>';
				else
				{
					echo '<div id="message" class="updated fade">'.$settlement_msg.' <p class="form-field"><a href="'.$url.'" class="button button-primary">OK</a></p></div>';
					return;
				}
			}


			$text = __('Settle payment', 'bambora-payform-embedded-card-payment-gateway');
			$url .= '&bambora_payform_embedded_card_settle';
			$html = "
				<p class='form-field'>
					<a href='$url' class='button button-primary'>$text</a>
				</p>";

			echo $html;
		}

		public function bambora_payform_embedded_card_process_settlement($order_number, &$settlement_msg)
		{
			$successful = false;
			require_once(plugin_dir_path( __FILE__ ).'includes/lib/bambora_payform_loader.php');
			$payment = new Bambora\PayForm($this->api_key, $this->private_key, 'w3.1', new Bambora\PayFormWPConnector());
			try
			{
				$settlement = $payment->settlePayment($order_number);
				$return_code = $settlement->result;

				switch ($return_code)
				{
					case 0:
						$successful = true;
						$settlement_msg = __('Settlement was successful.', 'bambora-payform-embedded-card-payment-gateway');
						break;
					case 1:
						$settlement_msg = __('Settlement failed. Validation failed.', 'bambora-payform-embedded-card-payment-gateway');
						break;
					case 2:
						$settlement_msg = __('Settlement failed. Either the payment has already been settled or the payment gateway refused to settle payment for given transaction.', 'bambora-payform-embedded-card-payment-gateway');
						break;
					default:
						$settlement_msg = __('Settlement failed. Unkown error.', 'bambora-payform-embedded-card-payment-gateway');
						break;
				}
			}
			catch (Bambora\PayFormException $e) 
			{
				$message = $e->getMessage();
				$settlement_msg = __('Exception, error: ', 'bambora-payform-embedded-card-payment-gateway') . $message;
			}
			return $successful;
		}

		public function bambora_payform_embedded_die($msg = '')
		{
			$logger = new WC_Logger();
			$logger->add( 'bambora-payform-embedded-card-payment-gateway', 'Bambora PayForm Embedded - return failed. Error: ' . $msg);
			status_header(400);
			nocache_headers();
			die($msg);
		}

		public function scheduled_subscription_payment( $amount_to_charge, $order)
		{
			require_once(plugin_dir_path( __FILE__ ).'includes/lib/bambora_payform_loader.php');
			$subscriptions = wcs_get_subscriptions_for_renewal_order($order);
			$subscription = end($subscriptions);

			$card_token = get_post_meta(  $subscription->get_id(), 'bambora_payform_embedded_card_token', true );

			$payment = new Bambora\PayForm($this->api_key, $this->private_key, 'w3.1', new Bambora\PayFormWPConnector());

			$order_number = (strlen($this->ordernumber_prefix)  > 0) ?  $this->ordernumber_prefix . '_' . $order->get_id() : $order->get_id();
			$order_number .=  '-' . str_pad(time().rand(0,9999), 5, "1", STR_PAD_RIGHT);

			$amount =  (int)(round($amount_to_charge*100, 0));

			$payment->addCharge(
				array(
					'order_number' => $order_number,
					'amount' => $amount,
					'currency' => get_woocommerce_currency(),
					'card_token' => $card_token,
					'email' => $this->send_receipt === 'yes' ? $order->get_billing_email() : ''
				)
			);

			$payment->addInitiator(array(
				'type' => 1
			));

			$payment = $this->add_customer_to_payment($payment, $order);

			if($this->send_items == 'yes')
			{
				$payment = $this->add_products_to_payment($payment, $order,  $amount);
			}

			$note = '';

			try
			{
				$result = $payment->chargeWithCardToken();

				$order->update_meta_data('bambora_payform_embedded_card_return_code', $result->resul);
				$order->save();

				switch ($result->result) {
					case 0:
						if($result->settled == 0)
							$note = __('Bambora PayForm (Embedded Card, Subscription) order', 'bambora-payform-embedded-card-payment-gateway') . ' ' . $order_number  . "<br>-<br>" . __('Payment is authorized. Use settle option to capture funds.', 'bambora-payform-embedded-card-payment-gateway') . "<br>";
						else
							$note = __('Bambora PayForm (Embedded Card, Subscription) order', 'bambora-payform-embedded-card-payment-gateway') . ' ' . $order_number  . "<br>-<br>" . __('Payment accepted.', 'bambora-payform-embedded-card-payment-gateway') . "<br>";

						$order->update_meta_data('bambora_payform_embedded_card_order_number', $order_number);
						$order->update_meta_data('bambora_payform_embedded_card_is_settled', $result->settled);
						$order->add_order_note($note);
						$order->payment_complete();
						break;
					case 2:
						$note = __('Duplicate order number.', 'bambora-payform-embedded-card-payment-gateway');
						$order->update_status('failed', $note);
						break;
					case 3:
						$note = __('Card token not found.', 'bambora-payform-embedded-card-payment-gateway');
						$order->update_status('failed', $note);
						break;
					default:
						if(isset($result->errors))
						{
							$errors = '';
							if(isset($result->errors))
							{
								foreach ($result->errors as $error) 
								{
									$errors .= ' ' . $error;
								}
							}

							$this->logger->add( 'bambora-payform-embedded-card-payment-gateway', 'Bambora PayForm (Embedded Card, Subscription)::chargeWithCardToken failed, response: ' . $result->result . ' - Errors:'. $errors);
						}


						$pbw_error = '';
						if(isset($result->source->error_code))
						{
							$pbw_error = $this->bambora_payform_embedded_card_translate_error_code($result->source->error_code);
						}
						
						$note = !empty($pbw_error) ? __('Payment failed. The card was not charged. Error: ', 'bambora-payform-embedded-card-payment-gateway') . $pbw_error : __('Payment failed. The card was not charged.', 'bambora-payform-embedded-card-payment-gateway');

						$order->update_status('failed', $note);
						break;
				}

			}
			catch(Bambora\PayFormException $e)
			{
				$note = __('Payment failed. Exception: ', 'bambora-payform-embedded-card-payment-gateway') . $e->getMessage();
				$order->update_status('failed', $note);
			}

			if(!empty($note))
			{
				$subscription->add_order_note($note);
			}
		}

		public function subscription_cancellation($subscription)
		{
			require_once(plugin_dir_path( __FILE__ ).'includes/lib/bambora_payform_loader.php');
			if($subscription->get_status() === 'cancelled')
			{
				$key = 'bambora_payform_embedded_card_token';
				$success_note = 'The card token %s was successfully deleted';
			}
			else
			{
				$key = 'bambora_payform_embedded_card_token_old';
				$success_note = 'The old card token %s was successfully deleted';
			}

			$card_token = get_post_meta($subscription->get_id(), $key, true);

			$payment = new Bambora\PayForm($this->api_key, $this->private_key, 'w3.1', new Bambora\PayFormWPConnector());

			try
			{
				$result = $payment->deleteCardToken($card_token);

				if($result->result == 0)
				{
					$subscription->add_order_note(sprintf(__($success_note, 'bambora-payform-embedded-card-payment-gateway'), $card_token));
				}
				else
				{
					$subscription->add_order_note(sprintf(__('Failed to delete the card token %s. Return code: %s', 'bambora-payform-embedded-card-payment-gateway'), $card_token, $result->result));
				}
			}
			catch(Bambora\PayFormException $e)
			{
				$subscription->add_order_note(sprintf(__('Failed to delete the card token %s. Exception: %s', 'bambora-payform-embedded-card-payment-gateway'), $card_token, $e->getMessage()));
			}
		}

		protected function add_products_to_payment($payment, $order, $amount)
		{
			$products = array();
			$total_amount = 0;
			$order_items = $order->get_items();
			$wc_order_shipping = $order->get_shipping_total();
			$wc_order_shipping_tax = $order->get_shipping_tax();

			foreach($order_items as $item)
			{
				$tax_rates = WC_Tax::get_rates($item->get_tax_class());
				if(!empty($tax_rates))
				{
					$tax_rate = reset($tax_rates);
					$line_tax = (int)round($tax_rate['rate']);
				}
				else
				{
					$line_tax = ($order->get_item_total($item, false, false) > 0) ? round($order->get_item_tax($item, false)/$order->get_item_total($item, false, false)*100,0) : 0;
				}
				
				$product = array(
					'title' => $item['name'],
					'id' => $item['product_id'],
					'count' => $item['qty'],
					'pretax_price' => (int)(round($order->get_item_total($item, false, false)*100, 0)),
					'price' => (int)(round($order->get_item_total($item, true, false)*100, 0)),
					'tax' => $line_tax,
					'type' => 1
				);
				$total_amount += $product['price'] * $product['count'];
				array_push($products, $product);
			}

			$shipping_items = $order->get_items('shipping');
			foreach($shipping_items as $s_method)
			{
				$shipping_method_id = $s_method['method_id'] ;
			}

			if($wc_order_shipping > 0){
				$product = array(
					'title' => $order->get_shipping_method(),
					'id' => $shipping_method_id,
					'count' => 1,
					'pretax_price' => (int)(round($wc_order_shipping*100, 0)),
					'price' => (int)(round(($wc_order_shipping_tax+$wc_order_shipping)*100, 0)),
					'tax' => round(($wc_order_shipping_tax/$wc_order_shipping)*100,0),
					'type' => 2
				);
				$total_amount += $product['price'] * $product['count'];
				array_push($products, $product);				
			}

			if(abs($total_amount - $amount) < 3)
			{
				foreach($products as $product)
				{
					$payment->addProduct(
						array(
							'id' => htmlspecialchars($product['id']),
							'title' => htmlspecialchars($product['title']),
							'count' => $product['count'],
							'pretax_price' => $product['pretax_price'],
							'tax' => $product['tax'],
							'price' => $product['price'],
							'type' => $product['type']
						)
					);
				}
			}

			return $payment;
		}

		protected function add_customer_to_payment($payment, $order)
		{
			$wc_b_first_name = $order->get_billing_first_name();
			$wc_b_last_name = $order->get_billing_last_name();
			$wc_b_email = $order->get_billing_email();
			$wc_b_address_1 = $order->get_billing_address_1();
			$wc_b_address_2 = $order->get_billing_address_2();
			$wc_b_city = $order->get_billing_city();
			$wc_b_postcode = $order->get_billing_postcode();
			$wc_b_country = $order->get_billing_country();
			$wc_s_first_name = $order->get_shipping_first_name();
			$wc_s_last_name = $order->get_shipping_last_name();
			$wc_s_address_1 = $order->get_shipping_address_1();
			$wc_s_address_2 = $order->get_shipping_address_2();
			$wc_s_city = $order->get_shipping_city();
			$wc_s_postcode = $order->get_shipping_postcode();
			$wc_s_country = $order->get_shipping_country();

			$payment->addCustomer(
				array(
					'firstname' => htmlspecialchars($wc_b_first_name), 
					'lastname' => htmlspecialchars($wc_b_last_name), 
					'email' => htmlspecialchars($wc_b_email), 
					'address_street' => htmlspecialchars($wc_b_address_1.' '.$wc_b_address_2),
					'address_city' => htmlspecialchars($wc_b_city),
					'address_zip' => htmlspecialchars($wc_b_postcode),
					'address_country' => htmlspecialchars($wc_b_country),
					'shipping_firstname' => htmlspecialchars($wc_s_first_name),
					'shipping_lastname' => htmlspecialchars($wc_s_last_name),
					'shipping_address_street' => trim(htmlspecialchars($wc_s_address_1.' '.$wc_s_address_2)),
					'shipping_address_city' => htmlspecialchars($wc_s_city),
					'shipping_address_zip' => htmlspecialchars($wc_s_postcode),
					'shipping_address_country' => htmlspecialchars($wc_s_country)
				)
			);

			return $payment;
		}

		public static function remove_change_payment_method_button($actions, $subscription) {

			$card_token = get_post_meta($subscription->get_id(), 'bambora_payform_embedded_card_token', true);

			if(!empty($card_token))
			{
				foreach ($actions as $action_key => $action)
				{
					switch ($action_key) 
					{
						case 'change_payment_method':
							unset($actions[ $action_key ]);
							break;
					}
				}
			}

			return $actions;
		}
	}
}

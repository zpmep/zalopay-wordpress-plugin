<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * WC_Gateway_ZaloPay class.
 *
 * @extends WC_Payment_Gateway
 */
class WC_Gateway_ZaloPay extends WC_Payment_Gateway
{
	public $appID;
	public $key1;
	// ZaloPay key2
	public $key2;
	public $sandboxMode;
	/**
	 * Constructor
	 */

	public function __construct()
	{
		$this->id = 'zlp'; // payment gateway plugin ID
		$this->icon = plugins_url('assets/images/logozlp1.png', dirname(__FILE__)); // URL of the icon that will be displayed on checkout page near your gateway name
		$this->has_fields = false; // in case you need a custom credit card form
		$this->method_description = __('ZaloPay Merchant Intergration Plugin.', 'woocommerce-gateway-zalopay'); // will be displayed on the options page

		// gateways can support subscriptions, refunds, saved payment methods,
		// but in this tutorial we begin with simple payments
		$this->supports           = array(
			'products',
			'refunds',
		);

		// Method with all the options fields
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();
		$this->title = __('ZaloPay', 'woocommerce-gateway-zalopay');
		$this->description = __('You will be redirected to ZaloPay.', 'woocommerce-gateway-zalopay');
		$this->appID = $this->appID ? $this->appID : $this->get_option('appID');
		$this->key1 = $this->key1 ? $this->key1 : $this->get_option('key1');
		$this->key2 = $this->key2 ? $this->key2 : $this->get_option('key2');
		$this->sandboxMode = $this->sandboxMode ? $this->sandboxMode : $this->get_option('sandboxMode');
		// $this->publishable_key = $this->testmode ? $this->get_option('test_publishable_key') : $this->get_option('publishable_key');
		// This action hook saves the settings
		// add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

		// We need custom JavaScript to obtain a token
		add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));

		// You can also register a webhook here
		add_action('woocommerce_api_zlp-callback', array($this, 'webhook'));

		// add_action('zlp_query_status', array($this, 'zlp_queryOrderStatus'));
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 */
	public function init_form_fields()
	{
		$this->form_fields = require(dirname(__FILE__) . '/admin/zlp-settings.php');
	}

	/**
	 * Payment_scripts function.
	 *
	 * Outputs scripts used for stripe payment
	 *
	 * @since 3.1.0
	 * @version 4.0.0
	 */
	public function payment_scripts()
	{
		if (
			!is_product()
			&& !is_cart()
			&& !is_checkout()
			&& !isset($_GET['pay_for_order']) // wpcs: csrf ok.
			&& !is_add_payment_method_page()
			&& !isset($_GET['change_payment_method']) // wpcs: csrf ok.
			|| (is_order_received_page())
		) {
			return;
		}

		// If ZaloPay is not enabled bail.
		if ('no' === $this->enabled) {
			return;
		}

		// If no SSL bail.
		if (!$this->sandboxMode && !is_ssl()) {
			WC_ZaloPay_Logger::log('ZaloPay live mode requires SSL.');
			return;
		}
	}

	// Handle Callback
	public function webhook()
	{
		if ('POST' != $_SERVER['REQUEST_METHOD']) {
			WC_ZaloPay_Logger::log('ZaloPay callback require POST method');
			header("HTTP/1.1 401 Unauthorized");
			wp_die( 'Forbidden' );
		}
		$data = json_decode(file_get_contents('php://input'), true);
		if ($data == NULL) {
			WC_ZaloPay_Logger::log('Callback failed, no data found');
			wp_send_json([
				'return_code' => RETURNCODE_ERROR,
				'return_message' => 'No data found'
			], 500);
		}
		$calHash = hash_hmac('sha256', $data['data'], $this->key2);
		if (strcmp($calHash, $data['mac'] != 0)) {
			WC_ZaloPay_Logger::log('Callback failed, mac not equal, data: '. print_r($data, true));
			wp_send_json([
				'return_code' => RETURNCODE_ERROR,
				'return_message' => 'Mac not equal'
			], 500);
		}
		$decodedData = json_decode($data['data']);
		if (!is_object($decodedData) || $decodedData->embed_data == NULL) {
			WC_ZaloPay_Logger::log('Cannot parsed embed data '. print_r($data, true));
			wp_send_json([
				'return_code' => RETURNCODE_ERROR,
				'return_message' => 'Failed'
			], 500);
		}
		$embedData = json_decode($decodedData->embed_data);
		$orderID = $embedData->orderID;
		$order = wc_get_order($orderID);
		$order->payment_complete();
		$order_stock_reduced = $order->get_meta('_order_stock_reduced', true);
		if (!$order_stock_reduced) {
			wc_reduce_stock_levels($orderID);
		}
		update_post_meta($orderID, 'zlp_callback_received', true);
		update_post_meta($orderID, 'zp_trans_id', $data->zp_trans_id);
		update_option('webhook_debug', $data);
		wp_send_json([
			'return_code' => RETURNCODE_SUCCESS,
			'return_message' => 'success'
		], 200);
	}


	/*
	* Create order
	*/
	public function process_payment($orderID)
	{
		$userName = wp_get_current_user()->user_login;
		$order = wc_get_order($orderID);
		$items = $order->get_items();
		$args = [
			'user' => $userName != "" ? $userName : 'guest',
			'orderID' => $orderID,
			'total' => $order->get_total(),
			'description' => 'Description for order - '.$orderID,
		];
		$itemArr = [];
		if (!is_wp_error($items)) {
			foreach ($items as $item) {
				array_push($itemArr, [$item->get_name() => ['quantity' => $item->get_quantity(), 'price' => $item->get_total()]]);
			}
		}

		$embeddata = [
			"redirecturl" => add_query_arg(['order-received' => $orderID, 'key' => $order->get_order_key(), 'page_id' => wc_get_page_id('checkout')], get_home_url()),
			"orderID" => $orderID
		];
		$args["item"] = json_encode($itemArr, JSON_UNESCAPED_UNICODE);
		$args["embed_data"] = json_encode($embeddata, JSON_UNESCAPED_UNICODE);

		$response = WC_ZaloPay_API::request($args);
		if (is_wp_error($response)) {
			wc_add_notice('Connection Error!', 'error');
			return array(
				'result'   => 'fail',
				'redirect' => '',
			);
		}
		if (RETURNCODE_SUCCESS != $response->return_code) {
			WC_ZaloPay_Logger::log(
				'Error Response: ' . print_r($response, true)
			);
			wc_add_notice('Create Order Failed. Please try again later!', 'error');
			return array(
				'result'   => 'fail',
				'redirect' => '',
			);
		}
		if (!wp_next_scheduled(ZLP_QUERY_QUEUE, [$orderID])) {
			wp_schedule_event(time(), 'every_minute', ZLP_QUERY_QUEUE, [$orderID]);
		}
		return array(
			'result' => 'success',
			'redirect' => esc_url_raw($response->order_url)
		);
	}

		/**
	 * Refund a charge.
	 *
	 * @since 3.1.0
	 * @version 4.0.0
	 * @param  int $order_id
	 * @param  float $amount
	 * @return bool
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || $amount < 1000 ) {
			return false;
		}

		$request = array();
		$order_currency = $order->get_currency();
	
		// if ( $order_currency != ZLP_CURRENCY ) {
		// 	return false;
		// }

		if ( !is_null( $amount ) ) {
			$request['amount'] = $amount;
		}
		
		$request['order_id'] = $order_id;
		$refundReason = $reason != "" ? " Reason: ".$reason : "";
		$request['description'] = "Refund for order ${order_id}." . $refundReason;
		WC_ZaloPay_Logger::log( "Info: Beginning refund for order {$order_id} for the amount of {$amount}" );

		$request = apply_filters( 'wc_zlp_refund_request', $request, $order );
		$response = WC_ZaloPay_API::request($request, ZLP_REFUND_API);

		if ( 1 > $response->return_code || is_wp_error($response) ) {
			WC_ZaloPay_Logger::log( "Refund Error for order {$order_id}. Reason: {$response->return_message} ");
			return false;
		} elseif ($response->return_code == 1 || $response->return_code == 3) {
			$refund_message = sprintf( __( 'Refunded %1$s%2$s - Reason: %3$s', 'woocommerce-gateway-zalopay' ), $amount, $order->get_currency(), $reason );

			$order->add_order_note( $refund_message );
			WC_ZaloPay_Logger::log( "Refund order {$order_id} success");
			return true;
		}
	}

	public function displayAdminSettingsWebhookDescription() {
		return sprintf( __( 'You must add the following URL <a href="%s" target="_blank">%s</a> to your <a href="%s" target="_blank">ZaloPay callback URL</a>. This will enable you to receive notifications on the charge statuses.', 'woocommerce-gateway-zalopay' ),self::getCallbackURL(), self::getCallbackURL(), self::getZaloPayCallBackSettingPage() );
	}

	public function displayAdminSettingsRedirectURLDescription() {
		return sprintf( __( 'Default Redirect URL <a href="%s" target="_blank">%s</a>.', 'woocommerce-gateway-zalopay' ),self::getRedirectURL(), self::getRedirectURL());
	}


	public static function getCallbackURL() {
		return add_query_arg( 'wc-api', 'zlp-callback', trailingslashit(get_home_url())  );
	}

	public static function getRedirectURL() {
		return wc_get_checkout_url();
	}

	public static function getZaloPayCallBackSettingPage() {
		$options = get_option('woocommerce_zlp_settings');
		if (isset($options['sandboxMode'])) {
			return 'https://sbmc.zalopay.vn/apps';
		}
		return 'https://mc.zalopay.vn/apps';
	}

	/**
	 * Store extra meta data for an order from a Stripe Response.
	 */
	public function process_response( $response, $order ) {
		WC_ZaloPay_Logger::log( 'Processing response: ' . print_r( $response, true ) );

		$orderID = $order->get_id();
		// var_dump("process-response");die;
		//! ZaloPay handle
		$order_stock_reduced = $order->get_meta( '_order_stock_reduced', true );

		if ( ! $order_stock_reduced ) {
			wc_reduce_stock_levels( $orderID );
		}
		$order->set_transaction_id( $response->zp_trans_id );
		$order->payment_complete( $orderID );
		$timestamp = wp_next_scheduled( ZLP_QUERY_QUEUE, [$orderID] );
		wp_unschedule_event( $timestamp, ZLP_QUERY_QUEUE, [$orderID]);
		//! End
		$captured = ( isset( $response->captured ) && $response->captured ) ? 'yes' : 'no';

		if ( 'yes' === $captured ) {
			// /**
			//  * Charge can be captured but in a pending state. Payment methods
			//  * that are asynchronous may take couple days to clear. Webhook will
			//  * take care of the status changes.
			//  */
			// if ( 'pending' === $response->status ) {
			// 	$order_stock_reduced = $order->get_meta( '_order_stock_reduced', true );

			// 	if ( ! $order_stock_reduced ) {
			// 		wc_reduce_stock_levels( $order_id );
			// 	}

			// 	$order->set_transaction_id( $response->id );
			// 	/* translators: transaction id */
			// 	$order->update_status( 'on-hold', sprintf( __( 'Stripe charge awaiting payment: %s.', 'woocommerce-gateway-stripe' ), $response->id ) );
			// }

			// if ( 'succeeded' === $response->status ) {
			// 	$order->payment_complete( $response->id );

			// 	/* translators: transaction id */
			// 	$message = sprintf( __( 'Stripe charge complete (Charge ID: %s)', 'woocommerce-gateway-stripe' ), $response->id );
			// 	$order->add_order_note( $message );
			// }

			// if ( 'failed' === $response->status ) {
			// 	$localized_message = __( 'Payment processing failed. Please retry.', 'woocommerce-gateway-stripe' );
			// 	$order->add_order_note( $localized_message );
			// 	throw new WC_ZaloPay_Exception( print_r( $response, true ), $localized_message );
			// }
		} else {
			// $order->set_transaction_id( $response->id );

			// if ( $order->has_status( array( 'pending', 'failed' ) ) ) {
			// 	wc_reduce_stock_levels( $order_id );
			// }

			// /* translators: transaction id */
			// $order->update_status( 'on-hold', sprintf( __( 'Stripe charge authorized (Charge ID: %s). Process order to take payment, or cancel to remove the pre-authorization.', 'woocommerce-gateway-stripe' ), $response->id ) );
		}


		if ( is_callable( array( $order, 'save' ) ) ) {
			$order->save();
		}

		do_action( 'wc_gateway_zlp_process_response', $response, $order );

		return $response;
	}
}
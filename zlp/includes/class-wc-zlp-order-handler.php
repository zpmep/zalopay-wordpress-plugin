<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles and process orders from asyncronous flows.
 *
 * @since 4.0.0
 */
class WC_ZaloPay_Order_Handler {
	private static $_this;
	public $retry_interval;

	/**
	 * Constructor.
	 *
	 * @since 4.0.0
	 * @version 4.0.0
	 */
	public function __construct() {
		self::$_this = $this;

		$this->retry_interval = 1;

		add_action( 'wp', array( $this, 'maybe_process_redirect_order' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'cancel_payment' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'cancel_payment' ) );
	}

	/**
	 * Public access to instance object.
	 *
	 * @since 4.0.0
	 * @version 4.0.0
	 */
	public static function get_instance() {
		return self::$_this;
	}

	/**
	 * Processes payments.
	 *
	 * @since 4.0.0
	 * @since 4.1.8 Add $previous_error parameter.
	 * @param int $orderID
	 * @param bool $retry
	 * @param mix $previous_error Any error message from previous request.
	 */
	public function process_zalopay_redirect( $orderID) {
		
		try {
			if ( empty( $orderID ) ) {
				return;
			}

			$order = wc_get_order( $orderID );

			if ( ! is_object( $order ) ) {
				return;
			}

			if ( $order->has_status( array( 'processing', 'completed', 'on-hold' ) ) ) {
				return;
			}
			$appTransID = get_post_meta($orderID,'zlp_app_trans_id', true);	

			$order_info = WC_ZaloPay_API::request(['app_trans_id' => $appTransID], ZLP_QUERY_ORDER_STATUS_API);
			if (is_wp_error($order_info)) {
				WC_ZaloPay_Logger::log("Handle redirect failed \"wp_error\" for order {$orderID} response:".  json_encode($order_info, JSON_UNESCAPED_UNICODE));
				throw new WC_ZaloPay_Exception('Unexpected error has occured. Please try again later.', __('Unexpected error has occured. Please try again later.', 'woocommerce-gateway-zalopay'));
			}
			if (RETURNCODE_SUCCESS != $order_info->return_code) {
				WC_ZaloPay_Logger::log("Handle redirect failed for order {$orderID} response:".  json_encode($order_info, JSON_UNESCAPED_UNICODE) );
				throw new WC_ZaloPay_Exception('Payment Failed.',  __( 'Payment failed for order #' . $order->get_id(), 'woocommerce-gateway-zalopay' ) );
			}

			WC_ZaloPay_Logger::log( "Info: (Redirect) Begin processing payment for order $orderID for the amount of {$order->get_total()}" );

			do_action( 'wc_gateway_zlp_process_redirect_payment', $order_info, $order );

			self::process_response( $order_info, $order );

		} catch ( WC_ZaloPay_Exception $e ) {
			WC_ZaloPay_Logger::log( 'Error: ' . $e->getMessage() );

			do_action( 'wc_gateway_zlp_process_redirect_payment_error', $e, $order );

			/* translators: error message */
			$order->update_status( 'failed', sprintf( __( 'ZaloPay payment failed: %s', 'woocommerce-gateway-zalopay' ), $e->getLocalizedMessage() ) );

			wc_add_notice( $e->getLocalizedMessage(), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}
	}

	/**
	 * Processses the orders that are redirected.
	 *
	 * @since 4.0.0
	 * @version 4.0.0
	 */
	public function maybe_process_redirect_order() {
		if ( ! is_order_received_page() || empty( $_GET['appid'] ) || empty( $_GET['checksum'] ) || empty( $_GET['apptransid'] ) || empty( $_GET['status'] ) ) {
			return;
		}
		$key = wc_clean($_GET['key']);

		$order_id = wc_get_order_id_by_order_key($key);

		$this->process_zalopay_redirect( $order_id );
	}


	/**
	 * Cancel pre-auth on refund/cancellation.
	 *
	 * @since 3.1.0
	 * @version 4.2.2
	 * @param  int $order_id
	 */
	public function cancel_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( 'zlp' === $order->get_payment_method() ) {
			self::process_admin_refund($order);

			// This hook fires when admin manually changes order status to cancel.
			do_action( 'woocommerce_zlp_process_manual_cancel', $order );
		}
	}

	public static function process_admin_refund( $order) {
		if ( ! $order->has_status( array( 'processing', 'on-hold' ) ) ) {
			wc_reduce_stock_levels( $order->get_id() );
		}
		$order_refunds = $order->get_refunds();
		$order_total = $order->get_total();
		foreach( $order_refunds as $refund ){
			// Loop through the order refund line items
			foreach( $refund->get_items() as $item ){
				$refunded_line_subtotal = $item->get_subtotal(); // line subtotal: zero or negative number
				if (0 > $refunded_line_subtotal) {
					$order_total = $order_total - abs($refunded_line_subtotal);
				}
			}
		}
		if ( ! $order || $order_total < 1000 ) {
			return false;
		}
		$request = array();
		$order_currency = $order->get_currency();
		if ( $order_currency != ZLP_CURRENCY ) {
			return false;
		}
		
		$order_id = $order->get_id();
		$request['order_id'] = $order_id;
		$request['amount'] = $order_total;
		$request['description'] = "Admin refund for order {$order_id} amount: {$order_total}";
		WC_ZaloPay_Logger::log( "Info: Beginning Admin refund for order {$order_id} for the amount of {$order_total}" );

		$request = apply_filters( 'wc_zlp_refund_request', $request, $order );
		$response = WC_ZaloPay_API::request($request, ZLP_REFUND_API);

		if ( RETURNCODE_SUCCESS > $response->return_code || is_wp_error($response) ) {
			WC_ZaloPay_Logger::log( "Refund Error for order {$order_id}. Reason: {$response->return_message} ");
			return false;
		} else{
			$refund_message = sprintf( __( 'Refunded %1$s%2$s - Reason: %3$s', 'woocommerce-gateway-zalopay' ), $order_total, $order->get_currency(), $reason );

			$order->add_order_note( $refund_message );
			WC_ZaloPay_Logger::log( "Refund order {$order_id} success");
			return true;
		}
	}

	/**
	 * Store extra meta data for an order from a Stripe Response.
	 */
	public static function process_response( $response, $order ) {
		WC_ZaloPay_Logger::log( 'Processing response: ' . print_r( $response, true ) );

		$orderID = $order->get_id();
		$order_stock_reduced = $order->get_meta( '_order_stock_reduced', true );

		if ( ! $order_stock_reduced ) {
			wc_reduce_stock_levels( $orderID );
		}
		$order->set_transaction_id( $response->zp_trans_id );
		$order->payment_complete( $orderID );
		$order->update_meta_data('zp_trans_id', $response->zp_trans_id);
		$timestamp = wp_next_scheduled( ZLP_QUERY_QUEUE, [$orderID] );
		wp_unschedule_event( $timestamp, ZLP_QUERY_QUEUE, [$orderID]);
		if ( is_callable( array( $order, 'save' ) ) ) {
			$order->save();
		}

		do_action( 'wc_gateway_zlp_process_response', $response, $order );

		return $response;
	}

}

new WC_ZaloPay_Order_Handler();

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
		// add_action( 'woocommerce_order_status_processing', array( $this, 'capture_payment' ) );
		// add_action( 'woocommerce_order_status_completed', array( $this, 'capture_payment' ) );
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
	 * Note at this time the original source has already been
	 * saved to a customer card (if applicable) from process_payment.
	 *
	 * @since 4.0.0
	 * @since 4.1.8 Add $previous_error parameter.
	 * @param int $orderID
	 * @param bool $retry
	 * @param mix $previous_error Any error message from previous request.
	 */
	public function process_zalopay_redirect( $orderID) {
		
		try {
			// var_dump("cc");die;
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
			if (1 != $order_info->return_code) {
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
	 * Capture payment when the order is changed from on-hold to complete or processing.
	 *
	 * @since 3.1.0
	 * @version 4.0.0
	 * @param  int $order_id
	 */
	public function capture_payment( $order_id ) {
		// var_dump("Capture payment");die;
		$order = wc_get_order( $order_id );

		if ( 'stripe' === $order->get_payment_method() ) {
			$charge             = $order->get_transaction_id();
			$captured           = $order->get_meta( '_stripe_charge_captured', true );
			$is_stripe_captured = false;

			if ( $charge && 'no' === $captured ) {
				// $order_total = $order->get_total();

				// if ( 0 < $order->get_total_refunded() ) {
				// 	$order_total = $order_total - $order->get_total_refunded();
				// }

				// $intent = $this->get_intent_from_order( $order );
				// if ( $intent ) {
				// 	// If the order has a Payment Intent, then the Intent itself must be captured, not the Charge
				// 	if ( ! empty( $intent->error ) ) {
				// 		/* translators: error message */
				// 		$order->add_order_note( sprintf( __( 'Unable to capture charge! %s', 'woocommerce-gateway-stripe' ), $intent->error->message ) );
				// 	} elseif ( 'requires_capture' === $intent->status ) {
				// 		$level3_data = $this->get_level3_data_from_order( $order );
				// 		$result = WC_Stripe_API::request_with_level3_data(
				// 			array(
				// 				'amount'   => WC_Stripe_Helper::get_stripe_amount( $order_total ),
				// 				'expand[]' => 'charges.data.balance_transaction',
				// 			),
				// 			'payment_intents/' . $intent->id . '/capture',
				// 			$level3_data,
				// 			$order
				// 		);

				// 		if ( ! empty( $result->error ) ) {
				// 			/* translators: error message */
				// 			$order->update_status( 'failed', sprintf( __( 'Unable to capture charge! %s', 'woocommerce-gateway-stripe' ), $result->error->message ) );
				// 		} else {
				// 			$is_stripe_captured = true;
				// 			$result = end( $result->charges->data );
				// 		}
				// 	} elseif ( 'succeeded' === $intent->status ) {
				// 		$is_stripe_captured = true;
				// 	}
				// } else {
				// 	// The order doesn't have a Payment Intent, fall back to capturing the Charge directly

				// 	// First retrieve charge to see if it has been captured.
				// 	$result = WC_Stripe_API::retrieve( 'charges/' . $charge );

				// 	if ( ! empty( $result->error ) ) {
				// 		/* translators: error message */
				// 		$order->add_order_note( sprintf( __( 'Unable to capture charge! %s', 'woocommerce-gateway-stripe' ), $result->error->message ) );
				// 	} elseif ( false === $result->captured ) {
				// 		$level3_data = $this->get_level3_data_from_order( $order );
				// 		$result = WC_Stripe_API::request_with_level3_data(
				// 			array(
				// 				'amount'   => WC_Stripe_Helper::get_stripe_amount( $order_total ),
				// 				'expand[]' => 'balance_transaction',
				// 			),
				// 			'charges/' . $charge . '/capture',
				// 			$level3_data,
				// 			$order
				// 		);

				// 		if ( ! empty( $result->error ) ) {
				// 			/* translators: error message */
				// 			$order->update_status( 'failed', sprintf( __( 'Unable to capture charge! %s', 'woocommerce-gateway-stripe' ), $result->error->message ) );
				// 		} else {
				// 			$is_stripe_captured = true;
				// 		}
				// 	} elseif ( true === $result->captured ) {
				// 		$is_stripe_captured = true;
				// 	}
				// }

				// if ( $is_stripe_captured ) {
				// 	/* translators: transaction id */
				// 	$order->add_order_note( sprintf( __( 'Stripe charge complete (Charge ID: %s)', 'woocommerce-gateway-stripe' ), $result->id ) );
				// 	$order->update_meta_data( '_stripe_charge_captured', 'yes' );

				// 	// Store other data such as fees
				// 	$order->set_transaction_id( $result->id );

				// 	if ( is_callable( array( $order, 'save' ) ) ) {
				// 		$order->save();
				// 	}

				// 	$this->update_fees( $order, $result->balance_transaction->id );
				// }

				// This hook fires when admin manually changes order status to processing or completed.
				// do_action( 'woocommerce_stripe_process_manual_capture', $order, $result );
			}
		}
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
			wc_reduce_stock_levels( $order_id );
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

		if ( 1 > $response->return_code || is_wp_error($response) ) {
			WC_ZaloPay_Logger::log( "Refund Error for order {$order_id}. Reason: {$response->return_message} ");
			return false;
		} elseif ($response->return_code == 1 || $response->return_code == 3) {
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

new WC_ZaloPay_Order_Handler();

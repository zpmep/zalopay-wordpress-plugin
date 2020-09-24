<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://zalopay.vn/
 * @since             1.0.0
 * @package           Zlp
 *
 * @wordpress-plugin
 * Plugin Name:       ZaloPay
 * Plugin URI:        https://zalopay.vn/
 * Description:       Zalopay Merchant intergration plugin.
 * Version:           1.0.0
 * Author:            ZION Co.
 * Author URI:        https://zalopay.vn/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       zlp
 * Domain Path:       /languages
 */

define( 'WC_ZALOPAY_VERSION', 'v2' );
define( 'ZLP_MIN_PHP_VER', '5.6.0' );
define( 'ZLP_MIN_WC_VER', '3.0' );
define('RETURNCODE_SUCCESS', 1);
define('RETURNCODE_ERROR', -1);
define('ZLP_QUERY_QUEUE', 'zlp_query_status');
define('ZLP_CURRENCY', 'VND');
define('ZLP_CREATE_ORDER_API', 'create');
define('ZLP_QUERY_ORDER_STATUS_API', 'query');
define('ZLP_REFUND_API', 'refund');
define('ZLP_QUERY_REFUND_STATUS_API', 'query_refund');

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define('ZLP_VERSION', '1.0.0');

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
// function run_zlp()
// {
// 	if ( !class_exists( 'WooCommerce' ) ) {
// 		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
// 		add_action( 'admin_notices', 'missing_wc_notice' );
// 		deactivate_plugins(plugin_basename( __FILE__ ) );
// 		return;
// 	}else {
// 		$plugin = new Zlp();
// 		$plugin->run();
// 	}
// }

/**
 * WooCommerce fallback notice.
 *
 * @since 4.1.2
 * @return string
 */
function zlp_missing_wc_notice() {
	echo '<div class="error"><p><strong>' . sprintf( esc_html__( 'ZaloPay requires WooCommerce to be installed and active. You can download %s here.', 'zlp' ), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>' ) . '</strong></p></div>';
}

function zlp_unsupported_currency_notice() {
	echo '<div class="error"><p><strong>' . sprintf( esc_html__( 'ZaloPay only support Vietnamese đồng (VNĐ) for now', 'zlp' )) . '</strong></p></div>';
}

add_action( 'plugins_loaded', 'zlp_init' );
function zlp_init() {
	load_plugin_textdomain( 'zlp', false, plugin_basename( dirname( __FILE__ ) ) . '/languages' );

	if ( ! class_exists( 'Z-lp' ) ) :

		class Zlp {

			/**
			 * @var Singleton The reference the *Singleton* instance of this class
			 */
			private static $instance;

			/**
			 * Returns the *Singleton* instance of this class.
			 *
			 * @return Singleton The *Singleton* instance.
			 */
			public static function get_instance() {
				if ( null === self::$instance ) {
					self::$instance = new self();
				}
				return self::$instance;
			}

			/**
			 * Private clone method to prevent cloning of the instance of the
			 * *Singleton* instance.
			 *
			 * @return void
			 */
			private function __clone() {}

			/**
			 * Private unserialize method to prevent unserializing of the *Singleton*
			 * instance.
			 *
			 * @return void
			 */
			private function __wakeup() {}

			/**
			 * Protected constructor to prevent creating a new instance of the
			 * *Singleton* via the `new` operator from outside of this class.
			 */
			private function __construct() {
				add_action( 'admin_init', array( $this, 'install' ) );
				$this->init();
			}

			/**
			 * Init the plugin after plugins_loaded so environment variables are set.
			 *
			 * @since 1.0.0
			 * @version 4.0.0
			 */
			public function init() {
				// if ( is_admin() ) {
				// 	require_once dirname( __FILE__ ) . '/includes/admin/class-wc-stripe-privacy.php';
				// }

				require_once dirname( __FILE__ ) . '/includes/class-wc-zlp-exception.php';
				require_once dirname( __FILE__ ) . '/includes/class-wc-zlp-logger.php';
				// require_once dirname( __FILE__ ) . '/includes/class-wc-stripe-helper.php';
				include_once dirname( __FILE__ ) . '/includes/class-wc-zlp-api.php';
				// require_once dirname( __FILE__ ) . '/includes/abstracts/abstract-wc-zlp-payment-gateway.php';
				// require_once dirname( __FILE__ ) . '/includes/class-wc-stripe-webhook-handler.php';
				// require_once dirname( __FILE__ ) . '/includes/class-wc-stripe-sepa-payment-token.php';
				// require_once dirname( __FILE__ ) . '/includes/class-wc-stripe-apple-pay-registration.php';
				// require_once dirname( __FILE__ ) . '/includes/compat/class-wc-stripe-pre-orders-compat.php';
				require_once dirname( __FILE__ ) . '/includes/class-wc-gateway-zlp.php';
				require_once dirname( __FILE__ ) . '/includes/class-wc-zlp-order-handler.php';
				// require_once dirname( __FILE__ ) . '/includes/payment-methods/class-wc-gateway-stripe-bancontact.php';
				// require_once dirname( __FILE__ ) . '/includes/payment-methods/class-wc-gateway-stripe-sofort.php';
				// require_once dirname( __FILE__ ) . '/includes/payment-methods/class-wc-gateway-stripe-giropay.php';
				// require_once dirname( __FILE__ ) . '/includes/payment-methods/class-wc-gateway-stripe-eps.php';
				// require_once dirname( __FILE__ ) . '/includes/payment-methods/class-wc-gateway-stripe-ideal.php';
				// require_once dirname( __FILE__ ) . '/includes/payment-methods/class-wc-gateway-stripe-p24.php';
				// require_once dirname( __FILE__ ) . '/includes/payment-methods/class-wc-gateway-stripe-alipay.php';
				// require_once dirname( __FILE__ ) . '/includes/payment-methods/class-wc-gateway-stripe-sepa.php';
				// require_once dirname( __FILE__ ) . '/includes/payment-methods/class-wc-gateway-stripe-multibanco.php';
				// require_once dirname( __FILE__ ) . '/includes/payment-methods/class-wc-stripe-payment-request.php';
				// require_once dirname( __FILE__ ) . '/includes/compat/class-wc-stripe-subs-compat.php';
				// require_once dirname( __FILE__ ) . '/includes/compat/class-wc-stripe-sepa-subs-compat.php';
				// require_once dirname( __FILE__ ) . '/includes/class-wc-stripe-order-handler.php';
				// require_once dirname( __FILE__ ) . '/includes/class-wc-stripe-payment-tokens.php';
				// require_once dirname( __FILE__ ) . '/includes/class-wc-stripe-customer.php';
				// require_once dirname( __FILE__ ) . '/includes/class-wc-stripe-intent-controller.php';

				// if ( is_admin() ) {
				// 	require_once dirname( __FILE__ ) . '/includes/admin/class-wc-stripe-admin-notices.php';
				// }

				// REMOVE IN THE FUTURE.
				// require_once dirname( __FILE__ ) . '/includes/deprecated/class-wc-stripe-apple-pay.php';

				add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateways' ) );
				add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
				// add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );

				// Modify emails emails.
				// add_filter( 'woocommerce_email_classes', array( $this, 'add_emails' ), 20 );

				// if ( version_compare( WC_VERSION, '3.4', '<' ) ) {
				// 	add_filter( 'woocommerce_get_sections_checkout', array( $this, 'filter_gateway_order_admin' ) );
				// }
			}

			/**
			 * Handles upgrade routines.
			 *
			 * @since 3.1.0
			 * @version 3.1.0
			 */
			public function install() {
				if ( !is_plugin_active( plugin_basename( __FILE__ ) ) ) {
					return;
				}elseif  ( !class_exists( 'WooCommerce' ) ) {
					require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
					add_action( 'admin_notices', 'zlp_missing_wc_notice' );
					deactivate_plugins(plugin_basename( __FILE__ ) );
					return;
				}elseif ( ! in_array( get_woocommerce_currency(), $this->get_supported_currency() ) ) {
					// add_action( 'admin_notices', 'zlp_unsupported_currency_notice' );
					// deactivate_plugins(plugin_basename( __FILE__ ) );
					// return;
				}
			}

			//ZaloPay Supported Currency
			public function get_supported_currency()
			{
				return apply_filters(
					'wc_zlp_supported_currencies',
					array(
						'VND'
					)
				);
			}

			/**
			 * Add plugin action links.
			 *
			 * @since 1.0.0
			 * @version 4.0.0
			 */
			public function plugin_action_links( $links ) {
				$plugin_links = array(
					'<a href="admin.php?page=wc-settings&tab=checkout">' . esc_html__( 'Settings', 'woocommerce-gateway-zalopay' ) . '</a>',
				);
				return array_merge( $plugin_links, $links );
			}

			/**
			 * Add plugin action links.
			 *
			 * @since 4.3.4
			 * @param  array  $links Original list of plugin links.
			 * @param  string $file  Name of current file.
			 * @return array  $links Update list of plugin links.
			 */
			// public function plugin_row_meta( $links, $file ) {
			// 	if ( plugin_basename( __FILE__ ) === $file ) {
			// 		$row_meta = array(
			// 			'docs'    => '<a href="' . esc_url( apply_filters( 'woocommerce_gateway_stripe_docs_url', 'https://docs.woocommerce.com/document/stripe/' ) ) . '" title="' . esc_attr( __( 'View Documentation', 'woocommerce-gateway-stripe' ) ) . '">' . __( 'Docs', 'woocommerce-gateway-stripe' ) . '</a>',
			// 			'support' => '<a href="' . esc_url( apply_filters( 'woocommerce_gateway_stripe_support_url', 'https://woocommerce.com/my-account/create-a-ticket?select=18627' ) ) . '" title="' . esc_attr( __( 'Open a support request at WooCommerce.com', 'woocommerce-gateway-stripe' ) ) . '">' . __( 'Support', 'woocommerce-gateway-stripe' ) . '</a>',
			// 		);
			// 		return array_merge( $links, $row_meta );
			// 	}
			// 	return (array) $links;
			// }

			/**
			 * Add the gateways to WooCommerce.
			 *
			 * @since 1.0.0
			 * @version 4.0.0
			 */
			public function add_gateways( $methods ) {
				$methods[] = 'WC_Gateway_ZaloPay';
				return $methods;
			}

			/**
			 * Modifies the order of the gateways displayed in admin.
			 *
			 * @since 4.0.0
			 * @version 4.0.0
			 */
			public function filter_gateway_order_admin( $sections ) {
				unset( $sections['zlp'] );
				$sections['zlp']            = __( 'ZaloPay', 'woocommerce-gateway-zalopay' );
				return $sections;
			}

			/**
			 * Adds the failed SCA auth email to WooCommerce.
			 *
			 * @param WC_Email[] $email_classes All existing emails.
			 * @return WC_Email[]
			 */
			// public function add_emails( $email_classes ) {
			// 	require_once WC_STRIPE_PLUGIN_PATH . '/includes/compat/class-wc-stripe-email-failed-authentication.php';
			// 	require_once WC_STRIPE_PLUGIN_PATH . '/includes/compat/class-wc-stripe-email-failed-renewal-authentication.php';
			// 	require_once WC_STRIPE_PLUGIN_PATH . '/includes/compat/class-wc-stripe-email-failed-preorder-authentication.php';
			// 	require_once WC_STRIPE_PLUGIN_PATH . '/includes/compat/class-wc-stripe-email-failed-authentication-retry.php';

				// Add all emails, generated by the gateway.
			// 	$email_classes['WC_Stripe_Email_Failed_Renewal_Authentication']  = new WC_Stripe_Email_Failed_Renewal_Authentication( $email_classes );
			// 	$email_classes['WC_Stripe_Email_Failed_Preorder_Authentication'] = new WC_Stripe_Email_Failed_Preorder_Authentication( $email_classes );
			// 	$email_classes['WC_Stripe_Email_Failed_Authentication_Retry'] = new WC_Stripe_Email_Failed_Authentication_Retry( $email_classes );

			// 	return $email_classes;
			// }
			
		}

		Zlp::get_instance();
	endif;
}

add_action(ZLP_QUERY_QUEUE, 'zlp_cron_job');
function zlp_cron_job($orderID)
{
	$isCallbackReceived = get_post_meta($orderID, 'zlp_callback_received', true);
	$timestamp = wp_next_scheduled( ZLP_QUERY_QUEUE, [$orderID]);
	$callInterVal = get_post_meta($orderID, 'zlp_call_interval', true);
	update_post_meta($orderID, 'zlp_call_interval', $callInterVal);
	if ($isCallbackReceived || ($callInterVal != NULL && $callInterVal >= 15) ) {
		wp_unschedule_event( $timestamp, ZLP_QUERY_QUEUE, [$orderID]);
	}
	
	if ($callInterVal == NULL) {
		update_post_meta($orderID, 'zlp_call_interval', 1);
	} else {
		update_post_meta($orderID, 'zlp_call_interval', ++$callInterVal);
	}

	$appTransID = get_post_meta($orderID,'zlp_app_trans_id', true);
	$response = WC_ZaloPay_API::request(['app_trans_id' => $appTransID], ZLP_QUERY_ORDER_STATUS_API);
	if (1 == $response->return_code) {
		$order = wc_get_order($orderID);
		$order->payment_complete();
		$order_stock_reduced = $order->get_meta('_order_stock_reduced', true);
		if (!$order_stock_reduced) {
			wc_reduce_stock_levels($orderID);
		}
		update_post_meta($orderID, 'zp_trans_id', $response->zp_trans_id);
		update_post_meta($orderID, 'zlp_callback_received', true);
		wp_unschedule_event( $timestamp, ZLP_QUERY_QUEUE, [$orderID]);
	}
}

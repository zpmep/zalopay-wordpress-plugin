<?php
if (!defined('ABSPATH')) {
	exit;
}
date_default_timezone_set('Asia/Ho_Chi_Minh');
/**
 * WC_Stripe_API class.
 *
 * Communicates with Stripe API.
 */
class WC_ZaloPay_API
{
	/**
	 * Stripe API Endpoint
	 */
	const ENDPOINT         = 'https://openapi.zalopay.vn/v2/';
	const SANDBOX_ENDPOINT = 'https://sb-openapi.zalopay.vn/v2/';

	/**
	 * Secret API Key.
	 * @var string
	 */
	private static $appID = '';

	/**
	 * Secret API Key.
	 * @var string
	 */
	private static $key1 = '';

	/**
	 * Secret API Key.
	 * @var string
	 */
	private static $key2 = '';

	/**
	 * Set secret API Key.
	 * @param string $key
	 */
	public static function setAppID($appID)
	{
		self::$appID = $appID;
	}

	/**
	 * Set secret API Key.
	 * @param string $key
	 */
	public static function setKey1($key1)
	{
		self::$key1 = $key1;
	}

	/**
	 * Set secret API Key.
	 * @param string $key
	 */
	public static function setKey2($key2)
	{
		self::$key2 = $key2;
	}

	/**
	 * Get secret key.
	 * @return string
	 */
	public static function getEndpoint()
	{
		$options = get_option('woocommerce_zlp_settings');
		if (!isset($options['sandboxMode']) || $options['sandboxMode'] === 'yes') {
			return self::SANDBOX_ENDPOINT;
		}
		return self::ENDPOINT;
	}

	public static function getAppID()
	{
		if (!self::$appID) {
			$options = get_option('woocommerce_zlp_settings');

			if (isset($options['appID'])) {
				self::setAppID($options['appID']);
			}
		}
		return self::$appID;
	}

	public static function getKey1()
	{
		if (!self::$key1) {
			$options = get_option('woocommerce_zlp_settings');

			if (isset($options['key1'])) {
				self::setKey1($options['key1']);
			}
		}
		return self::$key1;
	}

	public static function generateRefundRequest($data)
	{
		$zp_trans_id = get_post_meta($data['order_id'], 'zp_trans_id', true);
		$app_id = self::getAppID();
		$postData = [
			'app_id' => $app_id,
			'm_refund_id' => date('ymd') . '_' . $app_id . '_' . uniqid(),
			'zp_trans_id' => $zp_trans_id,
			'amount' => $data['amount'],
			'timestamp' => round(microtime(true) * 1000),
			'description' => $data['description'],
		];
		$hash_data = $postData["app_id"] . "|" . $postData["zp_trans_id"] . "|" . $postData["amount"] . "|" . $postData["description"] . "|" . $postData["timestamp"];
		$postData["mac"] = hash_hmac("sha256", $hash_data, self::getKey1());
		return $postData;
	}

	public static function generateCreateOrderRequest($data)
	{
		$postData = [
			'app_id' => self::getAppID(),
			'app_user' => $data['user'],
			'app_trans_id' => date('ymd') . '_' . uniqid(),
			'app_time' => round(microtime(true) * 1000),
			'amount' => $data['total'],
			'description' => $data['description'],
			'item' => $data['item'],
			'embed_data' => $data['embed_data'],
			'bank_code' => wp_is_mobile() ? "zalopayapp" : $data['bank_code']
		];


		$macData = $postData["app_id"] . "|" . $postData["app_trans_id"] . "|" . $postData["app_user"] . "|" . $postData["amount"]
			. "|" . $postData["app_time"] . "|" . $postData["embed_data"] . "|" . $postData["item"];
		$postData["mac"] = hash_hmac("sha256", $macData, self::getKey1());
		$postData["source"] = "web";
		
		// Save App Trans ID To PostMeta
		update_post_meta($data['orderID'], 'zlp_app_trans_id', $postData["app_trans_id"]);
		update_post_meta($data['orderID'], 'zlp_callback_received', false);
		return $postData;
	}

	public static function generateGetStatusRequest($data)
	{
		$postData = [
			'app_id' => self::getAppID(),
			'app_trans_id' => $data['app_trans_id'],
		];
		$data = $postData["app_id"] . "|" . $postData["app_trans_id"] . "|" . self::getKey1();
		$postData["mac"] = hash_hmac("sha256", $data, self::getKey1());
		return $postData;
	}
	/**
	 * Send the request to ZaloPay's API
	 *
	 * @since 3.1.0
	 * @version 4.0.6
	 * @param array $data
	 * @param string $api
	 * @param string $method
	 * @param bool $with_headers To get the response with headers.
	 * @return stdClass|array
	 * @throws WC_ZaloPay_Exception
	 */
	public static function request($data, $api = ZLP_CREATE_ORDER_API, $method = 'POST', $with_headers = false)
	{
		switch ($api) {
			case 'query':
				$postData = self::generateGetStatusRequest($data);
				break;
			case 'refund':
				$postData = self::generateRefundRequest($data);
				break;
			default:
				$postData = self::generateCreateOrderRequest($data);
				break;
		}

		WC_ZaloPay_Logger::log("{$api} postData: " . print_r($postData, true));
		WC_ZaloPay_Logger::log("{$api} endpoint: " . self::getEndpoint() . $api);
		$response = wp_remote_post(
			self::getEndpoint() . $api,
			array(
				'method'  => $method,
				'body'    => apply_filters('woocommerce_zlp_request_body', $postData, $api),
				'timeout' => 70,
			)
		);

		if (is_wp_error($response) || empty($response['body'])) {
			WC_ZaloPay_Logger::log(
				'Error Response: ' . print_r($response, true) . PHP_EOL . PHP_EOL . 'Failed request: ' . print_r(
					array(
						'api'             => $api,
						'postData'         => $postData,
					),
					true
				)
			);

			throw new WC_ZaloPay_Exception(print_r($response, true), __('There was a problem connecting to the ZaloPay API endpoint.', 'woocommerce-gateway-zalopay'));
		}

		if ($with_headers) {
			return array(
				'headers' => wp_remote_retrieve_headers($response),
				'body'    => json_decode($response['body']),
			);
		}
		if ($api == 'refund') {
			update_post_meta($data['order_id'], 'zlp_m_refund_id', $postData['m_refund_id']);
		}

		return json_decode($response['body']);
	}
}

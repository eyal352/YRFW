<?php

class YRFW_API_Wrapper {

	private $curl;
	private $app_key;
	private $secret;
	private static $instance;
	private static $base_uri = 'https://api.yotpo.com/';

	private static $base_uri_new_v3 = 'https://api.yotpo.com/core/v3/stores/';

	private function __construct() {
		// nothing
	}

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new static;
		}
		return self::$instance;
	}

	public function init( string $app_key, string $secret ) {
		$this->app_key = $app_key;
		$this->secret  = $secret;
		$this->curl    = new dcai\curl();
	}

	public function get_base_uri() {
		return self::$base_uri;
	}

	public function get_curl() {
		return $this->curl;
	}

	public function get_token() {
		$payload = [
			'client_id'     => $this->app_key,
			'client_secret' => $this->secret,
			'grant_type'    => 'client_credentials',
		];
		$response = $this->curl->post( self::$base_uri . 'oauth/token', $payload );
		if ( 200 === $response->statusCode ) {
			return json_decode( $response->text )->access_token;
		}
	}

	public function get_token_new_v3() {
		$curl = curl_init();

		$post_body = '{ "secret": ' . '"' . $this->secret . '"' . '}';

		curl_setopt_array($curl, array(
			CURLOPT_URL => self::$base_uri_new_v3 . "$this->app_key/access_tokens",
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_POSTFIELDS => $post_body,
			CURLOPT_HTTPHEADER => array(
				'Content-Type: application/json'
			),
		));

		$response = curl_exec($curl);
		$jsonArray = json_decode($response, true);

		return $jsonArray['access_token'];
	}

	public function submit_order_new_v3(array $order, $api_token) {
		global $yrfw_logger;

		#$this->curl->appendRequestHeaders(array('X-Yotpo-Token' => $api_token , 'Content-Type' => 'application/json'));
		$this->curl->appendRequestHeader('Content-Type', 'application/json');
		$this->curl->appendRequestHeader('X-Yotpo-Token', $api_token);
		$response = $this->curl->post(self::$base_uri_new_v3 . "$this->app_key/orders/", json_encode($order));
		$yrfw_logger->debug('Response Status code ' . $response->statusCode);
		#echo('request URLL ' . self::$base_uri_new_v3 . "$this->app_key/orders/" . "Token used: $api_token " . "Response Headers:" . print_r($response->headers) . "Response Text: $response->text");

		return $response->statusCode;
	}

	public function submit_new_product_v3(array $product) {
		global $yrfw_logger;
		$api_token = $this->get_token_new_v3();

		$this->curl->appendRequestHeader('Content-Type', 'application/json');
		$this->curl->appendRequestHeader('X-Yotpo-Token', $api_token);
		$response = $this->curl->post(self::$base_uri_new_v3 . "$this->app_key/products", json_encode($product));
		//$yrfw_logger->debug('Response Status code ' . $response->statusCode);
		#echo('request URLL ' . self::$base_uri_new_v3 . "$this->app_key/orders/" . "Token used: $api_token " . "Response Headers:" . print_r($response->headers) . "Response Text: $response->text");

		$yrfw_logger->debug("Create new Product returned with response: $response->statusCode");
		return $response->statusCode;
	}

	public function submit_existing_order_v3(array $checkout_object, $order_id) {
		global $yrfw_logger;
		$api_token = $this->get_token_new_v3();
		$data = json_encode($checkout_object);
		$yotpo_order_id = $this->get_yotpo_order_id($order_id);


		$yrfw_logger->debug("Yotpo Order ID is $yotpo_order_id");

		$url = "https://api.yotpo.com/core/v3/stores/$this->app_key/orders/$yotpo_order_id";
		$headers = array('Content-Type: application/json', "X-Yotpo-Token: $api_token");
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLINFO_HEADER_OUT, true);
		curl_setopt($curl, CURLOPT_MAXREDIRS, 10);
		curl_setopt($curl, CURLOPT_VERBOSE, 1);
		curl_setopt($curl, CURLOPT_TIMEOUT, 5);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
		curl_setopt($curl, CURLOPT_TIMEOUT, 0);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PATCH');
		curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		$response = curl_exec($curl);

		$info = curl_getinfo($curl);
		#$header = substr($response, 0, $header_size);
		$header_data = curl_getinfo($curl, CURLINFO_HEADER_OUT);
		$body = substr($response, $header_size);
		# echo $header_size . $header . $body;
		#$yrfw_logger->debug("Response content $response \n Header data: $header_data \n Body: $body");
		#$yrfw_logger->debug("DATA REQUEST HEADER: " . print_r($info));
		$httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

		$yrfw_logger->debug("Update Existing Order returned with response: $httpcode");
		return $httpcode;
	}

	public function submit_existing_product_v3(array $product, $product_id) {
		global $yrfw_logger;
		$api_token = $this->get_token_new_v3();
		$yotpo_product_id = $this->get_yotpo_product_id($product_id);
		
		if ($yotpo_product_id == null) {
			$yrfw_logger->debug("Yotpo Product ID: null");
			$this->submit_new_product_v3($product);
		} else {
			$data = json_encode($product);
			#$yrfw_logger->debug("data object: $data");
			$url = "https://api.yotpo.com/core/v3/stores/$this->app_key/products/$yotpo_product_id";
			$headers = array('Content-Type: application/json', "X-Yotpo-Token: $api_token");
			$curl = curl_init();
			curl_setopt($curl, CURLOPT_URL, $url);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLINFO_HEADER_OUT, true);
			curl_setopt($curl, CURLOPT_MAXREDIRS, 10);
			curl_setopt($curl, CURLOPT_VERBOSE, 1);
			curl_setopt($curl, CURLOPT_TIMEOUT, 5);
			curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
			curl_setopt($curl, CURLOPT_TIMEOUT, 0);
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PATCH');
			curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
			curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
			$response = curl_exec($curl);

			$info = curl_getinfo($curl);
			#$header = substr($response, 0, $header_size);
			$header_data = curl_getinfo($curl, CURLINFO_HEADER_OUT);
			$body = substr($response, $header_size);
			# echo $header_size . $header . $body;
			#$yrfw_logger->debug("Response content $response \n Header data: $header_data \n Body: $body");
			#$yrfw_logger->debug("DATA REQUEST HEADER: " . print_r($info));
			$httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

			$yrfw_logger->debug("Update Existing Product returned with response: $httpcode");
			return $httpcode;
		}
	}

	public function get_yotpo_product_id($id) {
		global $yrfw_logger;
		$api_token = $this->get_token_new_v3();
		$this->curl->appendRequestHeader('Content-Type', 'application/json');
		$this->curl->appendRequestHeader('X-Yotpo-Token', $api_token);
		$products_response = $this->curl->get(self::$base_uri_new_v3 . "$this->app_key/products?limit=100");
		$response_array = json_decode($products_response, true);
		$products_array = $response_array['products'];

		#$yrfw_logger->debug($products_response);
		#$yrfw_logger->debug(print_r($products_array));
		$yotpo_id = null;
		foreach ($products_array as $prod) {
			if ($prod['external_id'] == $id) {
				$yotpo_id = $prod['yotpo_id'];
			}
		}

		return $yotpo_id;
	}

	public function get_yotpo_order_id($id) {
		global $yrfw_logger;
		$api_token = $this->get_token_new_v3();
		$this->curl->appendRequestHeader('Content-Type', 'application/json');
		$this->curl->appendRequestHeader('X-Yotpo-Token', $api_token);
		$response = $this->curl->get(self::$base_uri_new_v3 . "$this->app_key/orders?external_ids=$id");
		$response_array = json_decode($response, true);
		$yotpo_order_id = $response_array['orders'][0]['yotpo_id'];
		$yrfw_logger->debug("Yotpo Order ID: $yotpo_order_id");
		return $yotpo_order_id;
	}

	public function submit_checkout_data_new_v3(array $checkout_object, $api_token){
		global $yrfw_logger;
		$token = $this->get_token_new_v3();
		$data = json_encode($checkout_object);
		$url = "https://api.yotpo.com/core/v3/stores/DwD6IBVXhrSlRC8geuVgMqGgKlEMC1QYXu1k1Cx5/checkouts";
		$headers = array('Content-Type: application/json', "X-Yotpo-Token: $token");
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLINFO_HEADER_OUT, true);
		curl_setopt($curl, CURLOPT_MAXREDIRS, 10);
		#curl_setopt($curl, CURLOPT_VERBOSE, 1);
		curl_setopt($curl, CURLOPT_TIMEOUT, 5);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
		curl_setopt($curl, CURLOPT_TIMEOUT, 0);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PATCH');
		curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		$response = curl_exec($curl);


		#echo $response;
		#echo json_decode($response);
		#print_r($headers);

		 #$header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
		 #$header_data = curl_getinfo($curl, CURLINFO_HEADER_OUT);
		 $info = curl_getinfo($curl);
		 #$header = substr($response, 0, $header_size);
		 $body = substr($response, $header_size);
		# echo $header_size . $header . $body;
		 $yrfw_logger->debug("Response content $response \n Header data: $header_data \n Body: $body");
		 $yrfw_logger->debug( "DATA REQUEST HEADER: " . print_r($info));
		// curl_close($curl);
		 #echo "RESPONSE ECHO: $response";
		 return $response;
	}

	public function send_customer_data_v3(array $customer_data) {
		global $yrfw_logger;
		$token = $this->get_token_new_v3();
		$app_key = $this->app_key;
		$data = json_encode($customer_data);
		$url = "https://api.yotpo.com/core/v3/stores/$app_key/customers";
		$headers = array('Content-Type: application/json', "X-Yotpo-Token: $token");
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLINFO_HEADER_OUT, 1);
		curl_setopt($curl, CURLOPT_MAXREDIRS, 10);
		curl_setopt($curl, CURLOPT_VERBOSE, 1);
		curl_setopt($curl, CURLOPT_TIMEOUT, 0);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
		curl_setopt($curl, CURLOPT_TIMEOUT, 0);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PATCH');
		curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		$response = curl_exec($curl);

		$yrfw_logger->debug("update CUSTOMER DATA : $data \n CUSTOMER API RESPONSE: $response");
		#$yrfw_logger->debug("CUSTOMER ID " . array_values($customer_data)[0] . "CUSTOMER OBJECT PRETTY" . json_encode($customer_data) . "APPKEY $app_key");
		#echo $customer_data;
	}

	public function submit_order( array $order ) {
		$this->curl->appendRequestHeader( 'Content-Type', 'application/json' );
		$response = $this->curl->post( self::$base_uri . "apps/$this->app_key/purchases/", json_encode( $order ) );
		return $response->statusCode;
	}

	public function submit_orders( array $orders ) {
		$this->curl->appendRequestHeader( 'Content-Type', 'application/json' );
		$response = $this->curl->post( self::$base_uri . "apps/$this->app_key/purchases/mass_create", json_encode( $orders ) );
		return $response->statusCode;
	}

	public function get_product_bottomline( $product_id ) {
		$this->curl->appendRequestHeader( 'Content-Type', 'application/json' );
		$response = $this->curl->get( self::$base_uri . "products/$this->app_key/$product_id/bottomline" );
		return $response->text;
	}

	public function get_site_bottomline() {
		return $this->get_product_bottomline( 'yotpo_site_reviews' );
	}

}
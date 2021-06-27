<?php

/**
 * @package YotpoReviews
 */
class YRFW_Orders {
	/**
	 * Submit single order to Yotpo api
	 *
	 * @param  int  $order_id order ID to submit.
	 * @param  bool $retry To prevent going into a loop, we will only try to get a new utoken once.
	 * @return boolean        order submission success
	 * @throws void Try and get new token in case of 401.
	 */
	public function submit_single_order( int $order_id, bool $retry = false ) {
		global $yrfw_logger, $settings_instance;
		$order        = wc_get_order( $order_id );
		$order_status = "wc-{$order->get_status()}";
		$time         = microtime( true );
		#$yrfw_logger->debug("ORDER OUTPOUT: $order");
		$yrfw_logger->debug( "Order #$order_id changed status to $order_status, should be $settings_instance[yotpo_order_status]" );
		if ( $order_status == 'wc-pending' || $settings_instance['yotpo_order_status'] === $order_status ) {
			$yrfw_logger->debug( "Starting submission of order #$order_id" );
			if ( ! empty( $settings_instance['app_key'] ) && ! empty( $settings_instance['secret'] ) ) {
				$order_data = $this->get_single_order_data_new_v3( $order );
				
				if ( ! is_null( $order_data ) && is_array( $order_data ) ) {
					$curl = YRFW_API_Wrapper::get_instance();
					$curl->init( $settings_instance['app_key'], $settings_instance['secret'] );
					$api_time  = microtime( true );
					$api_token = $this->get_token_from_cache( true );
					$yrfw_logger->debug( 'Getting API token took ' . ( microtime( true ) - $api_time ) . ' seconds to complete. Token is ' . $api_token );
					if ( ! empty( $api_token ) ) {
						// $order_data['utoken']            = $api_token;
						// $order_data['platform']          = 'woocommerce';
						// $order_data['extension_version'] = YRFW_PLUGIN_VERSION;
						// $order_data['validate_data']     = false;
						try {
							$create_time = microtime( true );
							$response    = $curl->submit_order_new_v3( $order_data, $api_token );
							$yrfw_logger->debug( 'Order submission took ' . ( microtime( true ) - $create_time ) . ' seconds to complete.' );
							if ( 201 === $response ) {
								$yrfw_logger->info( "Order #$order_id submitted with response $response" );
								$yrfw_logger->debug( 'The whole process took ' . ( microtime( true ) - $time ) . ' seconds to complete.' );
								$yrfw_logger->debug('order object: ' . $order_data);
								$this->update_order_meta( $order_id );
							} elseif (409 == $response) {
								$yrfw_logger->warn("Order #$order_id returned with response " . (print_r($response, true) . " The order already exists, making patch request..."));
								$yrfw_logger->debug('order object: ' . json_encode($order_data));
								$patch_request = $curl->submit_existing_order_v3($order_data, $order_id);
								$yrfw_logger->debug("Patch Order Update submitted with response $patch_request");

							} elseif ( 401 === $response && ! $retry ) {
								$yrfw_logger->warn( "Order #$order_id failed with response " . ( print_r( $response, true ) ) );
								$yrfw_logger->debug('order object: ' . json_encode($order_data));
								throw new Exception( 'Access Denied', 401 );
							} else {
								$yrfw_logger->warn( "Order #$order_id returned with response " . ( print_r( $response, true ) ) );
								$yrfw_logger->debug('order object: ' . json_encode($order_data));
								return false;
							}
						} catch ( \Throwable $th ) {
							if ( 401 === $th ) {
								$this->get_token_from_cache( true );
								$this->submit_single_order( $order_id, true );
							}
						}
						unset( $order_data, $response, $api, $api_token );
					}
				}
			}
		}
	}

	/**
	 * Get single order information
	 *
	 * @param  object $order the order.
	 * @return array product data array
	 */
	public function get_single_order_data( WC_Order $order ) {
		global $yrfw_logger, $yotpo_cache, $settings_instance;
		$order_time   = microtime( true );
		$order_data   = array();
		$products_arr = array();
		$order_id     = $order->get_id();
		$order_data['order_date'] = date( 'Y-m-d H:i:s', strtotime( $order->get_date_created() ) );
		$email                    = $order->get_billing_email();
		if (
			! empty( $email )
			&& ! preg_match( '/\d$/', $email )
			&& filter_var( $email, FILTER_VALIDATE_EMAIL )
			&& strlen( substr( $email, strrpos( $email, '.' ) ) ) >= 3
		) {
			$order_data['email'] = $email;
		} else {
			$yrfw_logger->warn( "Order #$order_id Dropped - Invalid Email ($email)" );
			return;
		}
		$name = trim( "{$order->get_billing_first_name()} {$order->get_billing_last_name()}" );
		if ( ! empty( $name ) ) {
			$order_data['customer_name'] = $name;
		} else {
			$yrfw_logger->warn( "Order #$order_id Dropped - Invalid Name ($name)" );
			return;
		}
		$order_data['order_id'] = $order_id;
		$order_data['currency'] = YRFW_CURRENCY;
		$yrfw_logger->debug( "┌ Order #$order_data[order_id] Date: $order_data[order_date] Email: $order_data[email]" );
		$items = $order->get_items();
		if ( empty( $items ) ) {
			$yrfw_logger->warn( "Order #$order_id Dropped - No Products" );
			return;
		}
		foreach ( $items as $item ) {
			if ( '0' === $item['product_id'] ) {
				$yrfw_logger->warn( "Order #$order_id Dropped - Invalid Product (ID of 0)" );
				return;
			}
			$parent_id    = $item->get_product()->get_parent_id();
			$product_id   = ( 0 !== $parent_id ) ? $parent_id : $item['product_id'];
			$variation_id = $item->get_variation_id();
			$quantity     = $item['qty'];
			$_product     = $yotpo_cache->get_cached_product( $product_id );
			if ( ! $_product ) { return; }
			$product_data            =& $_product;
			$product_data['app_key'] = $settings_instance['app_key'];
			$product_data['price']   = ( $product_data['price'] ?: 0 ) * $quantity; // WIP - To be fixed.
			if ( 0 !== $variation_id ) {
		####
				$product_data['custom_properties']['name']  = 'variant';
				$product_data['custom_properties']['value'] = ( wc_get_product( $variation_id ) )->get_name();
			}
		###
			$products_arr[ $item['product_id'] ] = $product_data;

			$yrfw_logger->debug( "├─ Product: $product_data[name], ID: $product_id, Price: $product_data[price] $order_data[currency], Quantity: $item[qty], Image: $product_data[image]" );
			if ( isset( $product_data['custom_properties'] ) ) {
				$yrfw_logger->debug( '└── Variation: ' . $product_data['custom_properties']['value'] );
			}
		}
		$order_data['products'] = $products_arr;
		unset( $specs, $products_arr, $order, $items );
		$yrfw_logger->debug( 'Preparing order data took ' . ( microtime( true ) - $order_time ) . ' seconds to complete.' );
		return $order_data;
	}




	public function get_single_order_data_new_v3(WC_Order $order) {
		global $yrfw_logger, $yotpo_cache, $settings_instance;
		$order_time   = microtime(true);
		
		$order_data_final = array();
		$order_data   = array(); #highest level object
		$customer_array = array(); #customer object, email, id, name, accepts_sms_marketing
		$products_arr = array(); #array of product IDs in the 'Products' object of the JSON
		$fulfillments_array = array(); # arrray containing the fulfillment status of the order. To be used when creating AND updating orders

		#$order_data['order']['line_items'] = array();

		$order_id     = $order->get_id();
		$user_id      = $order->get_user_id();
		#$yrfw_logger->debug("SMS MARKETING DATA: " . get_post_meta($order_id, 'accept_sms_marketing', true));

		$order_data['external_id'] = strval($order_id);
		$order_data['order_date'] = date('Y-m-d\TH:i:s', strtotime($order->get_date_created()));
		$order_data['currency'] = YRFW_CURRENCY;
		$order_data['checkout_token'] = date('md') . $user_id;

		#build customer object
		$customer_array['external_id'] = strval($user_id);
		
		$email = $order->get_billing_email();
		# check email
		if (
			!empty($email)
			&& !preg_match('/\d$/', $email)
			&& filter_var($email, FILTER_VALIDATE_EMAIL)
			&& strlen(substr($email, strrpos($email, '.'))) >= 3
		) {
			$customer_array['email'] = $email;
		} else {
			$yrfw_logger->warn("Order #$order_id Dropped - Invalid Email ($email)");
			return;
		}
		$name = trim("{$order->get_billing_first_name()} {$order->get_billing_last_name()}");
		if (!empty($name)) {
			$customer_array['first_name'] = $order->get_billing_first_name();
			$customer_array['last_name'] = $order->get_billing_last_name();
		} else {
			$yrfw_logger->warn("Order #$order_id Dropped - Invalid Name ($name)");
			return;
		}

		if(get_post_meta($order_id, 'accept_sms_marketing', true)) {
			$customer_array['accepts_sms_marketing'] = true;
		} else {
			$customer_array['accepts_sms_marketing'] = false;
		}

		$customer_array['accepts_email_marketing'] = true;

		#append customer object to higher level order_data object
		$order_data['customer'] = $customer_array;
		
		$yrfw_logger->debug("┌ Order #$order_id Date: $order_data[order_date] Email: $customer_array[email]");

		#build line_items array

		$items = $order->get_items();
		if (empty($items)) {
			$yrfw_logger->warn("Order #$order_id Dropped - No Products");
			return;
		}

		$products_array = array();

		foreach ($items as $key=>$item) {
			if ('0' === $item['product_id']) {
				$yrfw_logger->warn("Order #$order_id Dropped - Invalid Product (ID of 0)");
				return;
			}
			$product = array();
			$parent_id    = $item->get_product()->get_parent_id();
			$product_id   = (0 !== $parent_id) ? $parent_id : $item['product_id'];
			$variation_id = $item->get_variation_id();
			#$quantity     = $item['qty'];
			$_product     = $yotpo_cache->get_cached_product($product_id);
			if (!$_product) {
				return;
			}
			$product['quantity'] = $item['qty'];
			$product['external_product_id'] = strval($product_id);
			
			array_push($products_array, $product);

			#$yrfw_logger->debug("├─ Product: ID: $product_id, Price: $product_data[price] $order_data[currency], Quantity: $item[qty], Image: $product_data[image]");
			if (isset($product_data['custom_properties'])) {
				$yrfw_logger->debug('└── Variation: ' . $product_data['custom_properties']['value']);
			}
		}

		$order_data['line_items'] = $products_array;

		#build fulfillments array
		$order_status = $order->get_status();
		#$yrfw_logger->debug("ORDER STATUS for PATCH request-> $order_status");
		if($order_status !== 'completed'){
			$fulfillments_array['fulfillment_date'] = date('Y-m-d\TH:i:s', strtotime('+3 days', strtotime($order->get_date_created())));
			$fulfillments_array['status'] = "pending";
		} else {
			$fulfillments_array['fulfillment_date'] = date('Y-m-d\TH:i:s', strtotime($order->get_date_completed()));
			$fulfillments_array['status'] = "success";
		}

		$fulfillments_array['external_id'] = strval($order_id);
		$fulfillments_array['fulfilled_items'] = $products_array;

		$order_data['fulfillments'] = [$fulfillments_array];



		$order_data_final['order'] = $order_data;
		
		#$order_data['line_items'] = $products_arr;
		unset($specs, $products_arr, $order, $items);
		$yrfw_logger->debug('Preparing order data took ' . (microtime(true) - $order_time) . ' seconds to complete.');
		#echo json_encode($order_data_final);
		$yrfw_logger->debug('order object' . json_encode($order_data_final));
		return $order_data_final;
	}


	public function submit_checkout_object(){
		global $yrfw_logger, $settings_instance;
		$curl = YRFW_API_Wrapper::get_instance();
		$curl->init($settings_instance['app_key'], $settings_instance['secret']);
		$api_token = $this->get_token_from_cache(true);
		$checkout_object = $this->create_checkout_object();
		#echo json_encode($checkout_object);
		try {
			$create_time = microtime(true);
			$response    = $curl->submit_checkout_data_new_v3($checkout_object, $api_token);
			$yrfw_logger->debug('Checkout submission took ' . (microtime(true) - $create_time) . ' seconds to complete.');
			if ($response === 201) {
				$yrfw_logger->info("Checkout submitted with response $response");
				$yrfw_logger->debug('The whole process took ' . (microtime(true) - $time) . ' seconds to complete.');
				$yrfw_logger->debug('checkout object: ' . $checkout_object);
			} elseif (401 === $response && !$retry) {
				$yrfw_logger->warn("Checkout" . print_r($checkout_object) ." failed with response " . (print_r($response, true)));
				$yrfw_logger->debug('checkout object: ' . json_encode($checkout_object));
				throw new Exception('Access Denied', 401);
			} else {
				$yrfw_logger->warn("Checkout " . print_r($checkout_object) . "  returned with response " . $response);
				$yrfw_logger->debug('checkout object: ' . json_encode($checkout_object));
				return false;
			}
		} catch (\Throwable $th) {
			if (401 === $th->getCode()) {
				$this->get_token_from_cache(true);
				$this->submit_checkout_object($checkout_object, true);
			}
		}

	}

	public function create_checkout_object(){
		$checkout_object = array();
		$customer_object = array();

		$user = wp_get_current_user();
		$user_token = date('md') . $user->ID;
		$checkout_object['token'] = strval($user_token);
		$checkout_object['checkout_date'] = date('Y-m-d\TH:i:s');
		$phone ;
		if(get_user_meta($user->ID, 'billing_phone', true) ) {
			$phone = get_user_meta($user->ID, 'billing_phone', true);
			//set phone number on customer object, else don't
			$customer_object['phone_number'] = "+$phone";
		} else {
			$phone = '5555555551';
		}
		

		$customer_object['external_id'] = strval($user->ID);
		$customer_object['email'] = $user->user_email;
		$customer_object['first_name'] = $user->user_firstname;
		$customer_object['last_name'] = $user->user_lastname;

		$checkout_object['customer'] = $customer_object;

		$products_array = array();

		#echo "user token: $user_token \n checkout date: $checkout_date \n";
		#$cart = WC()->cart->get_cart();
		#echo print_r($cart);
		#echo 'Username: ' . $user->user_login . "\n";
		foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
			$product = array();
			$product['external_product_id'] = strval($cart_item['product_id']);
			$product['quantity'] = strval($cart_item['quantity']);
			$product['total_price'] = strval($cart_item['line_total']);
			array_push($products_array, $product);
			#echo "product: $product \n, product ID: $product_id \n, Quantity: $quantity \n, price: $price \n";
		};

		$checkout_object['line_items'] = $products_array;
		$checkout_object_final['checkout'] = $checkout_object;
		return $checkout_object_final;
	}


	/**
	 * Returning auth token from cache (transient) or setting it if not available.
	 *
	 * @param boolean $refresh_token should we force refresh the token.
	 * @return string
	 */
	public function get_token_from_cache( bool $refresh_token = false ) {
		global $settings_instance, $yrfw_logger;
		$token = get_transient( 'yotpo_utoken' );
		if ( false === $token || $refresh_token ) { 
			$api = YRFW_API_Wrapper::get_instance();
			$api->init( $settings_instance['app_key'], $settings_instance['secret'] );
			$token = $api->get_token();
			if ( ! empty( $token ) ) {
				set_transient( 'yotpo_utoken', $token, WEEK_IN_SECONDS );
				$yrfw_logger->debug( "Got new token $token." );
			}
		}
		return $token;
	}

	/**
	 * Update transient with last order sent and set flag (post meta) for order ID to prevent increasing order counter.
	 *
	 * @param int $order_id the order ID.
	 * @return void
	 */
	public function update_order_meta( &$order_id ) {
		set_transient( 'yotpo_last_sent_order', date( 'Y-m-d H:i:s' ), 2 * WEEK_IN_SECONDS );
		if ( ! get_post_meta( $order_id, 'yotpo_order_sent' ) ) {
			set_transient( 'yotpo_total_orders', get_transient( 'yotpo_total_orders' ) + 1 );
			add_post_meta( $order_id, 'yotpo_order_sent', date( 'Y-m-d H:i:s' ) );
		}
	}
}
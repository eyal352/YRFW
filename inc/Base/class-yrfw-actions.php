<?php

/**
 * @package YotpoReviews
 * This class is responsible for all actions/filters.
 * e.g. widgets, order fulfillment
 */
class YRFW_Actions
{

	/**
	 * Performs all plugin actions
	 */
	public function __construct()
	{
		global $settings_instance;
		if (true === $settings_instance['authenticated']) {
			add_action('init', array($this, 'action_disable_reviews'), 10);
			add_action('init', array($this, 'action_generate_product_cache'), 9999);
			add_action('woocommerce_order_status_changed', array($this, 'action_submit_order'), 99, 1);

			#SMS checkout page call
			add_action('woocommerce_checkout_billing', array($this, 'action_submit_checkout'));

			if (true === $settings_instance['bottom_line_enabled_product']) {
				add_action($settings_instance['product_bottomline_hook'], array($this, 'action_show_star_rating_widget'), $settings_instance['product_bottomline_priority']);
			} elseif ('jsinject' === $settings_instance['bottom_line_enabled_product']) {
				add_action('wp_footer', array($this, 'action_js_inject_star_rating'));
			}
			if (true === $settings_instance['qna_enabled_product']) {
				add_action($settings_instance['product_qna_hook'], array($this, 'action_show_qa_widget'), $settings_instance['product_qna_priority']);
			} elseif ('jsinject' === $settings_instance['qna_enabled_product']) {
				add_action('wp_footer', array($this, 'action_js_inject_qa'));
			}
			if (true === $settings_instance['bottom_line_enabled_category']) {
				add_action($settings_instance['category_bottomline_hook'], array($this, 'action_show_star_rating_widget'), $settings_instance['category_bottomline_priority']);
			}
			add_action('yotpo_scheduler_action', array($this, 'action_perform_scheduler'));
			add_action('woocommerce_thankyou', array($this, 'action_show_conversion_tracking'), 1, 1);
			switch ($settings_instance['widget_location']) {
				case 'footer':
					add_action($settings_instance['main_widget_hook'], array($this, 'action_show_main_widget'), $settings_instance['main_widget_priority']);
					break;
				case 'tab':
					add_action('woocommerce_product_tabs', array($this, 'action_show_main_widget_tab'));
					add_filter('woocommerce_tab_manager_integration_tab_allowed', function () {
						return false;
					});
					break;
				case 'jsinject': // @TODO: add setting?
					add_action('wp_footer', array($this, 'action_js_inject_widget'));
					break;
				default:
					break;
			}
		}
	}

	/**
	 * Submit order once status equals status in settings, if the method is hook (as opposed to a schedule)
	 *
	 * @param  int $order_id order ID to submit.
	 * @return void
	 */
	public function action_submit_order(int $order_id)
	{
		global $yotpo_orders, $settings_instance;
		if ('hook' === $settings_instance['order_submission_method'] || !isset($settings_instance['order_submission_method'])) {
			$yotpo_orders->submit_single_order($order_id);
		}
	}

	public function action_submit_checkout()
	{
		global $yotpo_orders;
		$yotpo_orders->submit_checkout_object();
	}


	/**
	 * Show main widget at desired location
	 *
	 * @return void
	 */
	public function action_show_main_widget()
	{
		global $yotpo_widgets;
		echo $yotpo_widgets->main_widget();
	}

	/**
	 * Show star rating at desired location
	 *
	 * @return void
	 */
	public function action_show_star_rating_widget()
	{
		global $yotpo_widgets;
		echo $yotpo_widgets->bottomline();
	}

	/**
	 * Show Q&A widget at desired location
	 *
	 * @return void
	 */
	public function action_show_qa_widget()
	{
		global $yotpo_widgets;
		echo $yotpo_widgets->qa_bottomline();
	}

	/**
	 * Show main widget using JS injection
	 *
	 * @return void
	 */
	public function action_js_inject_widget()
	{
		global $yotpo_widgets;
		echo $yotpo_widgets->js_inject_main_widget();
	}

	/**
	 * Show star rating using JS injection
	 *
	 * @return void
	 */
	public function action_js_inject_star_rating()
	{
		global $yotpo_widgets;
		echo $yotpo_widgets->js_inject_rating('rating');
	}

	/**
	 * Show star rating using JS injection
	 *
	 * @return void
	 */
	public function action_js_inject_qa()
	{
		global $yotpo_widgets;
		echo $yotpo_widgets->js_inject_rating('qna');
	}

	/**
	 * Show main widget in a tab
	 *
	 * @param  array $tabs current tabs.
	 * @return array       tabs array with new tab appended.
	 */
	public function action_show_main_widget_tab($tabs)
	{
		global $product, $yotpo_widgets;
		if ($product->get_reviews_allowed()) {
			global $settings_instance;
			$tabs['yotpo_widget'] = array(
				'title'    => $settings_instance['widget_tab_name'],
				'priority' => 50,
				'callback' => array($this, 'action_show_main_widget'),
			);
		}
		return $tabs;
	}

	/**
	 * Perform scheduled submission of orders
	 *
	 * @return void
	 */
	public function action_perform_scheduler()
	{
		global $yotpo_scheduler, $yrfw_logger;
		$yrfw_logger->title('Starting scheduled order submission.');
		$yotpo_scheduler->do_scheduler();
		$yrfw_logger->title('Finished scheduled order submission.');
	}

	/**
	 * Start up the product cache.
	 *
	 * @return void
	 */
	public function action_generate_product_cache()
	{
		global $yotpo_cache;
		$yotpo_cache = YRFW_Product_Cache::get_instance();
		$yotpo_cache->init(YRFW_PLUGIN_PATH . 'products.json');
	}

	/**
	 * Conversion tracking pixel and script
	 *
	 * @param integer $order_id ther order id from the cart thank-you page.
	 * @return string
	 */
	public function action_show_conversion_tracking(int $order_id)
	{
		global $yotpo_widgets;
		return $yotpo_widgets->conversion_tracking($order_id);
	}

	/**
	 * Disable native reviews system
	 *
	 * @return void
	 */
	public function action_disable_reviews()
	{
		global $settings_instance;
		if (true === $settings_instance['disable_native_review_system']) {
			remove_action('woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_rating');
			remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_rating');
			add_filter('woocommerce_product_tabs', function ($tabs) {
				unset($tabs['reviews']);
				return $tabs;
			}, 99);
		}
	}
}

// Begin port of functions previously added to functions.php file

/* Inline script printed out in the footer */
add_action('wp_footer', 'sms_library');
function sms_library()
{
?>
	<script type="text/javascript" src="https://dhv2ziothpgrr.cloudfront.net/273026/form_40991.js?ver=1621505879"></script>
	<script type="text/javascript" src="https://dhv2ziothpgrr.cloudfront.net/273026/form_42790.js?ver=1621506016"></script>
<?php
}

// Submit API calls for product creation and update
add_action('added_post_meta', 'create_new_product_v3', 10, 4); //new product
add_action('updated_post_meta', 'update_current_product_v3', 10, 4); // update product

function update_current_product_v3($meta_id, $post_id, $meta_key, $meta_value)
{
	global $yrfw_logger;
	$curl = YRFW_API_Wrapper::get_instance();
	$product_object = array();
	$final_product = array();
	if ($meta_key == '_edit_lock') { // we've been editing the post
		if (get_post_type($post_id) == 'product') { // we've been editing a product
			$product = wc_get_product($post_id);
			$product_id = $product->get_id();

			$product_object['external_id'] = $product_id;
			$product_object['name'] = $product->get_name();
			$product_object['description'] = $product->get_description();
			$product_object['price'] = $product->get_regular_price();

			$final_product['product'] = $product_object;
			$response = $curl->submit_existing_product_v3($final_product, $product_id);
			//$yrfw_logger->debug($response);
		}
	}
}

function create_new_product_v3($meta_id, $post_id, $meta_key, $meta_value)
{
	global $yrfw_logger;
	$curl = YRFW_API_Wrapper::get_instance();
	$product_object = array();
	if ($meta_key == '_edit_lock') { // we've been editing the post
		if (get_post_type($post_id) == 'product') { // we've been editing a product
			$product = wc_get_product($post_id);
			//$yrfw_logger->debug($product);
			$product_object['external_id'] = $product->get_id();
			$product_object['name'] = $product->get_name();
			$product_object['description'] = $product->get_description();

			$product_final['product'] = $product_object;
			//$yrfw_logger->debug(print_r($product_final));

			$response = $curl->submit_new_product_v3($product_final);
			$yrfw_logger->debug($response);
			return $product_final;
		}
	}
}

// CUSTOMER UPDATE ON CREATION
add_action('user_register', 'create_customer', 10, 1);
add_action('profile_update', 'create_customer', 10, 1);
add_action('edit_user_created_user', 'create_customer', 10, 1);

function create_customer($user_id)
{
	write_log($user_id);
	global $yrfw_logger, $settings_instance;
	$curl = YRFW_API_Wrapper::get_instance();
	$curl->init($settings_instance['app_key'], $settings_instance['secret']);
	$customer_object = array();
	$final_object = array();
	$user = get_userdata($user_id);
	$customer_object['external_id'] = strval($user_id);
	$customer_object['email'] = $user->user_email;
	$final_object['customer'] = $customer_object;

	$yrfw_logger->debug("Customer OBJECT:" . json_encode($customer_object));

	$curl->send_customer_data_v3($final_object);
};

//ADD CHECKOUT CHECKBOX
add_action('woocommerce_review_order_before_submit', 'bt_add_checkout_checkbox', 10);

function bt_add_checkout_checkbox()
{

	woocommerce_form_field('checkout_checkbox', array( // CSS ID
		'type'          => 'checkbox',
		'class'         => array('form-row sms-checkbox'), // CSS Class
		'label_class'   => array('woocommerce-form__label woocommerce-form__label-for-checkbox checkbox'),
		'input_class'   => array('woocommerce-form__input woocommerce-form__input-checkbox input-checkbox'),
		'required'      => false, // Mandatory or Optional
		'label'         => 'ACCEPT SMS MARKETING', // Label and Link
	));
}

add_action('woocommerce_checkout_update_order_meta', 'bt_checkout_field_order_meta_db');
/**
 * Add custom field as order meta with field value to database
 */
function bt_checkout_field_order_meta_db($order_id)
{
	if (!empty($_POST['checkout_checkbox'])) {
		update_post_meta($order_id, 'accept_sms_marketing', sanitize_text_field($_POST['checkout_checkbox']));
	}
}

add_action('woocommerce_order_status_changed', 'woo_order_status_change_custom', 10, 3);

function woo_order_status_change_custom($order_id, $old_status, $new_status)
{
	global $yrfw_logger;
	$order = wc_get_order($order_id);
	//order ID is filled
	//old_status and new_status never
	//$yrfw_logger->debug("Completed Order is: " . print_r($order, true));
}

//$user = wp_get_current_user();

// $curl_new = YRFW_API_Wrapper::get_instance();
// $curl_new->init($settings_instance['app_key'], $settings_instance['secret']);

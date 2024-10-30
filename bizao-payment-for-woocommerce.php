<?php
/**
 * Plugin Name: BizaoPay for Woocommerce
 * Plugin URI: https://dev.bizao.com
 * Author Name: Bizao
 * Author URI: https://bizao.com
 * Description: Payez facilement et en toute sécurité vos achats avec votre compte Mobile Money ou Visa/MasterCard. ​
 * Version: 0.2.0
 * License:   GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * Class WC_Gateway_Bizao file.
 *
 * @package WooCommerce\Bizao
 */

use Automattic\Jetpack\Constants;

if (!defined('ABSPATH'))
{
    exit; // Exit if accessed directly.
}

/**
 * BizaoPay Gateway.
 *
 * Provides a BizaoPay Payment Gateway.
 *
 * @class       WC_Gateway_Bizao
 * @extends     WC_Payment_Gateway
 * @version     0.2.0
 * @package     WooCommerce\Classes\Payment
 */



 // Start of email unhooking
 add_action( 'woocommerce_email', 'bizao_unhook_woocommerce_emails' );
function bizao_unhook_woocommerce_emails( $email_class ) {
		/**
		 * Hooks for sending emails during store events
		 **/
		remove_action( 'woocommerce_low_stock_notification', array( $email_class, 'low_stock' ) );
		remove_action( 'woocommerce_no_stock_notification', array( $email_class, 'no_stock' ) );
		remove_action( 'woocommerce_product_on_backorder_notification', array( $email_class, 'backorder' ) );

		// New order emails
		remove_action( 'woocommerce_order_status_pending_to_processing_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
		remove_action( 'woocommerce_order_status_pending_to_completed_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
		remove_action( 'woocommerce_order_status_pending_to_on-hold_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
		remove_action( 'woocommerce_order_status_failed_to_processing_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
		remove_action( 'woocommerce_order_status_failed_to_completed_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
		remove_action( 'woocommerce_order_status_failed_to_on-hold_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );

		// Processing order emails
		remove_action( 'woocommerce_order_status_pending_to_processing_notification', array( $email_class->emails['WC_Email_Customer_Processing_Order'], 'trigger' ) );
		remove_action( 'woocommerce_order_status_pending_to_on-hold_notification', array( $email_class->emails['WC_Email_Customer_Processing_Order'], 'trigger' ) );

		// Completed order emails
		remove_action( 'woocommerce_order_status_completed_notification', array( $email_class->emails['WC_Email_Customer_Completed_Order'], 'trigger' ) );

		// Note emails
		remove_action( 'woocommerce_new_customer_note_notification', array( $email_class->emails['WC_Email_Customer_Note'], 'trigger' ) );
}
 // End of email unhooking


if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) return;

function bizao_settings_links($links)
{
    $bizao_links[] = '<a href="' . get_admin_url(null, 'admin.php?page=wc-settings&tab=checkout&section=bizao') . '">' . __('Settings', 'woocommerce') . '</a>';

    return $bizao_links + $links;
}

add_action('plugin_action_links_' . plugin_basename(__FILE__) , 'bizao_settings_links');

$current_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
$checkout_url = esc_url($_COOKIE["checkout_url"]);


// Checking card payment status
if (isset($_GET["mno_name"]) && ($_GET["mno_name"] == 'vmc') && isset($_GET["country_code"]) && isset($_GET["order_reference"]))
{
    $client_id = sanitize_text_field($_COOKIE["client_id"]);
    $client_secret = sanitize_text_field($_COOKIE["client_secret"]);
    $wp_order_id = sanitize_text_field($_COOKIE["wp_order_id"]);
    $mno_name = sanitize_text_field($_GET["mno_name"]);
    $country_code = "ci";
    $product_id = sanitize_text_field($_COOKIE["id"]);
    $order_ref = sanitize_text_field($_COOKIE["order_ref"]);
    $success_return_url = esc_url($_COOKIE["success_return_url"]);
    $basic = base64_encode("$client_id:$client_secret");


     // Start of access token fetching
     $token_url = "https://api.bizao.com/token";

     $access_token_request_headers = array(
         'Authorization' => "Basic $basic",
         'Content-Type' => 'application/x-www-form-urlencoded'
     );

     $access_token_request_body = array(
         'grant_type' => 'client_credentials'
     );

     $access_token_request_args = array(
         'headers' => $access_token_request_headers,
         'body' => $access_token_request_body
     );

     $response = json_encode(wp_remote_post($token_url, $access_token_request_args));
     $response_body = json_decode($response)->body;
     $access_token = json_decode($response_body)->access_token;

     // End of access token fetching

    // Getting transaction status
    $vmc_get_status_url = "https://api.bizao.com/debitCard/v2/getStatus/$order_ref";
    $get_status_response = wp_remote_get($vmc_get_status_url, ['timeout' => 100, 'headers' => ["Authorization" => "Bearer $access_token", "Accept" => "application/json", "Content-Type" => "application/json", "country-code" => $country_code],

    ]);

    $status_response = json_encode($get_status_response);
    $body_status = json_decode($status_response)->body;
    $payment_status = json_decode($body_status)->status;
    unset($access_token);

    if (is_null($payment_status) || $payment_status == 'Failed' || $payment_status == 'InProgress' || $payment_status == "")
    {
        global $wpdb;
        $wpdb->query("
		UPDATE {$wpdb->prefix}posts
		SET post_status = 'wc-cancelled'
		WHERE ID = $product_id
		AND post_type = 'shop_order'
	");
    }

    else if ($payment_status == 'Successful')
    {
        global $wpdb;
        $wpdb->query("
		UPDATE {$wpdb->prefix}posts
		SET post_status = 'wc-completed'
		WHERE ID = $product_id
		AND post_type = 'shop_order'
	");

        header("Refresh:1; url=" . $success_return_url, true, 303);
    }
}

// Checking mobile money payment status
else if (isset($_GET["mno_name"]) && isset($_GET["country_code"]) && isset($_GET["order_reference"]))
{
    $client_id = sanitize_text_field($_COOKIE["client_id"]);
    $client_secret = sanitize_text_field($_COOKIE["client_secret"]);
    $wp_order_id = sanitize_text_field($_COOKIE["wp_order_id"]);
    $mno_name = sanitize_text_field($_GET["mno_name"]);
    $country_code = sanitize_text_field($_GET["country_code"]);
    $product_id = sanitize_text_field($_COOKIE["id"]);
    $order_ref = sanitize_text_field($_COOKIE["order_ref"]);
    $success_return_url = esc_url($_COOKIE["success_return_url"]);
    $basic = base64_encode("$client_id:$client_secret");

    // Start of access token fetching
    $token_url = "https://api.bizao.com/token";

    $access_token_request_headers = array(
        'Authorization' => "Basic $basic",
        'Content-Type' => 'application/x-www-form-urlencoded'
    );

    $access_token_request_body = array(
        'grant_type' => 'client_credentials'
    );

    $access_token_request_args = array(
        'headers' => $access_token_request_headers,
        'body' => $access_token_request_body
    );

    $response = json_encode(wp_remote_post($token_url, $access_token_request_args));
    $response_body = json_decode($response)->body;
    $access_token = json_decode($response_body)->access_token;

    // End of access token fetching


    // Getting transaction status
    $mm_get_status_url = "https://api.bizao.com/mobilemoney/getstatus/v1/$order_ref";
    $get_status_response = wp_remote_get($mm_get_status_url, ['timeout' => 100, 'headers' => ["Authorization" => "Bearer $access_token", "Accept" => "application/json", "Content-Type" => "application/json", "country-code" => $country_code, "mno-name" => $mno_name],

    ]);

    $status_response = json_encode($get_status_response);
    $body_status = json_decode($status_response)->body;
    $payment_status = json_decode($body_status)->status;
    unset($access_token);

    if (is_null($payment_status) || $payment_status == 'Failed' || $payment_status == 'InProgress' || $payment_status == "")
    {
        global $wpdb;
        $wpdb->query("
		UPDATE {$wpdb->prefix}posts
		SET post_status = 'wc-cancelled'
		WHERE ID = $product_id
		AND post_type = 'shop_order'
	");
    }

    else if ($payment_status == 'Successful')
    {
        global $wpdb;
        $wpdb->query("
		UPDATE {$wpdb->prefix}posts
		SET post_status = 'wc-completed'
		WHERE ID = $product_id
		AND post_type = 'shop_order'
	");

        header("Refresh:1; url=" . $success_return_url, true, 303);
    }
}


add_action('plugins_loaded', 'bizao_payment_init', 11);
function bizao_payment_init()
{
    if (class_exists('WC_Payment_Gateway'))
    {
        class WC_Gateway_Bizao extends WC_Payment_Gateway
        {

            /**
             * Constructor for the gateway.
             */
            public function __construct()
            {
                // Setup general properties.
                $this->setup_properties();

                // Load the settings.
                $this->init_form_fields();
                $this->init_settings();

                // Get settings.
                $this->title = $this->get_option('title');
                $this->description = $this->get_option('description');
                $this->env = $this->get_option('env');
                $this->clientIDTest = $this->get_option('clientIDTest');
                $this->clientSecretTest = $this->get_option('clientSecretTest');
                $this->clientIDProd = $this->get_option('clientIDProd');
                $this->clientSecretProd = $this->get_option('clientSecretProd');
                $this->reference = $this->get_option('reference');
                $this->state = $this->get_option('state');
                $this->notif_url = $this->get_option('notifURL');
                $this->instructions = $this->get_option('instructions');
                $this->enable_for_methods = $this->get_option('enable_for_methods', array());
                $this->enable_for_virtual = $this->get_option('enable_for_virtual', 'yes') === 'yes';

                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
                    $this,
                    'process_admin_options'
                ));
                add_action('woocommerce_thankyou_' . $this->id, array(
                    $this,
                    'thankyou_page'
                ));
                add_filter('woocommerce_payment_complete_order_status', array(
                    $this,
                    'change_payment_complete_order_status'
                ) , 10, 3);

                // Customer Emails.
                add_action('woocommerce_email_before_order_table', array(
                    $this,
                    'email_instructions'
                ) , 10, 3);
            }

            /**
             * Setup general properties for the gateway.
             */
            protected function setup_properties()
            {
                $this->id = 'bizao';
                $this->icon = apply_filters('woocommerce_bizao_icon', plugins_url('/assets/bizao.png', __FILE__));
                $this->method_title = __('BizaoPay', 'woocommerce');
                $this->method_description = __('Payez facilement et en toute sécurité vos achats avec votre compte Mobile Money.', 'woocommerce');
                $this->env = $this->get_option('env');
                $this->clientIDTest = __('Le Client_ID Test est votre identifiant de test fourni par BIZAO​.', 'woocommerce');
                $this->clientSecretTest = __('Le Client_SECRET Test est votre Clé Secrète de test fournie par BIZAO.', 'woocommerce');
                $this->clientIDProd = __('Le Client_ID Prod est votre identifiant de production fourni par BIZAO​.', 'woocommerce');
                $this->clientSecretProd = __('Le Client_SECRET Prod est votre Clé Secrète de production fournie par BIZAO.', 'woocommerce');
                $this->reference = __('La référence vous est fournie par BIZAO.​', 'woocommerce');
                $this->state = __('Le state est un identifiant fourni par BIZAO.', 'woocommerce');
                $this->notif_url = __('URL sur laquelle BIZAO vous renvoie les informations de transaction.', 'woocommerce');
                $this->has_fields = false;
            }

            /**
             * Admin Panel Options
             */
            public function admin_options()
            {

?>

		<h3 style="text-decoration:underline">Note :</h3>
		<h2 style="color:black;font-weight:bold; text-decoration:underline">Afin de terminer l'activation du plugin, veuillez s'il vous plaît remplir le formulaire présent sur <a href="https://bizao.com/demarrer-un-projet/?utm_source=Plugins&utm_medium=organic&utm_campaign=Project_Wordpress" target="_blank">ce lien.</a></h2>
        <h4>** Une documentation faisant office de vérification de votre identité ainsi que celle de votre entreprise sera obligatoire pour le passage en production.</h4>
		<?php
                echo '<table class="form-table">';
                $this->generate_settings_html();
                echo '</table>';

            }

            /**
             * Initialise Gateway Settings Form Fields.
             */
            public function init_form_fields()
            {
                $this->form_fields = array(
                    'enabled' => array(
                        'title' => __('Enable/Disable', 'woocommerce') ,
                        'label' => __('Enable BizaoPay', 'woocommerce') ,
                        'type' => 'checkbox',
                        'description' => '',
                        'default' => 'no',
                    ) ,
                    'env' => array(
                        'title' => __('environnement', 'woocommerce') ,
                        'description' => __('choisissez un environnement.', 'woocommerce') ,
                        'default' => 'Test',
                        'type' => 'select',
                        'options' => array(
                            'test' => __('Test', 'woocommerce') ,
                            'prod' => __('Production', 'woocommerce') ,
                        ) ,
                        'desc_tip' => true,
                    ) ,
                    'clientIDTest' => array(
                        'title' => __('Client_ID Test.', 'woocommerce') ,
                        'type' => 'text',
                        'description' => __('Le client_id test est votre identifiant de test fourni par BIZAO​.', 'woocommerce') ,
                        'desc_tip' => true,
                    ) ,
                    'clientSecretTest' => array(
                        'title' => __('Client_Secret Test.', 'woocommerce') ,
                        'type' => 'text',
                        'description' => __('Le client_secret test est votre Clé Secrète de test fournie par BIZAO.', 'woocommerce') ,
                        'desc_tip' => true,
                    ) ,
                    'clientIDProd' => array(
                        'title' => __('Client_ID Prod.', 'woocommerce') ,
                        'type' => 'text',
                        'description' => __('Le client_id prod est votre identifiant de production fourni par BIZAO​.', 'woocommerce') ,
                        'desc_tip' => true,
                    ) ,
                    'clientSecretProd' => array(
                        'title' => __('Client_Secret Prod.', 'woocommerce') ,
                        'type' => 'text',
                        'description' => __('Le client_secret prod est votre Clé Secrète de production fournie par BIZAO.', 'woocommerce') ,
                        'desc_tip' => true,
                    ) ,
                    'title' => array(
                        'title' => __('Texte du bouton de paiement.', 'woocommerce') ,
                        'type' => 'text',
                        'description' => __('Le texte qui indiquera au client le moyen de paiement à utiliser.​', 'woocommerce') ,
                        'default' => __('Paiement par Mobile Money.', 'woocommerce') ,
                        'desc_tip' => true,
                    ) ,
                    'description' => array(
                        'title' => __('Description du bouton de paiement.', 'woocommerce') ,
                        'type' => 'textarea',
                        'description' => __('Description des moyens de paiement que l’utilisateur verra sous le Bouton de paiement. ​', 'woocommerce') ,
                        'default' => __('Effectuez votre paiement par Mobile Money.', 'woocommerce') ,
                        'desc_tip' => true,
                    ) ,
                    'reference' => array(
                        'title' => __('Reference.', 'woocommerce') ,
                        'type' => 'text',
                        'description' => __('La référence vous est fournie par BIZAO.​', 'woocommerce') ,
                        'desc_tip' => true,
                    ) ,
                    'state' => array(
                        'title' => __('State.', 'woocommerce') ,
                        'type' => 'text',
                        'description' => __('Le state est un identifiant fourni par BIZAO.', 'woocommerce') ,
                        'desc_tip' => true,
                    ) ,
                    'notifURL' => array(
                        'title' => __('Notif_URL' . " " . '(Optionnel)', 'woocommerce') ,
                        'type' => 'text',
                        'description' => __('URL sur laquelle BIZAO vous renvoie les informations des transactions.', 'woocommerce') ,
                        'desc_tip' => true,
                    )
                );
            }

            /**
             * Check If The Gateway Is Available For Use.
             *
             * @return bool
             */
            public function is_available()
            {
                $order = null;
                $needs_shipping = false;

                // Test if shipping is needed first.
                if (WC()->cart && WC()
                    ->cart
                    ->needs_shipping())
                {
                    $needs_shipping = true;
                }
                elseif (is_page(wc_get_page_id('checkout')) && 0 < get_query_var('order-pay'))
                {
                    $order_id = absint(get_query_var('order-pay'));
                    $order = wc_get_order($order_id);

                    // Test if order needs shipping.
                    if (0 < count($order->get_items()))
                    {
                        foreach ($order->get_items() as $item)
                        {
                            $_product = $item->get_product();
                            if ($_product && $_product->needs_shipping())
                            {
                                $needs_shipping = true;
                                break;
                            }
                        }
                    }
                }

                $needs_shipping = apply_filters('woocommerce_cart_needs_shipping', $needs_shipping);

                // Virtual order, with virtual disabled.
                if (!$this->enable_for_virtual && !$needs_shipping)
                {
                    return false;
                }

                // Only apply if all packages are being shipped via chosen method, or order is virtual.
                if (!empty($this->enable_for_methods) && $needs_shipping)
                {
                    $order_shipping_items = is_object($order) ? $order->get_shipping_methods() : false;
                    $chosen_shipping_methods_session = WC()
                        ->session
                        ->get('chosen_shipping_methods');

                    if ($order_shipping_items)
                    {
                        $canonical_rate_ids = $this->get_canonical_order_shipping_item_rate_ids($order_shipping_items);
                    }
                    else
                    {
                        $canonical_rate_ids = $this->get_canonical_package_rate_ids($chosen_shipping_methods_session);
                    }

                    if (!count($this->get_matching_rates($canonical_rate_ids)))
                    {
                        return false;
                    }
                }

                return parent::is_available();
            }

            /**
             * Checks to see whether or not the admin settings are being accessed by the current request.
             *
             * @return bool
             */
            private function is_accessing_settings()
            {
                if (is_admin())
                {
                    // phpcs:disable WordPress.Security.NonceVerification
                    if (!isset($_REQUEST['page']) || 'wc-settings' !== $_REQUEST['page'])
                    {
                        return false;
                    }
                    if (!isset($_REQUEST['tab']) || 'checkout' !== $_REQUEST['tab'])
                    {
                        return false;
                    }
                    if (!isset($_REQUEST['section']) || 'bizao' !== $_REQUEST['section'])
                    {
                        return false;
                    }
                    // phpcs:enable WordPress.Security.NonceVerification
                    return true;
                }

                if (Constants::is_true('REST_REQUEST'))
                {
                    global $wp;
                    if (isset($wp->query_vars['rest_route']) && false !== strpos($wp->query_vars['rest_route'], '/payment_gateways'))
                    {
                        return true;
                    }
                }

                return false;
            }

            /**
             * Loads all of the shipping method options for the enable_for_methods field.
             *
             * @return array
             */
            private function load_shipping_method_options()
            {
                // Since this is expensive, we only want to do it if we're actually on the settings page.
                if (!$this->is_accessing_settings())
                {
                    return array();
                }

                $data_store = WC_Data_Store::load('shipping-zone');
                $raw_zones = $data_store->get_zones();

                foreach ($raw_zones as $raw_zone)
                {
                    $zones[] = new WC_Shipping_Zone($raw_zone);
                }

                $zones[] = new WC_Shipping_Zone(0);

                $options = array();
                foreach (WC()->shipping()
                    ->load_shipping_methods() as $method)
                {

                    $options[$method->get_method_title() ] = array();

                    // Translators: %1$s shipping method name.
                    $options[$method->get_method_title() ][$method->id] = sprintf(__('Any &quot;%1$s&quot; method', 'woocommerce') , $method->get_method_title());

                    foreach ($zones as $zone)
                    {

                        $shipping_method_instances = $zone->get_shipping_methods();

                        foreach ($shipping_method_instances as $shipping_method_instance_id => $shipping_method_instance)
                        {

                            if ($shipping_method_instance->id !== $method->id)
                            {
                                continue;
                            }

                            $option_id = $shipping_method_instance->get_rate_id();

                            // Translators: %1$s shipping method title, %2$s shipping method id.
                            $option_instance_title = sprintf(__('%1$s (#%2$s)', 'woocommerce') , $shipping_method_instance->get_title() , $shipping_method_instance_id);

                            // Translators: %1$s zone name, %2$s shipping method instance name.
                            $option_title = sprintf(__('%1$s &ndash; %2$s', 'woocommerce') , $zone->get_id() ? $zone->get_zone_name() : __('Other locations', 'woocommerce') , $option_instance_title);

                            $options[$method->get_method_title() ][$option_id] = $option_title;
                        }
                    }
                }

                return $options;
            }

            /**
             * Converts the chosen rate IDs generated by Shipping Methods to a canonical 'method_id:instance_id' format.
             *
             * @since  3.4.0
             *
             * @param  array $order_shipping_items  Array of WC_Order_Item_Shipping objects.
             * @return array $canonical_rate_ids    Rate IDs in a canonical format.
             */
            private function get_canonical_order_shipping_item_rate_ids($order_shipping_items)
            {

                $canonical_rate_ids = array();

                foreach ($order_shipping_items as $order_shipping_item)
                {
                    $canonical_rate_ids[] = $order_shipping_item->get_method_id() . ':' . $order_shipping_item->get_instance_id();
                }

                return $canonical_rate_ids;
            }

            /**
             * Converts the chosen rate IDs generated by Shipping Methods to a canonical 'method_id:instance_id' format.
             *
             * @since  3.4.0
             *
             * @param  array $chosen_package_rate_ids Rate IDs as generated by shipping methods. Can be anything if a shipping method doesn't honor WC conventions.
             * @return array $canonical_rate_ids  Rate IDs in a canonical format.
             */
            private function get_canonical_package_rate_ids($chosen_package_rate_ids)
            {

                $shipping_packages = WC()->shipping()
                    ->get_packages();
                $canonical_rate_ids = array();

                if (!empty($chosen_package_rate_ids) && is_array($chosen_package_rate_ids))
                {
                    foreach ($chosen_package_rate_ids as $package_key => $chosen_package_rate_id)
                    {
                        if (!empty($shipping_packages[$package_key]['rates'][$chosen_package_rate_id]))
                        {
                            $chosen_rate = $shipping_packages[$package_key]['rates'][$chosen_package_rate_id];
                            $canonical_rate_ids[] = $chosen_rate->get_method_id() . ':' . $chosen_rate->get_instance_id();
                        }
                    }
                }

                return $canonical_rate_ids;
            }

            /**
             * Indicates whether a rate exists in an array of canonically-formatted rate IDs that activates this gateway.
             *
             * @since  3.4.0
             *
             * @param array $rate_ids Rate ids to check.
             * @return boolean
             */
            private function get_matching_rates($rate_ids)
            {
                // First, match entries in 'method_id:instance_id' format. Then, match entries in 'method_id' format by stripping off the instance ID from the candidates.
                return array_unique(array_merge(array_intersect($this->enable_for_methods, $rate_ids) , array_intersect($this->enable_for_methods, array_unique(array_map('wc_get_string_before_colon', $rate_ids)))));
            }

            /**
             * Process the payment and return the result.
             *
             * @param int $order_id Order ID.
             * @return array
             */

            public function process_payment($order_id)
            {
                $order = wc_get_order($order_id);
                if ($order->get_total() > 0)
                {
                    $this->bizao_payment($order);
                    $this->store_credentials();
                }
                $order->update_status(apply_filters('woocommerce_bizao_process_payment_order_status', $order->has_downloadable_item() ? 'on-hold' : 'processing', $order) , __('Payment to be made with an MNO.', 'woocommerce'));
                // Return thankyou redirect.
                return array(
                    'result' => 'success',
                    'redirect' => $this->get_payment_link($this->env) . urlencode($this->bizao_payment($order))
                );

            }

            //Start of Encryption function

            /**
             * Get encrypt method length number (128, 192, 256).
             *
             * @return integer.
             */

            public function encrytpWithRSA($source)
            {
                //Fetching public_key
                $public_key = file_get_contents("assets/public.crt", true);
                //initial character start
                $start = 0;
                // length of data divided by 10
                $length = strlen($source) / 10;
                //arround length to small number
                $num_length = floor($length);
                for ($i = 0;$i < $num_length;$i++)
                {
                    $crypttext = '';
                    // encrypt every section of data
                    openssl_public_encrypt(substr($source, $start, 10) , $crypttext, $public_key);
                    // increment start
                    $start += 10;
                    //concat with encrypted data
                    $crt .= $crypttext;
                    //breakpoint
                    $crt .= ":::";
                }

                //prevent floot result  on division
                if ((strlen($source) % 10) > 0)
                {
                    openssl_public_encrypt(substr($source, $start) , $crypttext, $public_key);
                    $crt .= $crypttext;
                }
                //encoding on base64
                return base64_encode($crt);
            }

            public function store_credentials()
            {
                $client_id = $this->set_client_id($this->env);
                $client_secret = $this->set_client_secret($this->env);

                /*
                Cookies expires if the payment process
                is not completed within 10 minutes
                */
                setcookie("client_id", $client_id, time() + 10 * 60);
                setcookie("client_secret", $client_secret, time() + 10 * 60);

            }

            public function get_payment_link($bizaoEnv)
            {
                if ($bizaoEnv == 'test') return "https://d36ikzw287ira.cloudfront.net/#/entry?sessionId=";
                else return "https://secure.bizao.com/entry?sessionId=";

            }

            public function set_client_id($bizaoEnv)
            {
                if ($bizaoEnv == 'test') return $this->clientIDTest;
                else return $this->clientIDProd;
            }

            public function set_client_secret($bizaoEnv)
            {
                if ($bizaoEnv == 'test') return $this->clientSecretTest;
                else return $this->clientSecretProd;
            }

            public function bizao_payment($order)
            {
                global $woocommerce;
                $lang = get_bloginfo("language");
                $language = explode("-", $lang) [0];
                $request_id = $order->id;
                $order_id = $order->id;
                $amount = $order->get_total();
                $currency = get_woocommerce_currency();
                $client_id = $this->set_client_id($this->env);
                $client_secret = $this->set_client_secret($this->env);
                $reference = $this->reference;
                $state = $this->state;
                $notif_url = $this->notif_url;
                $success_return_url = $this->get_return_url($order);
                $country = $woocommerce
                    ->customer
                    ->get_shipping_country();
                setcookie("id", $order_id, time() + 30 * 24 * 60 * 60);
                setcookie("order_ref", $order_id . '_wp_' . $reference, time() + 30 * 24 * 60 * 60);
                setcookie("success_return_url", $success_return_url, time() + 30 * 24 * 60 * 60);
                setcookie("checkout_url", wc_get_checkout_url() , time() + 30 * 24 * 60 * 60);
                setcookie("wp_order_id", $order_id, time() + 30 * 24 * 60 * 60);
                $body = wp_json_encode(array(
                    "currency" => $currency,
                    "order_id" => $order_id . '_wp_' . $reference,
                    "amount" => (int)$amount,
                    "state" => $state,
                    "reference" => $reference,
                    "lang" => $language,
                    "client_id" => $client_id,
                    "request_id" => $order_id . '_wp_' . $reference,
                    "return_url" => wc_get_checkout_url() ,
                    "cancel_url" => $shop_page_url,
                    "notif_url" => $notif_url,
                    "country" => $country,
                ));

                $encryptedBody = $this->encrytpWithRSA($body);
                return $encryptedBody;
            }

            /**
             * Output for the order received page.
             */
            public function thankyou_page()
            {
                if ($this->instructions)
                {
                    echo wp_kses_post(wpautop(wptexturize($this->instructions)));
                }
            }

            /**
             * Change payment complete order status to completed for bizao orders.
             *
             * @since  3.1.0
             * @param  string         $status Current order status.
             * @param  int            $order_id Order ID.
             * @param  WC_Order|false $order Order object.
             * @return string
             */
            public function change_payment_complete_order_status($status, $order_id = 0, $order = false)
            {
                if ($order && 'bizao' === $order->get_payment_method())
                {
                    $status = 'completed';
                }
                return $status;
            }

            /**
             * Add content to the WC emails.
             *
             * @param WC_Order $order Order object.
             * @param bool     $sent_to_admin  Sent to admin.
             * @param bool     $plain_text Email format: plain text or HTML.
             */
            public function email_instructions($order, $sent_to_admin, $plain_text = false)
            {
                if ($this->instructions && !$sent_to_admin && $this->id === $order->get_payment_method())
                {
                    echo wp_kses_post(wpautop(wptexturize($this->instructions)) . PHP_EOL);
                }
            }
        }

    }
}

add_filter('woocommerce_payment_gateways', 'add_to_woo_bizao_payment_gateway');
function add_to_woo_bizao_payment_gateway($gateways)
{
    $gateways[] = 'WC_Gateway_Bizao';
    return $gateways;
}

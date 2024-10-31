<?php
/*
Plugin Name: PayItLater Gateway for WooCommerce
Plugin URI: https://www.payitlater.com.au/
Description: Checkout with PayItLater in WooCommerce. Increase your sales and conversions by getting paid today and allowing your customers to pay in instalments.
Version: 1.5.7
Author: PayItLater
Author URI: https://www.payitlater.com.au/

Copyright: © 2022 PayItLater

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/**
 * Required functions
 */
// if ( ! function_exists( 'woothemes_queue_update' ) ) {
//     require_once( 'woo-includes/woo-functions.php' );
// }


// Test to see if WooCommerce is active (including network activated).
$plugin_path = trailingslashit( WP_PLUGIN_DIR ) . 'woocommerce/woocommerce.php';

if (
    in_array( $plugin_path, wp_get_active_and_valid_plugins() )
    || in_array( $plugin_path, wp_get_active_network_plugins() )
) {
    // Custom code here. WooCommerce is active, however it has not 
    // necessarily initialized (when that is important, consider
    // using the `woocommerce_init` action).
}else {
    add_action( 'admin_notices', 'payitlater_need_woocommerce' );
}

add_action( 'plugins_loaded', 'payitlater_init', 0 );


function payitlater_need_woocommerce() {
    $error = sprintf( __( 'The payitlater plugin requires WooCommerce. Please install and active the  %sWooCommerce%s plugin. ' , 'foo' ), '<a href="http://wordpress.org/extend/plugins/woocommerce/">', '</a>' );
    $message = '<div class="error"><p>' . $error . '</p></div>';
    echo $message;
  }

function payitlater_init() {

    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }

    class WC_Gateway_PayItLater extends WC_Payment_Gateway {

        /**
         * @var Singleton The reference to the singleton instance of this class
         */
        private static $_instance = null;

        /**
         * @var boolean Whether or not logging is enabled
         */
        public static $log_enabled = false;

        /**
         * @var WC_Logger Logger instance
         */
        public static $log = false;

        public static function instance() {
            if ( is_null( self::$_instance ) ) {
                self::$_instance = new self();
            }

            return self::$_instance;
        }

        public function __construct() {

            global $woocommerce;


            $this->id                 = 'payitlater';
            $this->method_title       = __( 'PayItLater', 'payitlater_plugin' );
            $this->order_button_text  = __( 'Checkout with PayItLater', 'payitlater_plugin' );
            $this->method_description = __( 'Use PayItLater to allow customers in Australia to pay by instalments with WooCommerce. The PayItLater gateway requires an active merchant agreement with PayItLater. If you do not have an account, <a href="https://www.payitlater.com.au" rel="nofollow">visit our website</a> to get started.', 'payitlater_plugin' );
            $this->icon               = plugins_url( 'images/logo.small.png', __FILE__ );
            $this->has_fields         = false;
            $this->supports           = array( 'products', 'refunds' );

            // Load the form fields.
            $this->init_form_fields();
            $this->init_settings();

            // get the merchant ID, and then decide the URLs from there.
            $merchant_id = $this->settings['merchant-id'];
            $domain      = $this->get_server( $merchant_id );
            $api_ver = "v1";
            $url = $domain . "api/" . $api_ver . "/";

            $this->endpoints = array(
                'merchants' => array(
                    'orders' => $url . 'merchants/orders',
                    'limits' => $url . 'merchants/payment-limits'
                ),
                'js'        => $domain . 'payitlater.js'
            );

            // Define user set variables
            $this->title       = $this->settings['title'];
            $this->description = __( 'Credit cards accepted: Visa, Mastercard', 'payitlater_plugin' );

            self::$log_enabled = $this->settings['debug'];

            // Used in popout box on /checkout, including modal.
            $this->queue_checkout_assets();

            // Hooks
            add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
                $this,
                'process_admin_options'
            ) );

            add_action( 'woocommerce_settings_start', array( $this, 'update_payment_limits' ) );

            add_filter( 'woocommerce_thankyou_order_id', array( $this, 'payment_callback' ) );

            // Don't enable if the amount limits are not met
            add_filter( 'woocommerce_available_payment_gateways', array( $this, 'check_cart_within_limits' ), 99, 1 );

            // $this->check_pending_abandoned_orders();
        }


        function get_server( $merchant_id ) {
            $domain = "";
            if ( strpos( $merchant_id, "pil_dev_" ) === 0 ) {
                $domain = "http://app.payitlater.dev/";
            } else if ( strpos( $merchant_id, "pil_live_" ) === 0 ) {
                $domain = "https://app.payitlater.com.au/";
            } else if ( strpos( $merchant_id, "pil_test_" ) === 0 ) {
                $domain = "https://app-test.payitlater.com.au/";
            }

            return $domain;
        }

        function get_environment( $merchant_id ) {
            $env = "";
            if ( strpos( $merchant_id, "pil_dev_" ) === 0 ) {
                $env = "DEV";
            } else if ( strpos( $merchant_id, "pil_live_" ) === 0 ) {
                $env = "LIVE";
            } else if ( strpos( $merchant_id, "pil_test_" ) === 0 ) {
                $env = "TEST";
            }

            return $env;
        }

        function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => __( 'Enable/Disable', 'payitlater_plugin' ),
                    'type'        => 'checkbox',
                    'description' => __( 'PayItLater will not be displayed to your users if this setting is disabled.', 'payitlater_plugin' ),
                    'desc_tip'    => true,
                    'label'       => __( 'Enable PayItLater', 'payitlater_plugin' ),
                    'default'     => 'yes'
                ),
                'title'   => array(
                    'title'       => __( 'Title', 'payitlater_plugin' ),
                    'type'        => 'text',
                    'description' => __( 'The payment method title the user sees during checkout.', 'payitlater_plugin' ),
                    'desc_tip'    => true,
                    'default'     => __( 'PayItLater - Pay With Instalments', 'payitlater_plugin' )
                ),
                'debug'   => array(
                    'title'       => __( 'Debug logging', 'payitlater_plugin' ),
                    'label'       => __( 'Enable debug logging', 'payitlater_plugin' ),
                    'type'        => 'checkbox',
                    'description' => __( 'Logs are in the <code>wc-logs</code> folder.', 'payitlater_plugin' ),
                    'desc_tip'    => true,
                    'default'     => 'no'
                ),

                'keys-heading' => array(
                    'title'       => __( 'Merchant Account Keys', 'woocommerce' ),
                    'type'        => 'title',
                    'description' => __( 'Enter the keys you received in your merchant welcome email below. Be careful not to switch ID and secret key (secret key is longer)', 'payitlater_plugin' ),
                ),
                'merchant-id'  => array(
                    'title'   => __( 'Merchant ID', 'payitlater_plugin' ),
                    'type'    => 'text',
                    'default' => '',
                    'label'   => __( '', 'payitlater_plugin' ),
                ),
                'secret-key'   => array(
                    'title'   => __( 'Secret key', 'payitlater_plugin' ),
                    'type'    => 'text',
                    'default' => '',
                    'label'   => __( 'Your secret key is a long sequence of random characters.', 'payitlater_plugin' ),
                    ''
                ),
                'merchant-cart-min' => array(
                    'type'    => 'hidden',
                    'default' => '',
                    ''
                ),
                'merchant-cart-max' => array(
                    'type'    => 'hidden',
                    'default' => '',
                    ''
                ),
            );
        } // End init_form_fields()

        public function queue_checkout_assets() {
            wp_enqueue_script( "jquery" );

            wp_register_script( 'payitlater_js', plugins_url( 'js/payitlater-page.js', __FILE__ ) );
            wp_enqueue_script( 'payitlater_js' );

            wp_register_style( 'payitlater_css', plugins_url( 'css/payitlater.css', __FILE__ ) );
            wp_enqueue_style( 'payitlater_css' );
        }

        function payment_fields() {
            global $woocommerce;

            $ordertotal = $woocommerce->cart->total;

            // Check which options are available for order amount
            $validoptions = $this->check_payment_options_for_amount( $ordertotal );


            if ( count( $validoptions ) == 0 ) {
                echo "Orders of this value cannot be processed by PayItLater!";

                return false;
            }

            // Payment form
            $pil_environment = $this->get_environment( $this->settings['merchant-id'] );
            ?>


            <?php include( "checkout/checkout-explanation.php" ); ?>

            <?php
        }

        function build_server_order_object( $order ) {
            // Setup order items
            $orderitems = $order->get_items();
            $items      = array();
            if ( count( $orderitems ) ) {
                foreach ( $orderitems as $item ) {

                    // get SKU
                    // After WooCommerce update, needed to fix here.

                    $product = new WC_Product( $item['product_id'] );

                    $items[] = array(
                        'name'     => $item['name'],
                        'sku'      => $product->get_sku(),
                        'quantity' => $item['qty'],
                        'price'    => array(
                            'amount'   => number_format( ( $item['line_subtotal'] / $item['qty'] ), 2, '.', '' ),
                            'currency' => get_woocommerce_currency()
                        )
                    );
                }
            }

            $shippingAddress = array(
                'name'     => $order->shipping_first_name . ' ' . $order->shipping_last_name,
                'address1' => $order->shipping_address_1,
                'address2' => $order->shipping_address_2,
                'suburb'   => $order->shipping_city,
                'postcode' => $order->shipping_postcode
            );

            if(empty($order->shipping_address_1) || empty($order->shipping_postcode) || empty($order->shipping_city)){
                $shippingAddress = array(
                    'name'     => $order->billing_first_name . ' ' . $order->billing_last_name,
                    'address1' => $order->billing_address_1,
                    'address2' => $order->billing_address_2,
                    'suburb'   => $order->billing_city,
                    'postcode' => $order->billing_postcode
                );
            }

            $body = array(
                'consumer'    => array(
                    'mobile'     => $order->billing_phone,
                    'givenNames' => $order->billing_first_name,
                    'surname'    => $order->billing_last_name,
                    'email'      => $order->billing_email
                ),
                'orderDetail' => array(
                    'merchantOrderDate' => time(),
                    'merchantOrderId'   => $order->id,
                    'items'             => $items,
                    'includedTaxes'     => array(
                        'amount'   => number_format( $order->get_cart_tax(), 2, '.', '' ),
                        'currency' => get_woocommerce_currency()
                    ),
                    'shippingAddress'   => $shippingAddress,
                    'billingAddress'    => array(
                        'name'     => $order->billing_first_name . ' ' . $order->billing_last_name,
                        'address1' => $order->billing_address_1,
                        'address2' => $order->billing_address_2,
                        'suburb'   => $order->billing_city,
                        'postcode' => $order->billing_postcode
                    ),
                    'orderAmount'       => array(
                        'amount'   => number_format( $order->get_total(), 2, '.', '' ),
                        'currency' => get_woocommerce_currency()
                    )
                )
            );

            // Check whether to add shipping
            if ( $order->get_shipping_method() ) {
                $body['orderDetail']['shippingCourier'] = $order->get_shipping_method();
                //'shippingPriority' => 'STANDARD', // STANDARD or EXPRESS
                $body['orderDetail']['shippingCost'] = array(
                    'amount'   => number_format( $order->get_total_shipping(), 2, '.', '' ),
                    'currency' => get_woocommerce_currency()
                );
            }

            // Check whether to add discount
            if ( $order->get_total_discount() ) {
                $body['orderDetail']['discountType'] = 'Discount';
                $body['orderDetail']['discount']     = array(
                    'amount'   => '-' . number_format( $order->get_total_discount(), 2, '.', '' ), // Should be negative
                    'currency' => get_woocommerce_currency()
                );
            }

            return $body;
        }

        function get_order_token( $order = false ) {

            $args     = array(
                'headers' => array(
                    'Authorization' => $this->get_authorization_header(),
                    'Content-Type'  => 'application/json'
                ),
                'body'    => json_encode( $this->build_server_order_object( $order ) )
            );
            $response = wp_remote_post( $this->endpoints['merchants']['orders'], $args );
            $body     = json_decode( wp_remote_retrieve_body( $response ) );

            $this->log( 'Order token result: ' . print_r( $body, true ) );

            if ( isset( $body->orderToken ) ) {
                return $body->orderToken;
            } else {
                return false;
            }
        }

        function process_payment( $order_id ) {
            global $woocommerce;
            $ordertotal = $woocommerce->cart->total;

            if ( function_exists( "wc_get_order" ) ) {
                $order = wc_get_order( $order_id );
            } else {
                $order = new WC_Order( $order_id );
            }

            // Get the order token
            $token        = $this->get_order_token( $order );
            $validoptions = $this->check_payment_options_for_amount( $ordertotal );

            if ( count( $validoptions ) == 0 ) {
                // amount is not supported
                $order->add_order_note( __( 'Order amount: $' . number_format( $ordertotal, 2 ) . ' is not supported.', 'payitlater_plugin' ) );
                wc_add_notice( __( 'Unfortunately, an order of $' . number_format( $ordertotal, 2 ) . ' cannot be processed with PayItLater.', 'payitlater_plugin' ), 'error' );

                return array(
                    'result'   => 'failure',
                    'redirect' => $order->get_checkout_payment_url( true )
                );

            } else if ( $token == false ) {
                // Couldn't generate token
                $order->add_order_note( __( 'Unable to generate the order token. Payment couldn\'t proceed.', 'payitlater_plugin' ) );
                wc_add_notice( __( 'Sorry, there was a problem preparing your payment.', 'payitlater_plugin' ), 'error' );

                return array(
                    'result'   => 'failure',
                    'redirect' => $order->get_checkout_payment_url( true )
                );

            } else {
                // Order token successful, save it so we can confirm it later
                update_post_meta( $order_id, '_payitlater_token', $token );

                $redirect = $order->get_checkout_payment_url( true );

                return array(
                    'result'   => 'success',
                    'redirect' => $redirect
                );
            }

        }

        function receipt_page( $order_id ) {
            global $woocommerce;

            if ( function_exists( "wc_get_order" ) ) {
                $order = wc_get_order( $order_id );
            } else {
                $order = new WC_Order( $order_id );
            }

            // Get the order token

            $token = get_post_meta( $order_id, '_payitlater_token', true );

            // Now redirect the user to the URL
            $returnurl = $this->get_return_url( $order );

            $failurl = $order->get_checkout_payment_url( false );

            // Update order status if it isn't already
            $is_pending = false;
            if ( function_exists( "has_status" ) ) {
                $is_pending = $order->has_status( 'pending' );
            } else {
                if ( $order->status == 'pending' ) {
                    $is_pending = true;
                }
            }

            if ( ! $is_pending ) {
                $order->update_status( 'pending' );
            }

            $args = array( 'returnUrl' => $returnurl, 'transactionToken' => $token, 'failUrl' => $failurl );
            ?>


            <?php
            // START SAFARI SESSION FIX
            session_start();
            if(!isset($_GET['iframe-fix'])){

                $merchant_id = $this->settings['merchant-id'];
                $domain = $this->get_server($merchant_id);

                $this_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
                $page_url = $domain . "redirect-catch?to=" . urlencode($this_url . "&iframe-fix=true");
                // die(header("Location:" . $page_url));
                echo "<script>window.location = '" . esc_url_raw($page_url) . "'</script>";
            }

            // END SAFARI SESSION FIX
            ?>


            <script src="<?php echo esc_url_raw($this->endpoints['js']); ?>"></script>
            <script>
                (function () {
                    var payItLaterLoading = setInterval(function () {
                        if (typeof PayItLater == 'undefined') return;
                        clearInterval(payItLaterLoading);
                        PayItLater.display(<?php echo json_encode( $args ); ?>);

                    }, 100);
                })();
            </script>

            <?php
        }


        /**
         * Validate the order status on the Thank You page
         *
         * @param  int $order_id
         *
         * @return  int Order ID as-is
         * @since 1.0.0
         */
        function payment_callback( $order_id ) {
            global $woocommerce;

            if ( function_exists( "wc_get_order" ) ) {
                $order = wc_get_order( $order_id );
            } else {
                $order = new WC_Order( $order_id );
            }

            $token = get_post_meta( $order_id, '_payitlater_token', true );  //update_post_meta( $order_id, '_payitlater_token', $token );
            if ( $token ) {
                $this->log( 'Checking order status for WC Order ID ' . $order_id . ', Order ID ' . $token );

                $response = wp_remote_get( $this->endpoints['merchants']['orders'] . '/' . $token, array(
                    'headers' => array(
                        'Authorization' => $this->get_authorization_header()
                    )
                ) );
                $body     = json_decode( wp_remote_retrieve_body( $response ) );

                $this->log( 'Checking order status result: ' . print_r( $body, true ) );

                //backwards compatibility with WooCommerce 2.1.x
                $is_completed = $is_processing = $is_pending = $is_on_hold = $is_failed = false;

                // Check status of order
                $this->status_received_for_order( $order, $body );

            }

            return $order_id;
        }

        function status_received_for_order( $order, $body ) {
            if ( $body->status == "APPROVED" ) {

                if ( $order->status !== "completed" && $order->status !== "processing" ) {
                    $order->add_order_note( sprintf( __( 'Payment approved by PayItLater. Transaction ID: %s', 'payitlater_plugin' ), $body->id ) );
                    $order->payment_complete( $body->id );
                    woocommerce_empty_cart();
                }
            } elseif ( $body->status == "PENDING" ) {
                if ( $order->status !== "on-hold" ) {
                    $order->add_order_note( sprintf( __( 'Payment is pending approval. Transaction ID: %s', 'payitlater_plugin' ), $body->id ) );
                    // $order->update_status( 'on-hold' );
                    update_post_meta( $order->id, '_transaction_id', $body->id );
                }
            } elseif ( $body->status == "FAILURE" || $body->status == "FAILED" ) {
                if ( $order->status !== "failed" ) {
                    $order->add_order_note( sprintf( __( 'Order has been declined. Transaction ID: %s', 'payitlater_plugin' ), $body->id ) );
                    $order->update_status( 'failed' );
                }
            } elseif ( $body->status == "ABANDONED" ) {
                if ( $order->status !== "cancelled" ) {
                    $order->add_order_note( sprintf( __( 'Order was abandoned by user during checkout.', 'payitlater_plugin' ), $body->id ) );
                    $order->update_status( 'cancelled' );
                }
            } else {
                if ( $order->status !== "pending" ) {
                    $order->add_order_note( sprintf( __( 'Payment %s. Transaction ID: %s', 'payitlater_plugin' ), strtolower( $body->status ), $body->id ) );
                    $order->update_status( 'pending' );
                }
            }
        }

        function get_authorization_header() {

            $token_id   = $this->settings['merchant-id'];
            $secret_key = $this->settings['secret-key'];

            return 'Basic ' . base64_encode( $token_id . ':' . $secret_key );
        }

        function check_payment_options_for_amount( $ordertotal ) {

            $body = array(
                'orderAmount' => array(
                    'amount'   => number_format( $ordertotal, 2, '.', '' ),
                    'currency' => get_woocommerce_currency()
                )
            );

            $args = array(
                'headers' => array(
                    'Authorization' => $this->get_authorization_header(),
                    'Content-Type'  => 'application/json'
                ),
                'body'    => json_encode( $body )
            );

            $response = wp_remote_post( $this->endpoints['merchants']['limits'], $args );
            $body     = json_decode( wp_remote_retrieve_body( $response ) );

            $this->log( 'Check payment options response: ' . print_r( $body, true ) );

            return $body;
        }

        /**
         * Request merchant payment limits and update WC
         *
         * @since 1.0.0
         */
        function update_payment_limits() {
            // Get existing limits
            $settings = get_option( 'woocommerce_payitlater_settings' );

            $this->log( 'Updating payment limits requested from ' . $this->endpoints['merchants']['limits'] );

            $response = wp_remote_get( $this->endpoints['merchants']['limits'], array( 'headers' => array( 'Authorization' => $this->get_authorization_header() ) ) );
            $body     = json_decode( wp_remote_retrieve_body( $response ) );

            $this->log( 'Updating payment limits response: ' . print_r( $body, true ) );

            if ( is_array( $body ) ) {
                foreach ( $body as $paymenttype ) {
                    // Min
                    $settings['merchant-cart-min'] = ( is_numeric( $paymenttype->minimumAmount ) ) ? $paymenttype->minimumAmount : 0;
                    // Max
                    $settings['merchant-cart-max'] = ( is_numeric( $paymenttype->maximumAmount ) ) ? $paymenttype->maximumAmount : 0;
                }
            }
            update_option( 'woocommerce_payitlater_settings', $settings );
            $this->init_settings();
        }

        public function notify_order_shipped( $order_id ) {
            $payment_method = get_post_meta( $order_id, '_payment_method', true );
            if ( $payment_method != "payitlater" ) {
                return;
            }

            if ( function_exists( "wc_get_order" ) ) {
                $order = wc_get_order( $order_id );
            } else {
                $order = new WC_Order( $order_id );
            }

            // Skip if shipping not required
            if ( ! $order->needs_shipping_address() ) {
                return;
            }

            $token = get_post_meta( $order_id, '_payitlater_token', true );

            $body = array(
                'trackingNumber' => get_post_meta( $order_id, '_tracking_number', true ),
                'courier'        => $order->get_shipping_method()
            );

            $args = array(
                'headers' => array(
                    'Authorization' => $this->get_authorization_header(),
                    'Content-Type'  => 'application/json'
                ),
                'body'    => json_encode( $body )
            );

            $this->log( 'Shipping notification request: ' . print_r( $args, true ) );

            $response     = wp_remote_post( $this->endpoints['merchants']['orders'] . '/' . $token . '/shipping_confirmation', $args );
            $responsecode = wp_remote_retrieve_response_code( $response );

            $this->log( 'Shipping notification response: ' . print_r( $response, true ) );

            if ( $responsecode == 200 ) {
                $order->add_order_note( __( 'PayItLater received shipping information.', 'payitlater_plugin' ) );
            } else {
                $order->add_order_note( sprintf( __( 'Unable to send PayItLater shipping information. Response code: %s.', 'payitlater_plugin' ), $responsecode ) );
            }
        }

        public function can_refund_order( $order ) {
            return $order && $order->get_transaction_id();
        }

        public function process_refund( $order_id, $amount = null, $reason = '' ) {

            if ( function_exists( "wc_get_order" ) ) {
                $order = wc_get_order( $order_id );
            } else {
                $order = new WC_Order( $order_id );
            }

            if ( ! $this->can_refund_order( $order ) ) {
                $this->log( 'Refund Failed: No transaction ID' );

                return false;
            }

            $body = array(
                'amount'               => array(
                    'amount'   => '-' . number_format( $amount, 2, '.', '' ),
                    'currency' => $order->get_order_currency()
                ),
                'merchantRefundId'     => '',
                'merchantRefundReason' => $reason
            );

            $args = array(
                'headers' => array(
                    'Authorization' => $this->get_authorization_header(),
                    'Content-Type'  => 'application/json'
                ),
                'body'    => json_encode( $body )
            );

            $this->log( 'Refund request: ' . print_r( $args, true ) );

            $token = get_post_meta($order_id, '_payitlater_token', true);

            $response = wp_remote_post( $this->endpoints['merchants']['orders'] . '/' . $token . '/refund_request', $args );

            $body         = json_decode( wp_remote_retrieve_body( $response ) );
            $responsecode = wp_remote_retrieve_response_code( $response );

            $this->log( 'Refund response: ' . print_r( $body, true ) );

            $this->log("Refund response code " . $responsecode);

            if ( $responsecode == 201 || $responsecode == 200 ) {
                $order->add_order_note( sprintf( __( 'Refund of $%s sent to PayItLater.', 'payitlater_plugin' ), $amount ) );

                return true;
            } else {
                if ( isset( $body->message ) ) {
                    $order->add_order_note( sprintf( __( 'Refund couldn\'t be processed by PayItLater: %s', 'payitlater_plugin' ), $body->message ) );
                } else {
                    $order->add_order_note( sprintf( __( 'There was an error sending the refund to PayItlater, please try again later.', 'payitlater_plugin' ) ) );
                }
            }

            return false;
        }

        function check_pending_abandoned_orders() {
            $this->log('Checking pending/abandoned.');
            $onhold_orders = get_posts( array( 'post_type' => 'shop_order', 'post_status' => 'wc-on-hold' ) );

            if(count($onhold_orders) == 0){
                $this->log("No onhold.");
            }
            foreach ( $onhold_orders as $onhold_order ) {

                $this->log('On hold block checking ' . $onhold_order->ID);
                $order = new WC_Order( $onhold_order->ID );

                if($order === false){
                    continue;
                }

                if($onhold_order->post_status === 'wc-processing'){
                    continue;
                }

                $transaction_id = get_post_meta( $onhold_order->ID, '_payitlater_token', true );
                if(!$transaction_id) {
                    continue;
                }

                // make sure customer finalised order with us.
                $gateway_used = get_post_meta($onhold_order->ID, '_payment_method', true);
                if($gateway_used != "payitlater"){
                    continue;
                }
                $this->log($onhold_order->ID . " survived pre-checks");

                $this->log( 'Checking abandoned order for WC Order ID ' . $onhold_order->ID . ',  Order ID ' . $transaction_id );

                $response = wp_remote_get( $this->endpoints['merchants']['orders'] . '/' . $transaction_id, array(
                    'headers' => array(
                        'Authorization' => $this->get_authorization_header()
                    )
                ) );
                $body     = json_decode( wp_remote_retrieve_body( $response ) );

                $this->log( 'Checking pending order result: ' . print_r( $body, true ) );


                // Check status of order
                $this->status_received_for_order( $order, $body );

            }

            // Get PENDING orders that may have been abandoned, or browser window closed after approved
            $pending_orders = get_posts( array( 'post_type' => 'shop_order', 'post_status' => 'wc-pending' ) );

            if (count($pending_orders) == 0) {
                $this->log("No pending.");
            }
            foreach ( $pending_orders as $pending_order ) {

                $this->log('pending block checking ' . $pending_order->ID);
                $order = new WC_Order( $pending_order->ID );

                if ($order === false) {
                    continue;
                }
                $payitlater_token = get_post_meta( $pending_order->ID, '_payitlater_token', true );
                if ( ! $payitlater_token ) {
                    continue;
                }

                if ($onhold_order->post_status === 'wc-processing') {
                    continue;
                }
                $gateway_used = get_post_meta($pending_order->ID, '_payment_method', true);
                if ($gateway_used != "payitlater") {
                    continue;
                }

                $transaction_id = get_post_meta($pending_order->ID, '_payitlater_token', true);
                if (!$transaction_id) {
                    continue;
                }
                $this->log($pending_order->ID . " survived pre-checks");

                $this->log( 'Checking pending order for WC Order ID ' . $pending_order->ID . ', PayItLater Token ' . $payitlater_token );

                $response = wp_remote_get( $this->endpoints['merchants']['orders'] . '/' . $payitlater_token, array(
                    'headers' => array(
                        'Authorization' => $this->get_authorization_header()
                    )
                ) );
                $body     = json_decode( wp_remote_retrieve_body( $response ) );

                $this->log( 'Checking abandoned order result: ' . print_r( $body, true ) );

                $this->status_received_for_order( $order, $body );

            }

        }

        function check_cart_within_limits( $gateways ) {

            global $woocommerce;
            $total   = $woocommerce->cart->total;

            if(method_exists($woocommerce->customer, 'get_country')){
                $country = $woocommerce->customer->get_country();
            } else {
                $country = WC()->customer ? WC()->customer->get_billing_country() : false;
            }

            if ( $country != "" ) {
                if ( $country != "AU" ) {
                    unset( $gateways['payitlater'] );

                    return $gateways;
                }
            }
            $inside_limit = false;
            if(array_key_exists("merchant-cart-min", $this->settings)){
                $inside_limit = ( $total >= $this->settings['merchant-cart-min'] && $total <= $this->settings['merchant-cart-max'] );

            }

            if ( ! $inside_limit ) {
                unset( $gateways['payitlater'] );
            }

            return $gateways;
        }

        /**
         * Logging method
         *
         * @param  string $message
         */
        public static function log( $message ) {
            if ( self::$log_enabled ) {
                if ( empty( self::$log ) ) {
                    self::$log = new WC_Logger();
                }
                self::$log->add( 'PayItLater', $message );
            }
        }

    }

    function add_payitlater_gateway( $methods ) {
        $methods[] = 'WC_Gateway_PayItLater';

        return $methods;
    }

    add_filter( 'woocommerce_payment_gateways', 'add_payitlater_gateway' );

    /**
     * Call the cron task related methods in the gateway
     *
     * @since 1.0.0
     **/
    function payitlater_do_cron_jobs() {
        $gateway = WC_Gateway_PayItLater::instance();

        $gateway->log( "Running cron jobs." );

        $gateway->check_pending_abandoned_orders();
    }

    add_action( 'payitlater_do_cron_jobs', 'payitlater_do_cron_jobs' );

    /**
     * Call the notify_order_shipped method in the gateway
     *
     * @param int $order_id
     *
     * @since 1.0.0
     **/
    function payitlater_notify_order_shipped( $order_id ) {
        $gateway = WC_Gateway_PayItLater::instance();
        $gateway->notify_order_shipped( $order_id );
    }

    add_action( 'woocommerce_order_status_completed', 'payitlater_notify_order_shipped', 10, 1 );

}
/* WP-Cron activation and schedule setup */
function payitlater_create_wpcronjob() {
    $timestamp = wp_next_scheduled( 'payitlater_do_cron_jobs' );
    if ( $timestamp == false ) {
        wp_schedule_event( time(), 'fifteenminutes', 'payitlater_do_cron_jobs' );
    }
}

register_activation_hook( __FILE__, 'payitlater_create_wpcronjob' );

function payitlater_delete_wpcronjob() {
    wp_clear_scheduled_hook( 'payitlater_do_cron_jobs' );
}

register_deactivation_hook( __FILE__, 'payitlater_delete_wpcronjob' );

function payitlater_add_fifteen_minute_schedule( $schedules ) {
    $schedules['fifteenminutes'] = array(
        'interval' => 15 * 60,
        'display'  => __( 'Every 15 minutes', 'payitlater_plugin' )
    );

    return $schedules;
}

add_filter( 'cron_schedules', 'payitlater_add_fifteen_minute_schedule' );

function payitlater_modal() {
    wp_enqueue_script( "jquery" );


    wp_register_style( 'payitlater_css', plugins_url( 'css/payitlater.css', __FILE__ ) );
    wp_enqueue_style( 'payitlater_css' );

    wp_register_script( 'payitlater_js', plugins_url( 'js/payitlater-page.js', __FILE__ ) );
    wp_enqueue_script( 'payitlater_js' );

    include_once("checkout/modal.php");
}

add_action('wp_footer', 'payitlater_modal');

function payitlater_text() {

    $settings = get_option( 'woocommerce_payitlater_settings' );


    if($settings['enabled'] == "yes"){
        // get instalment price.
        $id = get_the_ID();

        $_product = wc_get_product( $id );

        $price = $_product->get_price();

        $priceFront35 = number_format( ( $price * 0.35 ), 2 );
        $instalment = number_format( ( ( $price - $priceFront35 ) / 3 ) , 2 );

        include_once( "checkout/get-it-now-callout.php" );
    }


}

add_action( 'woocommerce_single_product_summary', 'payitlater_text', 15 );


?>

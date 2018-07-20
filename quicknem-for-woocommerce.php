<?php
/**
 * Plugin Name: Quicknem for WooCommerce
 * Plugin URI: https://quicknem.com/
 * Description:
 * Author: Fumito MIZUNO
 * Author URI: https://ounziw.com/
 * Version: 0.8
 * Text Domain: quicknem-for-woocommerce
 * Domain Path:
 *
 * Copyright: (c) 2018 Fumito MIZUNO
 *
 *
 * License: GNU General Public License v2.0 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 *
 */

defined( 'ABSPATH' ) or exit;

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    return;
}

/**
 * @param $gateways
 * @return array
 */
function quicknem_wc_add_to_gateways( $gateways ) {
    $gateways[] = 'Quicknem_WC_Gateway';
    return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'quicknem_wc_add_to_gateways' );


/**
 * @param $links
 * @return array
 */
function quicknem_wc_gateway_plugin_links( $links ) {

    $plugin_links = array(
        '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=quicknem_wc' ) . '">' . __( 'Configure', 'quicknem-for-woocommerce' ) . '</a>'
    );

    return array_merge( $plugin_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'quicknem_wc_gateway_plugin_links' );


/**
 *
 */
add_action( 'plugins_loaded', 'quicknem_wc_gateway_init', 11 );

/**
 *
 */
function quicknem_wc_gateway_init() {

    class Quicknem_WC_Gateway extends WC_Payment_Gateway {

        public function __construct() {

            $this->id                 = 'quicknem_wc';
            $this->icon               = apply_filters('quicknem_wc_icon', '');
            $this->has_fields         = false;
            $this->method_title       = __( 'Quicknem', 'quicknem-for-woocommerce' );
            $this->method_description = __( 'Allows NEM payments using Quicknem.', 'quicknem-for-woocommerce' );
            $this->nonce = wp_create_nonce( 'quicknem' );
            $this->init_form_fields();
            $this->init_settings();

            $this->title =  __( 'Quicknem', 'quicknem-for-woocommerce' );
            $this->url = $this->get_option( 'url' );
            $this->description = $this->get_option( 'description' );
            $this->quicknemid = $this->get_option( 'quicknemid' );
            $this->apikey = $this->get_option( 'apikey' );

            add_action( 'woocommerce_api_success', array( $this, 'success' ) );
            add_action( 'woocommerce_api_cancel', array( $this, 'cancel' ) );
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        }

        /**
         *
         */
        public function init_form_fields() {

            $this->form_fields = apply_filters( 'quicknem_wc_form_fields', array(

                'enabled' => array(
                    'title'   => __( 'Enable/Disable', 'quicknem-for-woocommerce' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Enable Quicknem Payment', 'quicknem-for-woocommerce' ),
                    'default' => 'yes'
                ),

                'url' => array(
                    'title'       => __( 'URL', 'quicknem-for-woocommerce' ),
                    'type'        => 'text',
                    'description' => __( 'The URL for Quicknem API Endpoint', 'quicknem-for-woocommerce' ),
                    'default'     => 'https://quicknem.com/v1',
                    'desc_tip'    => true,
                ),

                'description' => array(
                    'title'       => __( 'Description', 'quicknem-for-woocommerce' ),
                    'type'        => 'textarea',
                    'description' => __( 'Payment method description that the customer will see on your checkout.', 'quicknem-for-woocommerce' ),
                    'default'     => __( 'Please remit payment to Store Name upon pickup or delivery.', 'quicknem-for-woocommerce' ),
                    'desc_tip'    => true,
                ),

                'quicknemid' => array(
                    'title'       => __( 'User ID', 'quicknem-for-woocommerce' ),
                    'type'        => 'text',
                    'description' => __( 'Your ID for Quicknem', 'quicknem-for-woocommerce' ),
                    'default'     => '1',
                    'desc_tip'    => true,
                ),
                'apikey' => array(
                    'title'       => __( 'API KEY', 'quicknem-for-woocommerce' ),
                    'type'        => 'text',
                    'description' => __( 'API KEY for Quicknem', 'quicknem-for-woocommerce' ),
                    'default'     => '',
                    'desc_tip'    => true,
                ),
            ) );
        }

        /**
         * @param $order_id
         * @return array
         */
        public function process_payment( $order_id ) {

            $order = wc_get_order( $order_id );
            $uid = $this->quicknemid;
            $jpy = $order->get_total();
            $url = $this->url . '?id=' . $uid . '&jpy=' . $jpy . '&transid=' . $order_id . '&apitoken=' . $this->nonce;

            return array(
                'result' => 'success',
                'redirect' => $url
            );

        }

        /**
         *
         */
        public function success() {

            $nonce = @$_GET['apitoken'];
            $iv = str_pad($_GET['payid'], 16, 0, STR_PAD_LEFT);
            $apitoken = openssl_decrypt(hex2bin($nonce), 'aes-256-cbc', $this->apikey, OPENSSL_RAW_DATA , $iv);

            if ( ! wp_verify_nonce( $apitoken, 'quicknem' ) ) {
                die( 'Security check' );
            } else {
                $order = wc_get_order( $_GET['transid'] );
                $order->payment_complete();
                $order->reduce_order_stock();

                wp_safe_redirect($this->get_return_url( $order ));
                exit;

            }
        }

        /**
         *
         */
        public function cancel() {

            $nonce = @$_GET['apitoken'];
            $iv = str_pad($_GET['payid'], 16, 0, STR_PAD_LEFT);
            $apitoken = openssl_decrypt(hex2bin($nonce), 'aes-256-cbc', $this->apikey, OPENSSL_RAW_DATA , $iv);
            if ( ! wp_verify_nonce( $apitoken, 'quicknem' ) ) {

                die( 'Security check' );
            } else {
                $cancel_url = !empty($this->cancel_page_id) ? get_permalink($this->cancel_page_id) : wc_get_cart_url();
                wp_safe_redirect( $cancel_url );
                exit;
            }


        }

    }
}

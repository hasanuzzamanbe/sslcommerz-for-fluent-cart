<?php
/**
 * Plugin Name: Bangladeshi Payment Gateway SSLCommerz for FluentCart
 * Plugin URI: https://wpminers.com/plugins
 * Description: Accept payments via SSL Commerz in FluentCart - supports one-time payments, refunds, and multiple payment methods
 * Version: 1.0.0
 * Author: WPMiners
 * Author URI: https://wpminers.com
 * Text Domain: sslcommerz-for-fluent-cart
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined('ABSPATH') or exit;

// Define plugin constants
define('SSLCOMMERZ_FC_VERSION', '1.0.0');
define('SSLCOMMERZ_FC_PLUGIN_FILE', __FILE__);
define('SSLCOMMERZ_FC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SSLCOMMERZ_FC_PLUGIN_URL', plugin_dir_url(__FILE__));


/**
 * Check if FluentCart is active
 */
function sslcommerz_fc_check_dependencies() {
    if (!defined('FLUENTCART_VERSION')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('Bangladeshi Payment Gateway SSLCommerz for FluentCart', 'sslcommerz-for-fluent-cart'); ?></strong> 
                    <?php esc_html_e('requires FluentCart to be installed and activated.', 'sslcommerz-for-fluent-cart'); ?>
                </p>
            </div>
            <?php
        });
        return false;
    } else if (version_compare(FLUENTCART_VERSION, '1.2.5', '<')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('SSL Commerz for FluentCart', 'sslcommerz-for-fluent-cart'); ?></strong> 
                    <?php esc_html_e('requires FluentCart version 1.2.5 or higher.', 'sslcommerz-for-fluent-cart'); ?>
                </p>
            </div>
            <?php
        });
        return false;
    }
    return true;
}

/**
 * Initialize the plugin
 */
add_action('plugins_loaded', function() {
    if (!sslcommerz_fc_check_dependencies()) {
        return;
    }

    // Register autoloader
    spl_autoload_register(function ($class) {
        $prefix = 'SslcommerzFluentCart\\';
        $base_dir = SSLCOMMERZ_FC_PLUGIN_DIR . 'includes/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    });

    // Register the payment gateway
    add_action('fluent_cart/register_payment_methods', function($data) {
        \SslcommerzFluentCart\SslcommerzGateway::register();
    }, 10);

}, 20);

/**
 * Activation hook
 */
register_activation_hook(__FILE__, function() {
    if (!sslcommerz_fc_check_dependencies()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html(__('SSL Commerz for FluentCart requires FluentCart to be installed and activated.', 'sslcommerz-for-fluent-cart')),
            esc_html(__('Plugin Activation Error', 'sslcommerz-for-fluent-cart')),
            ['back_link' => true]
        );
    }
});


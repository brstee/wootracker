<?php
/**
 * Plugin Name: WooCommerce Real-time Analytics
 * Description: Real-time analytics for WooCommerce with visitor, add to cart, checkout, and purchase tracking.
 * Version: 1.0.0
 * Author: IT Department
 * Text Domain: wc-realtime-analytics
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.0
 * WC requires at least: 4.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WCRA_VERSION', '1.0.0');
define('WCRA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCRA_PLUGIN_URL', plugin_dir_url(__FILE__));

// Check if WooCommerce is active
function wcra_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'wcra_woocommerce_missing_notice');
        return false;
    }
    return true;
}

// Display admin notice when WooCommerce is missing
function wcra_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php _e('WooCommerce Real-time Analytics requires WooCommerce to be installed and activated.', 'wc-realtime-analytics'); ?></p>
    </div>
    <?php
}

// Initialize the plugin
function wcra_init() {
    // Check if WooCommerce is active
    if (!wcra_check_woocommerce()) {
        return;
    }
    
    // Include required files
    require_once WCRA_PLUGIN_DIR . 'includes/class-wc-realtime-db.php';
    require_once WCRA_PLUGIN_DIR . 'includes/class-wc-realtime-geo.php';
    require_once WCRA_PLUGIN_DIR . 'includes/class-wc-realtime-pusher.php';
    require_once WCRA_PLUGIN_DIR . 'includes/class-wc-realtime-tracker.php';
    require_once WCRA_PLUGIN_DIR . 'includes/class-wc-realtime-admin.php';
    
    // Initialize database handler
    $db = new WC_Realtime_DB();
    
    // Create tables on plugin initialization if they don't exist
    if (!$db->tables_exist()) {
        $db->create_tables();
    }
    
    // Initialize geolocation handler
    $geo = new WC_Realtime_Geo();
    
    // Initialize Pusher handler
    $pusher = new WC_Realtime_Pusher();
    
    // Initialize tracker
    $tracker = new WC_Realtime_Tracker($db, $pusher, $geo);
    
    // Initialize admin interface
    $admin = new WC_Realtime_Admin($db, $pusher);
}
add_action('plugins_loaded', 'wcra_init');

// Activation hook - create database tables
function wcra_activate() {
    // Include database class
    require_once plugin_dir_path(__FILE__) . 'includes/class-wc-realtime-db.php';
    
    // Initialize database handler
    $db = new WC_Realtime_DB();
    
    // Create tables
    $db->create_tables();
    
    // Set default Pusher values (empty for now)
    if (empty(get_option('wc_realtime_pusher_app_id'))) {
        update_option('wc_realtime_pusher_app_id', '1954811');
        update_option('wc_realtime_pusher_key', '893a08ea3a234d35155d');
        update_option('wc_realtime_pusher_secret', '69d8546ca8f57562bf86');
        update_option('wc_realtime_pusher_cluster', 'mt1');
    }
}
register_activation_hook(__FILE__, 'wcra_activate');

// Add settings link on plugin page
function wcra_settings_link($links) {
    $settings_link = '<a href="admin.php?page=wc-realtime-analytics-settings">' . __('Settings', 'wc-realtime-analytics') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
$plugin = plugin_basename(__FILE__);
add_filter("plugin_action_links_$plugin", 'wcra_settings_link');
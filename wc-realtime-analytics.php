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
define('WCRA_BASENAME', plugin_basename(__FILE__));

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
        <p><?php esc_html_e('WooCommerce Real-time Analytics requires WooCommerce to be installed and activated.', 'wc-realtime-analytics'); ?></p>
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
    $required_files = array(
        'includes/class-wc-realtime-db.php',
        'includes/class-wc-realtime-geo.php',
        'includes/class-wc-realtime-pusher.php',
        'includes/class-wc-realtime-tracker.php',
        'includes/class-wc-realtime-data.php',
        'includes/class-wc-realtime-admin.php'
    );
    
    foreach ($required_files as $file) {
        $file_path = WCRA_PLUGIN_DIR . $file;
        if (file_exists($file_path)) {
            require_once $file_path;
        } else {
            // Log error for missing file
            error_log(sprintf('WooCommerce Real-time Analytics: Required file %s not found.', $file_path));
            return;
        }
    }
    
    try {
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
        
        // Initialize data handler
        $data = new WC_Realtime_Data($db);
        
        // Initialize tracker
        $tracker = new WC_Realtime_Tracker($db, $pusher, $geo);
        
        // Initialize admin interface
        $admin = new WC_Realtime_Admin($db, $pusher, $data);
    } catch (Exception $e) {
        // Log initialization error
        error_log('WooCommerce Real-time Analytics initialization error: ' . $e->getMessage());
    }
    
    // Load text domain for translations
    add_action('plugins_loaded', 'wcra_load_textdomain');
}

// Load plugin text domain
function wcra_load_textdomain() {
    load_plugin_textdomain(
        'wc-realtime-analytics', 
        false, 
        dirname(WCRA_BASENAME) . '/languages'
    );
}

// Initialize the plugin on plugins_loaded
add_action('plugins_loaded', 'wcra_init');

// Activation hook - create database tables and initialize options
function wcra_activate() {
    // Check requirements
    if (!wcra_check_requirements()) {
        // Deactivate plugin if requirements not met
        deactivate_plugins(WCRA_BASENAME);
        wp_die(
            esc_html__('WooCommerce Real-time Analytics requires WordPress 5.0+ and PHP 7.0+.', 'wc-realtime-analytics'),
            esc_html__('Plugin Activation Error', 'wc-realtime-analytics'),
            array('back_link' => true)
        );
        return;
    }
    
    // Include database class
    require_once WCRA_PLUGIN_DIR . 'includes/class-wc-realtime-db.php';
    
    // Initialize database handler
    $db = new WC_Realtime_DB();
    
    // Create tables
    $db->create_tables();
    
    // Set default Pusher values if not already set
    $default_options = array(
        'wc_realtime_pusher_app_id' => '',
        'wc_realtime_pusher_key' => '',
        'wc_realtime_pusher_secret' => '',
        'wc_realtime_pusher_cluster' => 'mt1'
    );
    
    foreach ($default_options as $option_name => $default_value) {
        if (get_option($option_name) === false) {  // Only if option doesn't exist
            add_option($option_name, $default_value);
        }
    }
    
    // Clear any cached data
    wcra_clear_cache();
    
    // Flush rewrite rules
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'wcra_activate');

// Check plugin requirements
function wcra_check_requirements() {
    // Check WordPress version
    if (version_compare(get_bloginfo('version'), '5.0', '<')) {
        return false;
    }
    
    // Check PHP version
    if (version_compare(PHP_VERSION, '7.0', '<')) {
        return false;
    }
    
    return true;
}

// Deactivation hook
function wcra_deactivate() {
    // Clear any scheduled events
    wp_clear_scheduled_hook('wcra_daily_cleanup');
    
    // Clear any cached data
    wcra_clear_cache();
    
    // Flush rewrite rules
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'wcra_deactivate');

// Clear plugin cache data
function wcra_clear_cache() {
    // Clear any transients
    delete_transient('wcra_daily_stats');
    delete_transient('wcra_weekly_stats');
    delete_transient('wcra_monthly_stats');
}

// Uninstall hook
function wcra_uninstall() {
    // If uninstall not called from WordPress, exit
    if (!defined('WP_UNINSTALL_PLUGIN')) {
        exit;
    }
    
    // Remove plugin options
    $options_to_delete = array(
        'wc_realtime_pusher_app_id',
        'wc_realtime_pusher_key',
        'wc_realtime_pusher_secret',
        'wc_realtime_pusher_cluster'
    );
    
    foreach ($options_to_delete as $option) {
        delete_option($option);
    }
    
    // Drop plugin tables if needed - COMMENTED OUT FOR SAFETY
    // Uncomment the following code if you want to remove all data when uninstalling
    /*
    global $wpdb;
    
    // Include database class to get table names
    require_once plugin_dir_path(__FILE__) . 'includes/class-wc-realtime-db.php';
    
    // Initialize database handler to get table names
    $db = new WC_Realtime_DB();
    
    // Get table names from the class properties - this requires modifying the DB class
    // to expose table names or creating a method to return them
    $tables = array(
        $wpdb->prefix . 'wc_realtime_events',
        $wpdb->prefix . 'wc_realtime_daily',
        $wpdb->prefix . 'wc_realtime_products'
    );
    
    // Drop tables
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }
    */
    
    // Clear cache
    wcra_clear_cache();
}
register_uninstall_hook(__FILE__, 'wcra_uninstall');

// Add settings link on plugin page
function wcra_settings_link($links) {
    $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=wc-realtime-analytics-settings')) . '">' . 
                     esc_html__('Settings', 'wc-realtime-analytics') . '</a>';
                    
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . WCRA_BASENAME, 'wcra_settings_link');

// Schedule daily cleanup event
function wcra_schedule_events() {
    if (!wp_next_scheduled('wcra_daily_cleanup')) {
        wp_schedule_event(time(), 'daily', 'wcra_daily_cleanup');
    }
}
add_action('wp', 'wcra_schedule_events');

// Daily cleanup function
function wcra_do_daily_cleanup() {
    // Include database class
    require_once WCRA_PLUGIN_DIR . 'includes/class-wc-realtime-db.php';
    
    // Initialize database handler
    $db = new WC_Realtime_DB();
    
    // Cleanup old events data (default: keep 30 days)
    $days_to_keep = apply_filters('wcra_events_days_to_keep', 30);
    $db->cleanup_events_data($days_to_keep);
    
    // Clear any expired transients
    wcra_clear_cache();
}
add_action('wcra_daily_cleanup', 'wcra_do_daily_cleanup');
<?php
/**
 * Class WC_Realtime_Tracker
 * 
 * Handles tracking of visitor actions for WooCommerce Real-time Analytics
 * Works with cached environments (LiteSpeed Cache + Cloudflare)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WC_Realtime_Tracker {
    /**
     * Database handler
     *
     * @var WC_Realtime_DB
     */
    private $db;
    
    /**
     * Pusher handler
     *
     * @var WC_Realtime_Pusher
     */
    private $pusher;
    
    /**
     * Geolocation handler
     *
     * @var WC_Realtime_Geo
     */
    private $geo;
    
    /**
     * Flag to ensure checkout is only tracked once per page load
     * 
     * @var boolean
     */
    private $checkout_tracked = false;
    
    /**
     * Constructor
     *
     * @param WC_Realtime_DB $db Database handler
     * @param WC_Realtime_Pusher $pusher Pusher handler
     * @param WC_Realtime_Geo $geo Geolocation handler
     */
    public function __construct($db, $pusher, $geo) {
        $this->db = $db;
        $this->pusher = $pusher;
        $this->geo = $geo;
        
        // Initialize tracking methods
        $this->init();
    }
    
    /**
     * Initialize tracking
     */
    public function init() {
        // Client-side tracking (works with cache)
        add_action('wp_enqueue_scripts', array($this, 'enqueue_tracking_scripts'));
        
        // AJAX handlers for client-side tracking
        add_action('wp_ajax_wc_realtime_track', array($this, 'ajax_track_event'));
        add_action('wp_ajax_nopriv_wc_realtime_track', array($this, 'ajax_track_event'));
        
        // DISABLE server-side tracking for add_to_cart as it's handled by client-side
        // add_action('woocommerce_add_to_cart', array($this, 'track_add_to_cart'), 10, 6);
        
        // Track checkout page visits directly (more reliable than button clicks)
        // Use a late priority to ensure it only runs once
        add_action('wp', array($this, 'track_checkout_page_visit'), 99);
        
        // Purchase tracking should stay server-side
        add_action('woocommerce_thankyou', array($this, 'track_purchase'));
        
        // Make sure our tracking endpoint doesn't get cached
        add_action('litespeed_cache_api_purge', array($this, 'exclude_from_litespeed_cache'));
        add_filter('litespeed_cache_optimize_js_excludes', array($this, 'exclude_tracking_js_from_optimization'));
    }
    
    /**
     * Enqueue tracking scripts
     */
    public function enqueue_tracking_scripts() {
        // Register Pusher script
        wp_register_script(
            'pusher-js',
            'https://js.pusher.com/7.0/pusher.min.js',
            array(),
            '7.0',
            true
        );
        
        // Register and enqueue tracking script
        wp_enqueue_script(
            'wc-realtime-tracking',
            plugin_dir_url(dirname(__FILE__)) . 'assets/js/tracking.js',
            array('jquery', 'pusher-js'),
            WCRA_VERSION,
            true
        );
        
        // Generate a nonce specifically for tracking
        $tracking_nonce = wp_create_nonce('wc_realtime_tracking_nonce');
        
        // Get current product information if on a product page
        $product_id = 0;
        $product_name = '';
        
        if (is_product()) {
            global $product;
            if ($product && method_exists($product, 'get_id')) {
                $product_id = $product->get_id();
                $product_name = html_entity_decode($product->get_name(), ENT_QUOTES, 'UTF-8');
            }
        }
        
        // Pass configuration to JavaScript
        wp_localize_script(
            'wc-realtime-tracking',
            'wcRealtimeConfig',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => $tracking_nonce,
                'pusher_key' => $this->pusher->get_key(),
                'pusher_cluster' => $this->pusher->get_cluster(),
                'is_product' => is_product(),
                'product_id' => $product_id,
                'product_name' => $product_name,
                'session_id' => $this->get_or_create_session_id(),
                'is_checkout' => is_checkout()
            )
        );
    }
    
    /**
     * Track checkout page visit
     * This directly tracks when a user lands on the checkout page
     */
    public function track_checkout_page_visit() {
        // Prevent multiple tracking in the same page load - this is critical
        static $already_tracked = false;
        if ($already_tracked) {
            return;
        }
        
        // Only track on the checkout page
        if (!is_checkout()) {
            return;
        }
        
        // Skip tracking on AJAX requests and form submissions (to avoid duplicate tracking)
        if ($this->is_ajax_request() || !empty($_POST)) {
            return;
        }
        
        // Check if this is a payment callback or thank you page
        if (is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('order-received') || is_wc_endpoint_url('thankyou')) {
            return;
        }
        
        // Ensure WooCommerce is fully loaded
        if (!function_exists('WC') || !WC()->cart) {
            return;
        }
        
        // Get session ID
        $session_id = $this->get_or_create_session_id();
        
        // Check if already tracked in this session using cookies
        $cookie_name = 'wc_checkout_tracked';
        if (isset($_COOKIE[$cookie_name]) && $_COOKIE[$cookie_name] === $session_id) {
            return;
        }
        
        // Get IP address
        $ip_address = $this->get_client_ip();
        
        // Check for duplicate event more stringently - look for checkout events from same session in last 10 minutes
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_realtime_events';
        $time_threshold = date('Y-m-d H:i:s', strtotime('-10 minutes'));
        
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} 
            WHERE event_type = 'checkout' 
            AND session_id = %s 
            AND created_at >= %s",
            $session_id,
            $time_threshold
        ));
        
        if ($exists > 0) {
            return;
        }
        
        // This is important - mark as already tracked to prevent duplication
        $already_tracked = true;
        
        // Get country information
        $geo_data = $this->geo->get_country_from_ip($ip_address);
        
        // Get cart data
        $cart = WC()->cart;
        $items = array();
        
        if ($cart) {
            foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
                $product = $cart_item['data'];
                if ($product) {
                    $items[] = array(
                        'product_id' => $product->get_id(),
                        'name' => html_entity_decode($product->get_name(), ENT_QUOTES, 'UTF-8'),
                        'quantity' => $cart_item['quantity'],
                        'price' => $product->get_price()
                    );
                }
            }
        }
        
        // Prepare event data
        $event_data = array(
            'session_id' => $session_id,
            'user_id' => get_current_user_id(),
            'ip_address' => $ip_address,
            'country_code' => isset($geo_data['country_code']) ? sanitize_text_field($geo_data['country_code']) : '',
            'country_name' => isset($geo_data['country_name']) ? sanitize_text_field($geo_data['country_name']) : '',
            'cart_total' => ($cart ? $cart->get_cart_contents_total() : 0),
            'items' => $items,
            'items_count' => count($items)
        );
        
        // First save general checkout event (without specific product)
        $event_id = $this->db->save_event('checkout', $event_data);
        
        if (!$event_id) {
            return; // If primary event fails, don't proceed
        }
        
        // Set httponly cookie to prevent duplicate tracking - use session ID as value
        $secure = is_ssl();
        setcookie($cookie_name, $session_id, time() + 3600, COOKIEPATH, COOKIE_DOMAIN, $secure, true);
        
        // Send event via Pusher
        if ($this->pusher->is_configured()) {
            $this->pusher->trigger('wc-analytics', 'checkout', $event_data);
        }
    }
    
    /**
     * AJAX handler for tracking events
     */
    public function ajax_track_event() {
        // Verify nonce with specific action name
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'wc_realtime_tracking_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'), 403);
            exit;
        }
        
        // Get event type and sanitize
        $event_type = isset($_POST['event_type']) ? sanitize_text_field($_POST['event_type']) : '';
        
        // Check if event type is valid
        $valid_event_types = array('visitor', 'add_to_cart', 'checkout', 'purchase');
        if (!in_array($event_type, $valid_event_types, true)) {
            wp_send_json_error(array('message' => 'Invalid event type'), 400);
            exit;
        }
        
        // Skip checkout events via AJAX as they're handled by page visit
        if ($event_type === 'checkout') {
            wp_send_json_success(array(
                'event_id' => 0,
                'event_type' => $event_type,
                'timestamp' => current_time('mysql'),
                'status' => 'skipped', // Checkout tracked by page visit
            ));
            exit;
        }
        
        // Get product ID and validate
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        
        // Get product name if provided
        $product_name = isset($_POST['product_name']) ? sanitize_text_field($_POST['product_name']) : '';
        
        // Validate product ID if provided
        if ($product_id > 0 && !$product_name) {
            $product = wc_get_product($product_id);
            if ($product) {
                $product_name = html_entity_decode($product->get_name(), ENT_QUOTES, 'UTF-8');
            } else {
                wp_send_json_error(array('message' => 'Invalid product ID'), 400);
                exit;
            }
        }
        
        // Get session ID
        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : $this->get_or_create_session_id();
        
        // Get IP address
        $ip_address = $this->get_client_ip();
        
        // For visitor events, check if this IP has already been counted today
        if ($event_type === 'visitor') {
            // Check if visitor with this IP has been recorded today
            if ($this->has_visitor_been_recorded($ip_address)) {
                wp_send_json_success(array(
                    'event_id' => 0,
                    'event_type' => $event_type,
                    'timestamp' => current_time('mysql'),
                    'status' => 'skipped', // Visitor already recorded
                ));
                exit;
            }
        }
        
        // Check for any duplicate event in the last 30 seconds
        if ($this->is_duplicate_event($event_type, $ip_address, $product_id)) {
            wp_send_json_success(array(
                'event_id' => 0,
                'event_type' => $event_type,
                'timestamp' => current_time('mysql'),
                'status' => 'skipped', // Event already recorded
            ));
            exit;
        }
        
        // Get country information
        $geo_data = $this->geo->get_country_from_ip($ip_address);
        
        // Get quantity for add to cart events
        $quantity = isset($_POST['quantity']) ? absint($_POST['quantity']) : 1;
        
        // Prepare event data
        $event_data = array(
            'session_id' => $session_id,
            'product_id' => $product_id,
            'product_name' => $product_name,
            'user_id' => get_current_user_id(),
            'ip_address' => $ip_address,
            'country_code' => isset($geo_data['country_code']) ? sanitize_text_field($geo_data['country_code']) : '',
            'country_name' => isset($geo_data['country_name']) ? sanitize_text_field($geo_data['country_name']) : '',
            'quantity' => $quantity
        );
        
        // Save event to database
        $event_id = $this->db->save_event($event_type, $event_data);
        
        if (!$event_id) {
            wp_send_json_error(array('message' => 'Failed to save event'), 500);
            exit;
        }
        
        // Send event via Pusher
        if ($event_id && $this->pusher->is_configured()) {
            $this->pusher->trigger('wc-analytics', $event_type, $event_data);
        }
        
        wp_send_json_success(array(
            'event_id' => $event_id,
            'event_type' => $event_type,
            'timestamp' => current_time('mysql')
        ));
    }
    
    /**
     * Check if a visitor with this IP has already been recorded today
     *
     * @param string $ip_address Visitor IP address
     * @return bool True if visitor already recorded, false otherwise
     */
    private function has_visitor_been_recorded($ip_address) {
        global $wpdb;
        
        $today = date('Y-m-d');
        $today_with_time = $today . ' 00:00:00';
        
        $table_name = $wpdb->prefix . 'wc_realtime_events';
        
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} 
            WHERE event_type = 'visitor' 
            AND ip_address = %s 
            AND created_at >= %s",
            $ip_address,
            $today_with_time
        ));
        
        return (int)$result > 0;
    }
    
    /**
     * Check if an event might be a duplicate (recently recorded for same IP and product)
     *
     * @param string $event_type Event type
     * @param string $ip_address IP address
     * @param int $product_id Product ID
     * @return bool True if likely duplicate, false otherwise
     */
    private function is_duplicate_event($event_type, $ip_address, $product_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wc_realtime_events';
        
        // Look for events in the last 30 seconds (prevents double-clicks and page reloads)
        $time_threshold = date('Y-m-d H:i:s', strtotime('-30 seconds'));
        
        // If no product ID, just check event type and IP
        if ($product_id === 0) {
            $result = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} 
                WHERE event_type = %s 
                AND ip_address = %s 
                AND created_at >= %s",
                $event_type,
                $ip_address,
                $time_threshold
            ));
        } else {
            $result = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} 
                WHERE event_type = %s 
                AND ip_address = %s 
                AND product_id = %d 
                AND created_at >= %s",
                $event_type,
                $ip_address,
                $product_id,
                $time_threshold
            ));
        }
        
        return (int)$result > 0;
    }
    
    /**
     * Track "Add to Cart" event
     *
     * @param string $cart_item_key Cart item key
     * @param int $product_id Product ID
     * @param int $quantity Quantity
     * @param int $variation_id Variation ID
     * @param array $variation Variation data
     * @param array $cart_item_data Cart item data
     */
    public function track_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        // Validate product ID
        $product_id = absint($product_id);
        if (!$product_id || !wc_get_product($product_id)) {
            return;
        }
        
        // Get IP address
        $ip_address = $this->get_client_ip();
        
        // Check for duplicate event to prevent double tracking
        if ($this->is_duplicate_event('add_to_cart', $ip_address, $product_id)) {
            return;
        }
        
        // Get country information
        $geo_data = $this->geo->get_country_from_ip($ip_address);
        
        // Prepare event data
        $event_data = array(
            'session_id' => $this->get_or_create_session_id(),
            'product_id' => $product_id,
            'user_id' => get_current_user_id(),
            'ip_address' => $ip_address,
            'country_code' => isset($geo_data['country_code']) ? sanitize_text_field($geo_data['country_code']) : '',
            'country_name' => isset($geo_data['country_name']) ? sanitize_text_field($geo_data['country_name']) : '',
            'quantity' => absint($quantity)
        );
        
        // Add product name for dashboard display
        $product = wc_get_product($product_id);
        if ($product) {
            $event_data['product_name'] = html_entity_decode($product->get_name(), ENT_QUOTES, 'UTF-8');
        }
        
        // Save event to database
        $event_id = $this->db->save_event('add_to_cart', $event_data);
        
        // Send event via Pusher
        if ($event_id && $this->pusher->is_configured()) {
            $this->pusher->trigger('wc-analytics', 'add_to_cart', $event_data);
        }
    }
    
    /**
     * Track "Purchase" event
     *
     * @param int $order_id Order ID
     */
    public function track_purchase($order_id) {
        $order_id = absint($order_id);
        if (!$order_id) {
            return;
        }
        
        // Check if already tracked in this session
        $tracked_orders = isset($_COOKIE['wc_purchase_tracked']) ? json_decode(stripslashes($_COOKIE['wc_purchase_tracked']), true) : array();
        
        if (!is_array($tracked_orders)) {
            $tracked_orders = array();
        }
        
        if (in_array($order_id, $tracked_orders, true)) {
            return;
        }
        
        // Get order
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        // Get IP address
        $ip_address = $this->get_client_ip();
        
        // Get country information
        $geo_data = $this->geo->get_country_from_ip($ip_address);
        
        // Get order items
        $items = array();
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $items[] = array(
                    'product_id' => $product->get_id(),
                    'name' => html_entity_decode($product->get_name(), ENT_QUOTES, 'UTF-8'),
                    'quantity' => $item->get_quantity(),
                    'price' => $product->get_price()
                );
            }
        }
        
        // Prepare event data
        $event_data = array(
            'session_id' => $this->get_or_create_session_id(),
            'user_id' => get_current_user_id(),
            'ip_address' => $ip_address,
            'country_code' => isset($geo_data['country_code']) ? sanitize_text_field($geo_data['country_code']) : '',
            'country_name' => isset($geo_data['country_name']) ? sanitize_text_field($geo_data['country_name']) : '',
            'order_id' => $order_id,
            'order_total' => $order->get_total(),
            'items' => $items,
            'items_count' => count($items)
        );
        
        // Track each product in the order
        if (!empty($items)) {
            foreach ($items as $item) {
                $product_event_data = array(
                    'session_id' => $event_data['session_id'],
                    'product_id' => absint($item['product_id']),
                    'product_name' => $item['name'],
                    'user_id' => $event_data['user_id'],
                    'ip_address' => $event_data['ip_address'],
                    'country_code' => $event_data['country_code'],
                    'country_name' => $event_data['country_name'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'order_id' => $order_id
                );
                
                $this->db->save_event('purchase', $product_event_data);
            }
        }
        
        // Save general purchase event (without specific product)
        $event_id = $this->db->save_event('purchase', $event_data);
        
        // Send event via Pusher
        if ($event_id && $this->pusher->is_configured()) {
            $this->pusher->trigger('wc-analytics', 'purchase', $event_data);
        }
        
        // Update cookie to prevent duplicate tracking
        $tracked_orders[] = $order_id;
        
        // Set httponly cookie with secure flags when possible
        $secure = is_ssl();
        setcookie(
            'wc_purchase_tracked', 
            json_encode($tracked_orders), 
            time() + 86400, 
            COOKIEPATH, 
            COOKIE_DOMAIN, 
            $secure, 
            true  // HttpOnly flag
        );
    }
    
    /**
     * Check if current request is AJAX
     *
     * @return bool True if AJAX request
     */
    private function is_ajax_request() {
        return (defined('DOING_AJAX') && DOING_AJAX) || 
               (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');
    }
    
    /**
     * Get or create a session ID for tracking
     *
     * @return string Session ID
     */
    private function get_or_create_session_id() {
        $cookie_name = 'wc_analytics_session';
        
        if (isset($_COOKIE[$cookie_name])) {
            return sanitize_text_field($_COOKIE[$cookie_name]);
        }
        
        // Create new session ID using more secure method
        if (function_exists('random_bytes')) {
            $random = bin2hex(random_bytes(16));
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            $random = bin2hex(openssl_random_pseudo_bytes(16));
        } else {
            $random = md5(uniqid(mt_rand(), true) . microtime());
        }
        
        $session_id = 'wc_' . $random;
        
        // Set secure cookie when possible
        $secure = is_ssl();
        setcookie($cookie_name, $session_id, time() + 86400, COOKIEPATH, COOKIE_DOMAIN, $secure, true);
        
        return $session_id;
    }
    
    /**
     * Get client IP address considering proxies
     *
     * @return string IP address
     */
    private function get_client_ip() {
        // Check for CloudFlare IP
        $cf_ip = isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? sanitize_text_field($_SERVER['HTTP_CF_CONNECTING_IP']) : '';
        
        if (!empty($cf_ip) && filter_var($cf_ip, FILTER_VALIDATE_IP)) {
            return $cf_ip;
        }
        
        // Check for proxy IPs
        $headers = array(
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        );
        
        foreach ($headers as $header) {
            if (isset($_SERVER[$header]) && !empty($_SERVER[$header])) {
                $ips = explode(',', sanitize_text_field($_SERVER[$header]));
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '127.0.0.1';  // Default localhost
    }
    
    /**
     * Get current product ID (if on product page)
     *
     * @return int Product ID or 0
     */
    private function get_current_product_id() {
        global $product;
        
        if (is_product() && $product && method_exists($product, 'get_id')) {
            return $product->get_id();
        }
        
        return 0;
    }
    
    /**
     * Exclude tracking endpoint from LiteSpeed Cache
     */
    public function exclude_from_litespeed_cache() {
        if (class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'nonce')) {
            LiteSpeed_Cache_API::nonce('wc_realtime_tracking_nonce');
        }
        
        if (class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'hook_tpl_not_cacheable')) {
            LiteSpeed_Cache_API::hook_tpl_not_cacheable('wp_ajax_wc_realtime_track');
            LiteSpeed_Cache_API::hook_tpl_not_cacheable('wp_ajax_nopriv_wc_realtime_track');
        }
    }
    
    /**
     * Exclude tracking JavaScript from LiteSpeed optimization
     *
     * @param array $excludes List of excluded scripts
     * @return array Updated list of excluded scripts
     */
    public function exclude_tracking_js_from_optimization($excludes) {
        if (!is_array($excludes)) {
            $excludes = array();
        }
        
        $excludes[] = 'wc-realtime-tracking';
        $excludes[] = 'pusher-js';
        return $excludes;
    }
}
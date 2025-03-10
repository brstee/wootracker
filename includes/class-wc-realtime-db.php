<?php
/**
 * Class WC_Realtime_DB
 * 
 * Database management for WooCommerce Real-time Analytics plugin
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WC_Realtime_DB {
    /**
     * Database table names
     */
    private $table_events;
    private $table_daily;
    private $table_products;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        
        // Define table names
        $this->table_events = $wpdb->prefix . 'wc_realtime_events';
        $this->table_daily = $wpdb->prefix . 'wc_realtime_daily';
        $this->table_products = $wpdb->prefix . 'wc_realtime_products';
    }
    
    /**
     * Create database tables
     */
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Table for storing detailed events
        $sql_events = "CREATE TABLE {$this->table_events} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            event_type varchar(20) NOT NULL,
            session_id varchar(50) NOT NULL,
            product_id bigint(20) DEFAULT 0,
            user_id bigint(20) DEFAULT 0,
            ip_address varchar(100) NOT NULL,
            country_code varchar(2) DEFAULT '',
            country_name varchar(50) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY event_type (event_type),
            KEY session_id (session_id),
            KEY product_id (product_id),
            KEY country_code (country_code),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Table for storing daily aggregated data
        $sql_daily = "CREATE TABLE {$this->table_daily} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            date date NOT NULL,
            visitors int(11) DEFAULT 0,
            add_to_cart int(11) DEFAULT 0,
            checkouts int(11) DEFAULT 0,
            purchases int(11) DEFAULT 0,
            country_code varchar(2) DEFAULT '',
            country_name varchar(50) DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY date_country (date, country_code),
            KEY date (date)
        ) $charset_collate;";
        
        // Table for storing product-specific data
        $sql_products = "CREATE TABLE {$this->table_products} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            date date NOT NULL,
            product_id bigint(20) NOT NULL,
            visitors int(11) DEFAULT 0,
            add_to_cart int(11) DEFAULT 0,
            checkouts int(11) DEFAULT 0,
            purchases int(11) DEFAULT 0,
            country_code varchar(2) DEFAULT '',
            country_name varchar(50) DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY product_date_country (product_id, date, country_code),
            KEY date (date),
            KEY product_id (product_id)
        ) $charset_collate;";
        
        // Execute table creation queries
        dbDelta($sql_events);
        dbDelta($sql_daily);
        dbDelta($sql_products);
    }
    
    /**
     * Save event to the database
     * 
     * @param string $event_type Type of event (visitor, add_to_cart, checkout, purchase)
     * @param array $data Event data
     * @return int|false ID of the record or false on failure
     */
    public function save_event($event_type, $data = array()) {
        global $wpdb;
        
        // Validate event type
        $valid_event_types = array('visitor', 'add_to_cart', 'checkout', 'purchase');
        if (!in_array($event_type, $valid_event_types, true)) {
            return false;
        }
        
        // Sanitize and validate data
        $product_id = isset($data['product_id']) ? absint($data['product_id']) : 0;
        $session_id = isset($data['session_id']) ? sanitize_text_field($data['session_id']) : '';
        $user_id = isset($data['user_id']) ? absint($data['user_id']) : 0;
        $ip_address = isset($data['ip_address']) ? sanitize_text_field($data['ip_address']) : '';
        $country_code = isset($data['country_code']) ? sanitize_text_field($data['country_code']) : '';
        $country_name = isset($data['country_name']) ? sanitize_text_field($data['country_name']) : '';
        
        // Validate required fields
        if (empty($session_id) || empty($ip_address)) {
            return false;
        }
        
        // Limit length of fields to prevent SQL injection through truncation
        $session_id = substr($session_id, 0, 50);
        $ip_address = substr($ip_address, 0, 100);
        $country_code = substr($country_code, 0, 2);
        $country_name = substr($country_name, 0, 50);
        
        $result = $wpdb->insert(
            $this->table_events,
            array(
                'event_type' => $event_type,
                'session_id' => $session_id,
                'product_id' => $product_id,
                'user_id' => $user_id,
                'ip_address' => $ip_address,
                'country_code' => $country_code,
                'country_name' => $country_name,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s')
        );
        
        if ($result) {
            // Update today's stats
            $this->update_today_stats($event_type, $product_id, $country_code, $country_name);
            return $wpdb->insert_id;
        }
        
        return false;
    }
    
    /**
     * Update today's statistics
     * 
     * @param string $event_type Type of event
     * @param int $product_id Product ID
     * @param string $country_code Country code
     * @param string $country_name Country name
     */
    private function update_today_stats($event_type, $product_id, $country_code, $country_name) {
        $today = date('Y-m-d');
        
        // Update daily stats
        $this->update_daily_stats($event_type, $today, $country_code, $country_name);
        
        // If we have a product_id, update product stats
        if ($product_id > 0) {
            $this->update_product_stats($event_type, $today, $product_id, $country_code, $country_name);
        }
    }
    
    /**
     * Update daily statistics table
     * 
     * @param string $event_type Type of event
     * @param string $date Date (Y-m-d)
     * @param string $country_code Country code
     * @param string $country_name Country name
     */
    private function update_daily_stats($event_type, $date, $country_code, $country_name) {
        global $wpdb;
        
        // Validate parameters
        $field = $this->get_field_from_event_type($event_type);
        if (!$field) {
            return;
        }
        
        // Sanitize date format
        $date = sanitize_text_field($date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return;
        }
        
        // Sanitize other inputs
        $country_code = sanitize_text_field($country_code);
        $country_name = sanitize_text_field($country_name);
        
        // Check if record exists using prepared statement
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_daily} WHERE date = %s AND country_code = %s",
            $date, $country_code
        ));
        
        if (!$exists) {
            // Create new record
            $wpdb->insert(
                $this->table_daily,
                array(
                    'date' => $date,
                    'country_code' => $country_code,
                    'country_name' => $country_name
                ),
                array('%s', '%s', '%s')
            );
        }
        
        // Update stats based on event type using prepared statement
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_daily} SET {$field} = {$field} + 1 WHERE date = %s AND country_code = %s",
            $date, $country_code
        ));
    }
    
    /**
     * Update product statistics table
     * 
     * @param string $event_type Type of event
     * @param string $date Date (Y-m-d)
     * @param int $product_id Product ID
     * @param string $country_code Country code
     * @param string $country_name Country name
     */
    private function update_product_stats($event_type, $date, $product_id, $country_code, $country_name) {
        global $wpdb;
        
        // Validate parameters
        $field = $this->get_field_from_event_type($event_type);
        if (!$field) {
            return;
        }
        
        // Sanitize date format
        $date = sanitize_text_field($date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return;
        }
        
        // Sanitize other inputs
        $product_id = absint($product_id);
        $country_code = sanitize_text_field($country_code);
        $country_name = sanitize_text_field($country_name);
        
        // Validate product ID
        if (!$product_id) {
            return;
        }
        
        // Check if record exists using prepared statement
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_products} WHERE date = %s AND product_id = %d AND country_code = %s",
            $date, $product_id, $country_code
        ));
        
        if (!$exists) {
            // Create new record
            $wpdb->insert(
                $this->table_products,
                array(
                    'date' => $date,
                    'product_id' => $product_id,
                    'country_code' => $country_code,
                    'country_name' => $country_name
                ),
                array('%s', '%d', '%s', '%s')
            );
        }
        
        // Update stats based on event type using prepared statement
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_products} SET {$field} = {$field} + 1 
            WHERE date = %s AND product_id = %d AND country_code = %s",
            $date, $product_id, $country_code
        ));
    }
    
    /**
     * Convert event_type to field name in tables
     * 
     * @param string $event_type Type of event
     * @return string|false Field name or false if invalid
     */
    private function get_field_from_event_type($event_type) {
        $mapping = array(
            'visitor' => 'visitors',
            'add_to_cart' => 'add_to_cart',
            'checkout' => 'checkouts',
            'purchase' => 'purchases'
        );
        
        return isset($mapping[$event_type]) ? $mapping[$event_type] : false;
    }
    
    /**
     * Get aggregated statistics
     * 
     * @param string $start_date Start date (Y-m-d)
     * @param string $end_date End date (Y-m-d)
     * @return array Statistics data
     */
    public function get_stats($start_date, $end_date) {
        global $wpdb;
        
        // Validate and sanitize date inputs
        $start_date = sanitize_text_field($start_date);
        $end_date = sanitize_text_field($end_date);
        
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            return array(
                'store' => array(),
                'products' => array(),
                'countries' => array()
            );
        }
        
        // Get overall store statistics
        $store_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                SUM(visitors) as visitors,
                SUM(add_to_cart) as add_to_cart,
                SUM(checkouts) as checkouts,
                SUM(purchases) as purchases
            FROM {$this->table_daily}
            WHERE date BETWEEN %s AND %s",
            $start_date, $end_date
        ), ARRAY_A);
        
        // Default values for null results
        $store_stats = $this->ensure_default_values($store_stats);
        
        // Calculate conversion rates
        $store_stats['atc_rate'] = $this->calculate_percentage($store_stats['add_to_cart'], $store_stats['visitors']);
        $store_stats['checkout_rate'] = $this->calculate_percentage($store_stats['checkouts'], $store_stats['add_to_cart']);
        $store_stats['purchase_rate'] = $this->calculate_percentage($store_stats['purchases'], $store_stats['checkouts']);
        
        // Get product-specific statistics
        $product_stats = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                product_id,
                SUM(visitors) as visitors,
                SUM(add_to_cart) as add_to_cart,
                SUM(checkouts) as checkouts,
                SUM(purchases) as purchases
            FROM {$this->table_products}
            WHERE date BETWEEN %s AND %s
            GROUP BY product_id
            ORDER BY visitors DESC",
            $start_date, $end_date
        ), ARRAY_A);
        
        // Get product names and add them to the results
        if (!empty($product_stats)) {
            foreach ($product_stats as &$product) {
                // Ensure values are integers
                $product['visitors'] = absint($product['visitors']);
                $product['add_to_cart'] = absint($product['add_to_cart']);
                $product['checkouts'] = absint($product['checkouts']);
                $product['purchases'] = absint($product['purchases']);
                
                // Get product name
                $product_id = absint($product['product_id']);
                $product_obj = wc_get_product($product_id);
                if ($product_obj) {
                    $product['name'] = html_entity_decode($product_obj->get_name(), ENT_QUOTES, 'UTF-8');
                } else {
                    $product['name'] = 'Product #' . $product_id;
                }
            }
        }
        
        // Get country-specific statistics
        $country_stats = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                country_code,
                country_name,
                SUM(visitors) as visitors,
                SUM(add_to_cart) as add_to_cart,
                SUM(checkouts) as checkouts,
                SUM(purchases) as purchases
            FROM {$this->table_daily}
            WHERE date BETWEEN %s AND %s AND country_code != ''
            GROUP BY country_code, country_name
            ORDER BY visitors DESC",
            $start_date, $end_date
        ), ARRAY_A);
        
        // Ensure values are integers
        if (!empty($country_stats)) {
            foreach ($country_stats as &$country) {
                $country['visitors'] = absint($country['visitors']);
                $country['add_to_cart'] = absint($country['add_to_cart']);
                $country['checkouts'] = absint($country['checkouts']);
                $country['purchases'] = absint($country['purchases']);
            }
        }
        
        return array(
            'store' => $store_stats,
            'products' => $product_stats,
            'countries' => $country_stats
        );
    }
    
    /**
     * Ensure default values for null database results
     *
     * @param array|null $data Data to check
     * @return array Data with default values
     */
    private function ensure_default_values($data) {
        if (!is_array($data)) {
            $data = array();
        }
        
        $defaults = array(
            'visitors' => 0,
            'add_to_cart' => 0,
            'checkouts' => 0,
            'purchases' => 0
        );
        
        foreach ($defaults as $key => $value) {
            if (!isset($data[$key]) || is_null($data[$key])) {
                $data[$key] = $value;
            } else {
                // Ensure values are integers
                $data[$key] = absint($data[$key]);
            }
        }
        
        return $data;
    }
    
    /**
     * Calculate percentage with safety for division by zero
     * 
     * @param int $numerator Numerator
     * @param int $denominator Denominator
     * @return float Percentage value
     */
    private function calculate_percentage($numerator, $denominator) {
        if (empty($denominator) || $denominator == 0) {
            return 0;
        }
        
        return round(($numerator / $denominator) * 100, 2);
    }
    
    /**
     * Get today's statistics
     * 
     * @return array Statistics data
     */
    public function get_today_stats() {
        $today = date('Y-m-d');
        return $this->get_stats($today, $today);
    }
    
    /**
     * Get yesterday's statistics
     * 
     * @return array Statistics data
     */
    public function get_yesterday_stats() {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        return $this->get_stats($yesterday, $yesterday);
    }
    
    /**
     * Get this week's statistics
     * 
     * @return array Statistics data
     */
    public function get_this_week_stats() {
        $start_of_week = date('Y-m-d', strtotime('this week monday'));
        $today = date('Y-m-d');
        return $this->get_stats($start_of_week, $today);
    }
    
    /**
     * Get this month's statistics
     * 
     * @return array Statistics data
     */
    public function get_this_month_stats() {
        $start_of_month = date('Y-m-01');
        $today = date('Y-m-d');
        return $this->get_stats($start_of_month, $today);
    }
    
    /**
     * Get statistics for the last 7 days
     * 
     * @return array Statistics data
     */
    public function get_last_7_days_stats() {
        $start_date = date('Y-m-d', strtotime('-6 days'));
        $today = date('Y-m-d');
        return $this->get_stats($start_date, $today);
    }
    
    /**
     * Get statistics for the last 30 days
     * 
     * @return array Statistics data
     */
    public function get_last_30_days_stats() {
        $start_date = date('Y-m-d', strtotime('-29 days'));
        $today = date('Y-m-d');
        return $this->get_stats($start_date, $today);
    }
    
    /**
     * Get statistics for a custom date range
     * 
     * @param string $start_date Start date (Y-m-d)
     * @param string $end_date End date (Y-m-d)
     * @return array Statistics data
     */
    public function get_custom_range_stats($start_date, $end_date) {
        return $this->get_stats($start_date, $end_date);
    }
    
    /**
     * Clean up old data from events table
     * 
     * @param int $days_to_keep Number of days to keep data
     * @return int Number of rows deleted
     */
    public function cleanup_events_data($days_to_keep = 30) {
        global $wpdb;
        
        // Validate days to keep
        $days_to_keep = absint($days_to_keep);
        if ($days_to_keep < 1) {
            $days_to_keep = 30; // Default value
        }
        
        $date_limit = date('Y-m-d H:i:s', strtotime("-{$days_to_keep} days"));
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_events} WHERE created_at < %s",
            $date_limit
        ));
        
        return $deleted;
    }
    
    /**
     * Check if database tables exist
     * 
     * @return bool True if all tables exist
     */
    public function tables_exist() {
        global $wpdb;
        
        $events_table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $this->table_events
        )) === $this->table_events;
        
        $daily_table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $this->table_daily
        )) === $this->table_daily;
        
        $products_table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $this->table_products
        )) === $this->table_products;
        
        return $events_table_exists && $daily_table_exists && $products_table_exists;
    }
}
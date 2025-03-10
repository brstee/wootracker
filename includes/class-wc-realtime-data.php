<?php
/**
 * Class WC_Realtime_Data
 * 
 * Handles data processing and reporting for WooCommerce Real-time Analytics
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WC_Realtime_Data {
    /**
     * Database handler
     *
     * @var WC_Realtime_DB
     */
    private $db;
    
    /**
     * Constructor
     *
     * @param WC_Realtime_DB $db Database handler
     */
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Get data for dashboard
     *
     * @param string $timeframe Timeframe to get data for
     * @param string $from_date From date for custom timeframe (Y-m-d)
     * @param string $to_date To date for custom timeframe (Y-m-d)
     * @return array Dashboard data
     */
    public function get_dashboard_data($timeframe = 'today', $from_date = '', $to_date = '') {
        // Get raw statistics based on timeframe
        $stats = $this->get_timeframe_stats($timeframe, $from_date, $to_date);
        
        // Process stats for dashboard display
        return $this->process_stats_for_dashboard($stats);
    }
    
    /**
     * Get statistics for a specific timeframe
     *
     * @param string $timeframe Timeframe to get data for
     * @param string $from_date From date for custom timeframe (Y-m-d)
     * @param string $to_date To date for custom timeframe (Y-m-d)
     * @return array Raw statistics
     */
    public function get_timeframe_stats($timeframe = 'today', $from_date = '', $to_date = '') {
        switch ($timeframe) {
            case 'today':
                return $this->db->get_today_stats();
                
            case 'yesterday':
                return $this->db->get_yesterday_stats();
                
            case 'this_week':
                return $this->db->get_this_week_stats();
                
            case 'this_month':
                return $this->db->get_this_month_stats();
                
            case 'last_7_days':
                return $this->db->get_last_7_days_stats();
                
            case 'last_30_days':
                return $this->db->get_last_30_days_stats();
                
            case 'custom':
                if (!empty($from_date) && !empty($to_date)) {
                    return $this->db->get_custom_range_stats($from_date, $to_date);
                }
                
                // Fallback to today if dates are not provided
                return $this->db->get_today_stats();
                
            default:
                return $this->db->get_today_stats();
        }
    }
    
    /**
     * Process raw statistics for dashboard display
     *
     * @param array $stats Raw statistics
     * @return array Processed statistics
     */
    private function process_stats_for_dashboard($stats) {
        if (!is_array($stats)) {
            return array(
                'store' => array(),
                'products' => array(),
                'countries' => array()
            );
        }
        
        // Process store overview data
        $store_data = isset($stats['store']) ? $stats['store'] : array();
        
        // Calculate conversion rates if not already calculated
        if (!isset($store_data['atc_rate']) && isset($store_data['visitors']) && isset($store_data['add_to_cart'])) {
            $store_data['atc_rate'] = $this->calculate_percentage($store_data['add_to_cart'], $store_data['visitors']);
        }
        
        if (!isset($store_data['checkout_rate']) && isset($store_data['add_to_cart']) && isset($store_data['checkouts'])) {
            $store_data['checkout_rate'] = $this->calculate_percentage($store_data['checkouts'], $store_data['add_to_cart']);
        }
        
        if (!isset($store_data['purchase_rate']) && isset($store_data['checkouts']) && isset($store_data['purchases'])) {
            $store_data['purchase_rate'] = $this->calculate_percentage($store_data['purchases'], $store_data['checkouts']);
        }
        
        // Process product data
        $product_data = isset($stats['products']) ? $stats['products'] : array();
        
        // Add product names if missing
        foreach ($product_data as &$product) {
            if (!isset($product['name']) && isset($product['product_id'])) {
                $product_obj = wc_get_product($product['product_id']);
                $product['name'] = $product_obj ? $product_obj->get_name() : 'Product #' . $product['product_id'];
            }
        }
        
        // Process country data
        $country_data = isset($stats['countries']) ? $stats['countries'] : array();
        
        return array(
            'store' => $store_data,
            'products' => $product_data,
            'countries' => $country_data
        );
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
     * Get daily breakdown data for charts
     *
     * @param string $from_date From date (Y-m-d)
     * @param string $to_date To date (Y-m-d)
     * @return array Daily data for charts
     */
    public function get_daily_chart_data($from_date, $to_date) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wc_realtime_daily';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                date,
                SUM(visitors) as visitors,
                SUM(add_to_cart) as add_to_cart,
                SUM(checkouts) as checkouts,
                SUM(purchases) as purchases
            FROM {$table_name}
            WHERE date BETWEEN %s AND %s
            GROUP BY date
            ORDER BY date ASC",
            $from_date, $to_date
        ), ARRAY_A);
        
        // Format data for chart
        $dates = array();
        $visitors = array();
        $add_to_cart = array();
        $checkouts = array();
        $purchases = array();
        
        foreach ($results as $row) {
            $dates[] = $row['date'];
            $visitors[] = (int)$row['visitors'];
            $add_to_cart[] = (int)$row['add_to_cart'];
            $checkouts[] = (int)$row['checkouts'];
            $purchases[] = (int)$row['purchases'];
        }
        
        return array(
            'dates' => $dates,
            'visitors' => $visitors,
            'add_to_cart' => $add_to_cart,
            'checkouts' => $checkouts,
            'purchases' => $purchases
        );
    }
    
    /**
     * Get top products data
     *
     * @param string $from_date From date (Y-m-d)
     * @param string $to_date To date (Y-m-d)
     * @param int $limit Number of products to return
     * @return array Top products data
     */
    public function get_top_products($from_date, $to_date, $limit = 10) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wc_realtime_products';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                product_id,
                SUM(visitors) as visitors,
                SUM(add_to_cart) as add_to_cart,
                SUM(checkouts) as checkouts,
                SUM(purchases) as purchases
            FROM {$table_name}
            WHERE date BETWEEN %s AND %s
            GROUP BY product_id
            ORDER BY visitors DESC
            LIMIT %d",
            $from_date, $to_date, $limit
        ), ARRAY_A);
        
        // Add product names
        foreach ($results as &$product) {
            $product_obj = wc_get_product($product['product_id']);
            $product['name'] = $product_obj ? $product_obj->get_name() : 'Product #' . $product['product_id'];
        }
        
        return $results;
    }
    
    /**
     * Get top countries data
     *
     * @param string $from_date From date (Y-m-d)
     * @param string $to_date To date (Y-m-d)
     * @param int $limit Number of countries to return
     * @return array Top countries data
     */
    public function get_top_countries($from_date, $to_date, $limit = 10) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wc_realtime_daily';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                country_code,
                country_name,
                SUM(visitors) as visitors,
                SUM(add_to_cart) as add_to_cart,
                SUM(checkouts) as checkouts,
                SUM(purchases) as purchases
            FROM {$table_name}
            WHERE date BETWEEN %s AND %s AND country_code != ''
            GROUP BY country_code, country_name
            ORDER BY visitors DESC
            LIMIT %d",
            $from_date, $to_date, $limit
        ), ARRAY_A);
        
        return $results;
    }
}
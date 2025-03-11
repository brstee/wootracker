<?php
/**
 * Class WC_Realtime_Data
 * 
 * Handles advanced data processing and reporting for WooCommerce Real-time Analytics
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
     * Cache for various calculations to improve performance
     *
     * @var array
     */
    private $cache = array();
    
    /**
     * Constructor
     *
     * @param WC_Realtime_DB $db Database handler
     */
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Get comprehensive dashboard data with advanced analysis
     *
     * @param string $timeframe Timeframe to get data for
     * @param string $from_date From date for custom timeframe (Y-m-d)
     * @param string $to_date To date for custom timeframe (Y-m-d)
     * @return array Enhanced dashboard data
     */
    public function get_dashboard_data($timeframe = 'today', $from_date = '', $to_date = '') {
        // Validate and sanitize input
        $timeframe = $this->sanitize_timeframe($timeframe);
        
        // Get raw statistics based on timeframe
        $stats = $this->get_timeframe_stats($timeframe, $from_date, $to_date);
        
        // Process stats with advanced analysis
        return $this->process_advanced_stats($stats, $timeframe, $from_date, $to_date);
    }
    
    /**
     * Sanitize and validate timeframe
     *
     * @param string $timeframe Input timeframe
     * @return string Validated timeframe
     */
    private function sanitize_timeframe($timeframe) {
        $valid_timeframes = array(
            'today', 'yesterday', 'this_week', 'this_month', 
            'last_7_days', 'last_30_days', 'custom'
        );
        
        return in_array($timeframe, $valid_timeframes, true) ? $timeframe : 'today';
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
        try {
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
                    // Validate date format
                    if (empty($from_date) || empty($to_date) || 
                        !$this->validate_date_format($from_date) || 
                        !$this->validate_date_format($to_date)) {
                        return $this->db->get_today_stats();
                    }
                    
                    return $this->db->get_custom_range_stats($from_date, $to_date);
                    
                default:
                    return $this->db->get_today_stats();
            }
        } catch (Exception $e) {
            error_log('WC Realtime Analytics: Error getting timeframe stats - ' . $e->getMessage());
            return array(
                'store' => array(),
                'products' => array(),
                'countries' => array()
            );
        }
    }
    
    /**
     * Validate date format
     *
     * @param string $date Date to validate
     * @return bool True if valid, false otherwise
     */
    private function validate_date_format($date) {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && 
               strtotime($date) !== false;
    }
    
    /**
     * Process advanced statistics with additional insights
     *
     * @param array $stats Raw statistics
     * @param string $timeframe Timeframe used
     * @param string $from_date From date (for custom range)
     * @param string $to_date To date (for custom range)
     * @return array Processed statistics with advanced insights
     */
    private function process_advanced_stats($stats, $timeframe, $from_date = '', $to_date = '') {
        if (!is_array($stats)) {
            return array(
                'store' => array(),
                'products' => array(),
                'countries' => array()
            );
        }
        
        // Ensure all sections exist
        $stats = array_merge(array(
            'store' => array(),
            'products' => array(),
            'countries' => array()
        ), $stats);
        
        // Process store data with advanced calculations
        $store_data = $this->process_store_insights($stats['store'], $timeframe, $from_date, $to_date);
        
        // Process product data with ranking and performance metrics
        $product_data = $this->process_product_insights($stats['products']);
        
        // Process country data with geographic insights
        $country_data = $this->process_country_insights($stats['countries']);
        
        return array(
            'store' => $store_data,
            'products' => $product_data,
            'countries' => $country_data,
            'timeframe' => $timeframe
        );
    }
    
    /**
     * Calculate advanced store insights
     *
     * @param array $store_stats Raw store statistics
     * @param string $timeframe Current timeframe
     * @param string $from_date From date (for custom range)
     * @param string $to_date To date (for custom range)
     * @return array Enhanced store insights
     */
    private function process_store_insights($store_stats, $timeframe, $from_date = '', $to_date = '') {
        // Ensure default values
        $store_stats = $this->ensure_default_values($store_stats);
        
        // Calculate conversion rates (if not already calculated)
        $store_stats['atc_rate'] = $this->calculate_percentage(
            $store_stats['add_to_cart'], 
            $store_stats['visitors']
        );
        
        $store_stats['checkout_rate'] = $this->calculate_percentage(
            $store_stats['checkouts'], 
            $store_stats['add_to_cart']
        );
        
        $store_stats['purchase_rate'] = $this->calculate_percentage(
            $store_stats['purchases'], 
            $store_stats['checkouts']
        );
        
        // Calculate total revenue (if possible)
        $store_stats['estimated_revenue'] = $this->calculate_estimated_revenue($store_stats);
        
        // Add trend information based on timeframe
        $store_stats['trend'] = $this->get_trend_data($timeframe, $from_date, $to_date);
        
        return $store_stats;
    }
    
    /**
     * Calculate estimated revenue from purchases
     *
     * @param array $store_stats Store statistics
     * @return float Estimated revenue
     */
    private function calculate_estimated_revenue($store_stats) {
        global $wpdb;
        
        try {
            $avg_order_total = $wpdb->get_var(
                "SELECT AVG(total) FROM {$wpdb->prefix}woocommerce_order_items 
                JOIN {$wpdb->prefix}woocommerce_order_itemmeta ON 
                {$wpdb->prefix}woocommerce_order_items.order_id = {$wpdb->prefix}woocommerce_order_itemmeta.order_id 
                WHERE meta_key = '_line_total'"
            );
            
            $purchases = max(1, $store_stats['purchases']);
            return round($avg_order_total * $purchases, 2);
        } catch (Exception $e) {
            error_log('WC Realtime Analytics: Error calculating estimated revenue - ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get trend data for store performance
     *
     * @param string $timeframe Current timeframe
     * @param string $from_date From date (for custom range)
     * @param string $to_date To date (for custom range)
     * @return array Trend information
     */
    private function get_trend_data($timeframe, $from_date = '', $to_date = '') {
        // Caching to prevent multiple calculations
        $cache_key = "trend_{$timeframe}_{$from_date}_{$to_date}";
        
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }
        
        // Placeholder for trend calculation
        // In a real implementation, this would compare current period with previous period
        $trend = array(
            'visitors' => 'stable', // Could be 'up', 'down', or 'stable'
            'add_to_cart' => 'stable',
            'checkouts' => 'stable',
            'purchases' => 'stable'
        );
        
        $this->cache[$cache_key] = $trend;
        return $trend;
    }
    
    /**
     * Process product insights with advanced ranking
     *
     * @param array $products Raw product data
     * @return array Enhanced product insights
     */
    private function process_product_insights($products) {
        if (empty($products)) {
            return array();
        }
        
        // Calculate additional product metrics
        foreach ($products as &$product) {
            // Convert to integers to ensure numeric operations
            $product['visitors'] = absint($product['visitors']);
            $product['add_to_cart'] = absint($product['add_to_cart']);
            $product['checkouts'] = absint($product['checkouts']);
            $product['purchases'] = absint($product['purchases']);
            
            // Calculate conversion rates
            $product['atc_rate'] = $this->calculate_percentage(
                $product['add_to_cart'], 
                $product['visitors']
            );
            
            $product['purchase_rate'] = $this->calculate_percentage(
                $product['purchases'], 
                $product['add_to_cart']
            );
            
            // Fetch additional product information if missing
            if (empty($product['name'])) {
                $product_obj = wc_get_product($product['product_id']);
                $product['name'] = $product_obj ? $product_obj->get_name() : 'Product #' . $product['product_id'];
            }
        }
        
        // Sort products by visitors in descending order
        usort($products, function($a, $b) {
            return $b['visitors'] - $a['visitors'];
        });
        
        return $products;
    }
    
    /**
     * Process country insights with geographic analysis
     *
     * @param array $countries Raw country data
     * @return array Enhanced country insights
     */
    private function process_country_insights($countries) {
        if (empty($countries)) {
            return array();
        }
        
        // Calculate total metrics for comparison
        $total_visitors = array_sum(array_column($countries, 'visitors'));
        
        // Process each country
        foreach ($countries as &$country) {
            // Ensure integer values
            $country['visitors'] = absint($country['visitors']);
            $country['add_to_cart'] = absint($country['add_to_cart']);
            $country['checkouts'] = absint($country['checkouts']);
            $country['purchases'] = absint($country['purchases']);
            
            // Calculate country-specific rates
            $country['visitors_percentage'] = $total_visitors > 0 
                ? round(($country['visitors'] / $total_visitors) * 100, 2) 
                : 0;
            
            $country['atc_rate'] = $this->calculate_percentage(
                $country['add_to_cart'], 
                $country['visitors']
            );
            
            $country['purchase_rate'] = $this->calculate_percentage(
                $country['purchases'], 
                $country['add_to_cart']
            );
        }
        
        // Sort countries by visitors in descending order
        usort($countries, function($a, $b) {
            return $b['visitors'] - $a['visitors'];
        });
        
        return $countries;
    }
    
    /**
     * Ensure default values for null or empty results
     *
     * @param array $data Data to normalize
     * @return array Normalized data with default values
     */
    private function ensure_default_values($data) {
        $defaults = array(
            'visitors' => 0,
            'add_to_cart' => 0,
            'checkouts' => 0,
            'purchases' => 0,
            'atc_rate' => 0,
            'checkout_rate' => 0,
            'purchase_rate' => 0
        );
        
        return array_merge($defaults, array_intersect_key($data, $defaults));
    }
    
    /**
     * Calculate percentage safely
     *
     * @param int $numerator Numerator
     * @param int $denominator Denominator
     * @return float Calculated percentage
     */
    private function calculate_percentage($numerator, $denominator) {
        $numerator = absint($numerator);
        $denominator = absint($denominator);
        
        if ($denominator === 0) {
            return 0.00;
        }
        
        return round(($numerator / $denominator) * 100, 2);
    }
}
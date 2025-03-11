<?php
/**
 * Class WC_Realtime_Geo
 * 
 * Enhanced geolocation functionality for WooCommerce Real-time Analytics
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WC_Realtime_Geo {
    /**
     * Available geolocation services
     *
     * @var array
     */
    private $geolocation_services = array(
        'woocommerce' => 'get_country_with_wc',
        'cloudflare' => 'get_country_with_cloudflare',
        'ip_api' => 'get_country_with_ip_api',
        'ipinfo' => 'get_country_with_ipinfo',
        'ipapi_co' => 'get_country_with_ipapi_co'
    );
    
    /**
     * Cache for geolocation results to improve performance
     *
     * @var array
     */
    private $location_cache = array();
    
    /**
     * Maximum cache time in seconds
     *
     * @var int
     */
    private $cache_expiration = 3600; // 1 hour
    
    /**
     * Constructor
     */
    public function __construct() {
        // Initialize any necessary setup
        $this->init();
    }
    
    /**
     * Initialize geolocation
     */
    private function init() {
        // Add any initialization logic if needed
    }
    
    /**
     * Get country information from IP address
     *
     * @param string $ip_address IP address
     * @return array Country data (code and name)
     */
    public function get_country_from_ip($ip_address) {
        // Sanitize IP address
        $ip_address = $this->sanitize_ip_address($ip_address);
        
        // Check cache first
        $cached_result = $this->get_cached_location($ip_address);
        if ($cached_result) {
            return $cached_result;
        }
        
        // Skip for localhost and private IPs
        if ($this->is_private_ip($ip_address)) {
            return $this->get_default_result();
        }
        
        // Try different geolocation methods
        $result = $this->try_geolocation_services($ip_address);
        
        // Cache the result
        if (!empty($result['country_code'])) {
            $this->cache_location($ip_address, $result);
        }
        
        return $result;
    }
    
    /**
     * Sanitize IP address
     *
     * @param string $ip_address IP address to sanitize
     * @return string Sanitized IP address
     */
    private function sanitize_ip_address($ip_address) {
        // Remove any whitespace
        $ip_address = trim($ip_address);
        
        // Validate IPv4 or IPv6
        if (filter_var($ip_address, FILTER_VALIDATE_IP)) {
            return $ip_address;
        }
        
        // Log invalid IP
        error_log("WC Realtime Analytics: Invalid IP address - {$ip_address}");
        
        return '127.0.0.1'; // Default to localhost
    }
    
    /**
     * Try multiple geolocation services
     *
     * @param string $ip_address IP address
     * @return array Country data
     */
    private function try_geolocation_services($ip_address) {
        foreach ($this->geolocation_services as $service => $method) {
            try {
                // Only attempt if method exists
                if (method_exists($this, $method)) {
                    $result = $this->$method($ip_address);
                    
                    // Return first successful result
                    if (!empty($result['country_code'])) {
                        return $result;
                    }
                }
            } catch (Exception $e) {
                // Log any errors
                error_log("WC Realtime Analytics: Geolocation service {$service} failed - " . $e->getMessage());
            }
        }
        
        // If all services fail, return default
        return $this->get_default_result();
    }
    
    /**
     * Get country code using WooCommerce's geolocation
     *
     * @param string $ip_address IP address
     * @return array Country data
     */
    private function get_country_with_wc($ip_address) {
        if (!class_exists('WC_Geolocation')) {
            return $this->get_default_result();
        }
        
        try {
            $geolocation = new WC_Geolocation();
            $geo_data = $geolocation->geolocate_ip($ip_address);
            
            // Validate and format result
            if (isset($geo_data['country'])) {
                return $this->format_country_result($geo_data['country']);
            }
        } catch (Exception $e) {
            error_log("WC Realtime Analytics: WooCommerce geolocation failed - " . $e->getMessage());
        }
        
        return $this->get_default_result();
    }
    
    /**
     * Get country from Cloudflare headers
     *
     * @param string $ip_address IP address
     * @return array Country data
     */
    private function get_country_with_cloudflare($ip_address) {
        if (isset($_SERVER['HTTP_CF_IPCOUNTRY']) && !empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
            $country_code = sanitize_text_field($_SERVER['HTTP_CF_IPCOUNTRY']);
            return $this->format_country_result($country_code);
        }
        
        return $this->get_default_result();
    }
    
    /**
     * Get country using IP-API.com service
     *
     * @param string $ip_address IP address
     * @return array Country data
     */
    private function get_country_with_ip_api($ip_address) {
        try {
            $response = wp_remote_get("http://ip-api.com/json/{$ip_address}?fields=countryCode,country");
            
            if (is_wp_error($response)) {
                return $this->get_default_result();
            }
            
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (isset($data['countryCode']) && !empty($data['countryCode'])) {
                return array(
                    'country_code' => sanitize_text_field($data['countryCode']),
                    'country_name' => isset($data['country']) ? sanitize_text_field($data['country']) : ''
                );
            }
        } catch (Exception $e) {
            error_log("WC Realtime Analytics: IP-API geolocation failed - " . $e->getMessage());
        }
        
        return $this->get_default_result();
    }
    
    /**
     * Get country using IPInfo.io service
     *
     * @param string $ip_address IP address
     * @return array Country data
     */
    private function get_country_with_ipinfo($ip_address) {
        try {
            $response = wp_remote_get("https://ipinfo.io/{$ip_address}/json");
            
            if (is_wp_error($response)) {
                return $this->get_default_result();
            }
            
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (isset($data['country']) && !empty($data['country'])) {
                return $this->format_country_result($data['country'], 
                    isset($data['region']) ? $data['region'] : '');
            }
        } catch (Exception $e) {
            error_log("WC Realtime Analytics: IPInfo geolocation failed - " . $e->getMessage());
        }
        
        return $this->get_default_result();
    }
    
    /**
     * Get country using ipapi.co service
     *
     * @param string $ip_address IP address
     * @return array Country data
     */
    private function get_country_with_ipapi_co($ip_address) {
        try {
            $response = wp_remote_get("https://ipapi.co/{$ip_address}/json/");
            
            if (is_wp_error($response)) {
                return $this->get_default_result();
            }
            
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (isset($data['country_code']) && !empty($data['country_code'])) {
                return array(
                    'country_code' => sanitize_text_field($data['country_code']),
                    'country_name' => isset($data['country_name']) ? sanitize_text_field($data['country_name']) : ''
                );
            }
        } catch (Exception $e) {
            error_log("WC Realtime Analytics: ipapi.co geolocation failed - " . $e->getMessage());
        }
        
        return $this->get_default_result();
    }
    
    /**
     * Format country result with WooCommerce country name if possible
     *
     * @param string $country_code Country code
     * @param string $region Optional region name
     * @return array Formatted country data
     */
    private function format_country_result($country_code, $region = '') {
        $country_code = strtoupper(sanitize_text_field($country_code));
        
        // Try to get full country name from WooCommerce
        $country_name = '';
        if (class_exists('WC_Countries')) {
            $countries = WC()->countries->get_countries();
            $country_name = isset($countries[$country_code]) ? $countries[$country_code] : $region;
        }
        
        return array(
            'country_code' => $country_code,
            'country_name' => $country_name
        );
    }
    
    /**
     * Check if an IP address is private/local
     *
     * @param string $ip_address IP address
     * @return bool True if IP is private/local
     */
    private function is_private_ip($ip_address) {
        // Comprehensive check for private IP ranges
        $private_ranges = array(
            // IPv4 private ranges
            array('10.0.0.0', '10.255.255.255'),      // 10.0.0.0/8
            array('172.16.0.0', '172.31.255.255'),    // 172.16.0.0/12
            array('192.168.0.0', '192.168.255.255'),  // 192.168.0.0/16
            array('169.254.0.0', '169.254.255.255'),  // Link-local
            array('127.0.0.0', '127.255.255.255'),    // Localhost
            
            // IPv6 private ranges
            array('fc00::', 'fdff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'), // Unique local addresses
            '::1'                                     // Localhost
        );
        
        // Convert IP to long for comparison
        $ip_long = ip2long($ip_address);
        
        foreach ($private_ranges as $range) {
            if (is_array($range)) {
                $start = ip2long($range[0]);
                $end = ip2long($range[1]);
                
                if ($ip_long !== false && $ip_long >= $start && $ip_long <= $end) {
                    return true;
                }
            } elseif ($ip_address === $range) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get default result when geolocation fails
     *
     * @return array Default country result
     */
    private function get_default_result() {
        return array(
            'country_code' => '',
            'country_name' => ''
        );
    }
    
    /**
     * Cache location result
     *
     * @param string $ip_address IP address
     * @param array $result Geolocation result
     */
    private function cache_location($ip_address, $result) {
        // Use transient API for persistent caching
        set_transient("wcra_geo_{$ip_address}", $result, $this->cache_expiration);
    }
    
    /**
     * Get cached location result
     *
     * @param string $ip_address IP address
     * @return array|false Cached result or false if not found
     */
    private function get_cached_location($ip_address) {
        // Retrieve from transient
        $cached_result = get_transient("wcra_geo_{$ip_address}");
        
        return $cached_result ? $cached_result : false;
    }
}
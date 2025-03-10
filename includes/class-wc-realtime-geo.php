<?php
/**
 * Class WC_Realtime_Geo
 * 
 * Handles geolocation functionality for WooCommerce Real-time Analytics
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WC_Realtime_Geo {
    /**
     * Use WooCommerce's built-in geolocation if available
     *
     * @var bool
     */
    private $use_wc_geolocation;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Check if WooCommerce geolocation is available
        $this->use_wc_geolocation = class_exists('WC_Geolocation');
    }
    
    /**
     * Get country information from IP address
     *
     * @param string $ip_address IP address
     * @return array Country data (code and name)
     */
    public function get_country_from_ip($ip_address) {
        $country_code = '';
        $country_name = '';
        
        // Default result if geolocation fails
        $result = array(
            'country_code' => '',
            'country_name' => ''
        );
        
        // Skip for localhost and private IPs
        if ($this->is_private_ip($ip_address)) {
            return $result;
        }
        
        // Try to use WooCommerce's geolocation
        if ($this->use_wc_geolocation) {
            $country_code = $this->get_country_with_wc($ip_address);
            
            if (!empty($country_code)) {
                $countries = WC()->countries->get_countries();
                $country_name = isset($countries[$country_code]) ? $countries[$country_code] : '';
                
                return array(
                    'country_code' => $country_code,
                    'country_name' => $country_name
                );
            }
        }
        
        // Fallback to Cloudflare if available
        if (isset($_SERVER['HTTP_CF_IPCOUNTRY']) && !empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
            $country_code = sanitize_text_field($_SERVER['HTTP_CF_IPCOUNTRY']);
            
            // Get country name from WooCommerce if available
            if (class_exists('WC_Countries')) {
                $countries = WC()->countries->get_countries();
                $country_name = isset($countries[$country_code]) ? $countries[$country_code] : '';
            }
            
            return array(
                'country_code' => $country_code,
                'country_name' => $country_name
            );
        }
        
        // Fallback to IP-API service as a last resort
        return $this->get_country_with_ip_api($ip_address);
    }
    
    /**
     * Get country code using WooCommerce's geolocation
     *
     * @param string $ip_address IP address
     * @return string Country code
     */
    private function get_country_with_wc($ip_address) {
        if (!class_exists('WC_Geolocation')) {
            return '';
        }
        
        // Get geolocation instance
        $geolocation = new WC_Geolocation();
        
        // Get geolocation data
        $geo_data = $geolocation->geolocate_ip($ip_address);
        
        return isset($geo_data['country']) ? $geo_data['country'] : '';
    }
    
    /**
     * Get country information using IP-API.com service
     *
     * @param string $ip_address IP address
     * @return array Country data (code and name)
     */
    private function get_country_with_ip_api($ip_address) {
        $result = array(
            'country_code' => '',
            'country_name' => ''
        );
        
        // Make request to IP-API.com
        $response = wp_remote_get("http://ip-api.com/json/{$ip_address}?fields=countryCode,country");
        
        if (is_wp_error($response)) {
            return $result;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!is_array($data)) {
            return $result;
        }
        
        return array(
            'country_code' => isset($data['countryCode']) ? $data['countryCode'] : '',
            'country_name' => isset($data['country']) ? $data['country'] : ''
        );
    }
    
    /**
     * Check if an IP address is private/local
     *
     * @param string $ip_address IP address
     * @return bool True if IP is private/local
     */
    private function is_private_ip($ip_address) {
        // Check for localhost
        if ($ip_address === '127.0.0.1' || $ip_address === '::1') {
            return true;
        }
        
        // IPv4 private ranges
        $private_ipv4_ranges = array(
            '10.0.0.0|10.255.255.255',     // 10.0.0.0/8
            '172.16.0.0|172.31.255.255',   // 172.16.0.0/12
            '192.168.0.0|192.168.255.255', // 192.168.0.0/16
            '169.254.0.0|169.254.255.255', // 169.254.0.0/16
            '127.0.0.0|127.255.255.255'    // 127.0.0.0/8
        );
        
        // Convert IP to long representation
        $ip_long = ip2long($ip_address);
        
        // Check if IP is valid IPv4
        if ($ip_long !== false) {
            foreach ($private_ipv4_ranges as $range) {
                list($start, $end) = explode('|', $range);
                
                if ($ip_long >= ip2long($start) && $ip_long <= ip2long($end)) {
                    return true;
                }
            }
        }
        
        // Check simple IPv6 cases
        if (strpos($ip_address, 'fc00:') === 0 || strpos($ip_address, 'fd00:') === 0) {
            return true; // fc00::/7 - unique local addresses
        }
        
        return false;
    }
}
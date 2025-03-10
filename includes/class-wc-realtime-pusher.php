<?php
/**
 * Class WC_Realtime_Pusher
 * 
 * Handles Pusher integration for WooCommerce Real-time Analytics
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WC_Realtime_Pusher {
    /**
     * Pusher app ID
     *
     * @var string
     */
    private $app_id;
    
    /**
     * Pusher key
     *
     * @var string
     */
    private $key;
    
    /**
     * Pusher secret
     *
     * @var string
     */
    private $secret;
    
    /**
     * Pusher cluster
     *
     * @var string
     */
    private $cluster;
    
    /**
     * Pusher instance
     *
     * @var Pusher\Pusher
     */
    private $pusher;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Get Pusher configuration from options
        $this->app_id = get_option('wc_realtime_pusher_app_id', '');
        $this->key = get_option('wc_realtime_pusher_key', '');
        $this->secret = get_option('wc_realtime_pusher_secret', '');
        $this->cluster = get_option('wc_realtime_pusher_cluster', 'mt1');
        
        // Initialize Pusher if all required settings are available
        if ($this->is_configured()) {
            $this->init_pusher();
        }
    }
    
    /**
     * Initialize Pusher instance
     */
    private function init_pusher() {
        // Include Pusher PHP SDK via Composer autoload if available
        if (file_exists(WCRA_PLUGIN_DIR . 'vendor/autoload.php')) {
            require_once WCRA_PLUGIN_DIR . 'vendor/autoload.php';
        }
        
        // Create Pusher instance
        if (class_exists('Pusher\\Pusher')) {
            $this->pusher = new Pusher\Pusher(
                $this->key,
                $this->secret,
                $this->app_id,
                array(
                    'cluster' => $this->cluster,
                    'useTLS' => true
                )
            );
        }
    }
    
    /**
     * Check if Pusher is properly configured
     *
     * @return bool
     */
    public function is_configured() {
        return !empty($this->app_id) && !empty($this->key) && !empty($this->secret);
    }
    
    /**
     * Trigger a Pusher event
     *
     * @param string $channel Channel name
     * @param string $event Event name
     * @param array $data Event data
     * @return bool|array Success status or error response
     */
    public function trigger($channel, $event, $data) {
        if (!$this->pusher) {
            return false;
        }
        
        try {
            return $this->pusher->trigger($channel, $event, $data);
        } catch (Exception $e) {
            // Log error for debugging
            error_log('Pusher error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get Pusher key for use in JavaScript
     *
     * @return string
     */
    public function get_key() {
        return $this->key;
    }
    
    /**
     * Get Pusher cluster for use in JavaScript
     *
     * @return string
     */
    public function get_cluster() {
        return $this->cluster;
    }
    
    /**
     * Save Pusher settings
     *
     * @param string $app_id Pusher app ID
     * @param string $key Pusher key
     * @param string $secret Pusher secret
     * @param string $cluster Pusher cluster
     * @return bool Success status
     */
    public function save_settings($app_id, $key, $secret, $cluster) {
        $app_id = sanitize_text_field($app_id);
        $key = sanitize_text_field($key);
        $secret = sanitize_text_field($secret);
        $cluster = sanitize_text_field($cluster);
        
        update_option('wc_realtime_pusher_app_id', $app_id);
        update_option('wc_realtime_pusher_key', $key);
        update_option('wc_realtime_pusher_secret', $secret);
        update_option('wc_realtime_pusher_cluster', $cluster);
        
        // Update instance properties
        $this->app_id = $app_id;
        $this->key = $key;
        $this->secret = $secret;
        $this->cluster = $cluster;
        
        // Re-initialize Pusher
        $this->init_pusher();
        
        return true;
    }
}
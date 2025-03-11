<?php
/**
 * Class WC_Realtime_Pusher
 * 
 * Enhanced Pusher integration for WooCommerce Real-time Analytics
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WC_Realtime_Pusher {
    /**
     * Pusher configuration constants
     */
    const MAX_RETRY_ATTEMPTS = 3;
    const RETRY_DELAY = 1; // seconds
    const EVENT_TIMEOUT = 10; // seconds

    /**
     * Pusher app configuration
     *
     * @var array
     */
    private $config = array(
        'app_id' => '',
        'key' => '',
        'secret' => '',
        'cluster' => 'mt1',
        'encrypted' => true
    );
    
    /**
     * Pusher instance
     *
     * @var Pusher\Pusher|Pusher
     */
    private $pusher = null;
    
    /**
     * Logger for tracking Pusher events
     *
     * @var WC_Logger
     */
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Initialize logger
        $this->logger = wc_get_logger();
        
        // Load configuration
        $this->load_configuration();
        
        // Initialize Pusher if configured
        $this->init_pusher();
    }
    
    /**
     * Load Pusher configuration from WordPress options
     */
    private function load_configuration() {
        $this->config['app_id'] = get_option('wc_realtime_pusher_app_id', '');
        $this->config['key'] = get_option('wc_realtime_pusher_key', '');
        $this->config['secret'] = get_option('wc_realtime_pusher_secret', '');
        $this->config['cluster'] = get_option('wc_realtime_pusher_cluster', 'mt1');
    }
    
    /**
     * Initialize Pusher instance
     */
    private function init_pusher() {
        // Skip initialization if not fully configured
        if (!$this->is_configured()) {
            $this->log_error('Pusher not configured - missing credentials');
            return;
        }
        
        try {
            // Attempt to load Pusher library
            $this->load_pusher_library();
            
            // Create Pusher instance
            $this->create_pusher_instance();
        } catch (Exception $e) {
            $this->log_error('Pusher initialization failed', array(
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
        }
    }
    
    /**
     * Load Pusher library with multiple fallback methods
     */
    private function load_pusher_library() {
        $library_paths = array(
            WCRA_PLUGIN_DIR . 'vendor/autoload.php',
            WCRA_PLUGIN_DIR . 'includes/lib/Pusher.php',
            WP_PLUGIN_DIR . '/pusher/pusher.php'
        );
        
        foreach ($library_paths as $path) {
            if (file_exists($path)) {
                require_once $path;
                return;
            }
        }
        
        throw new Exception('Pusher library not found');
    }
    
    /**
     * Create Pusher instance with error handling
     */
    private function create_pusher_instance() {
        $options = array(
            'cluster' => $this->config['cluster'],
            'useTLS' => $this->config['encrypted'],
            'debug' => defined('WP_DEBUG') && WP_DEBUG
        );
        
        // Try namespaced Pusher class first
        if (class_exists('Pusher\\Pusher')) {
            $this->pusher = new Pusher\Pusher(
                $this->config['key'], 
                $this->config['secret'], 
                $this->config['app_id'], 
                $options
            );
        } 
        // Fallback to non-namespaced class
        elseif (class_exists('Pusher')) {
            $this->pusher = new Pusher(
                $this->config['key'], 
                $this->config['secret'], 
                $this->config['app_id'], 
                $options
            );
        } else {
            throw new Exception('No Pusher class found');
        }
    }
    
    /**
     * Check if Pusher is properly configured
     *
     * @return bool
     */
    public function is_configured() {
        return !empty($this->config['app_id']) && 
               !empty($this->config['key']) && 
               !empty($this->config['secret']);
    }
    
    /**
     * Trigger a Pusher event with advanced error handling
     *
     * @param string $channel Channel name
     * @param string $event Event name
     * @param array $data Event data
     * @return bool Success status
     */
    public function trigger($channel, $event, $data) {
        // Validate inputs
        if (!$this->validate_trigger_inputs($channel, $event, $data)) {
            return false;
        }
        
        // Prepare event data
        $prepared_data = $this->prepare_event_data($data);
        
        // Attempt to trigger event with retries
        return $this->attempt_trigger($channel, $event, $prepared_data);
    }
    
    /**
     * Validate trigger inputs
     *
     * @param string $channel Channel name
     * @param string $event Event name
     * @param array $data Event data
     * @return bool
     */
    private function validate_trigger_inputs($channel, $event, $data) {
        // Check Pusher is initialized
        if (!$this->pusher) {
            $this->log_error('Pusher not initialized');
            return false;
        }
        
        // Validate channel and event names
        if (empty($channel) || empty($event)) {
            $this->log_error('Invalid channel or event', array(
                'channel' => $channel,
                'event' => $event
            ));
            return false;
        }
        
        // Ensure data is an array
        if (!is_array($data)) {
            $this->log_error('Event data must be an array');
            return false;
        }
        
        return true;
    }
    
    /**
     * Prepare event data with additional metadata
     *
     * @param array $data Original event data
     * @return array Prepared event data
     */
    private function prepare_event_data($data) {
        // Add timestamp
        $data['timestamp'] = current_time('mysql');
        
        // Add site-specific identifier
        $data['site_url'] = get_site_url();
        
        // Sanitize and truncate data
        return $this->sanitize_event_data($data);
    }
    
    /**
     * Sanitize and limit event data size
     *
     * @param array $data Event data
     * @return array Sanitized event data
     */
    private function sanitize_event_data($data) {
        // Limit total data size
        $max_data_size = 10 * 1024; // 10KB
        
        // Recursive sanitization
        $sanitized = array_map(function($value) {
            // Convert to string if not already
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value);
            }
            
            // Truncate long strings
            return is_string($value) ? 
                mb_substr($value, 0, 500, 'UTF-8') : 
                $value;
        }, $data);
        
        // Ensure data doesn't exceed max size
        $encoded = json_encode($sanitized);
        if (strlen($encoded) > $max_data_size) {
            $this->log_error('Event data too large', array(
                'size' => strlen($encoded)
            ));
            return array_slice($sanitized, 0, 10);
        }
        
        return $sanitized;
    }
    
    /**
     * Attempt to trigger event with retry mechanism
     *
     * @param string $channel Channel name
     * @param string $event Event name
     * @param array $data Prepared event data
     * @return bool Success status
     */
    private function attempt_trigger($channel, $event, $data) {
        for ($attempt = 1; $attempt <= self::MAX_RETRY_ATTEMPTS; $attempt++) {
            try {
                // Set timeout
                add_filter('http_request_timeout', array($this, 'get_event_timeout'));
                
                // Trigger event
                $result = $this->pusher->trigger($channel, $event, $data);
                
                // Remove timeout filter
                remove_filter('http_request_timeout', array($this, 'get_event_timeout'));
                
                // Check result
                if ($result === true || 
                    (is_array($result) && isset($result['status']) && $result['status'] === 200)) {
                    $this->log_success($channel, $event, $data);
                    return true;
                }
                
                // Log failed attempt
                $this->log_error('Event trigger failed', array(
                    'channel' => $channel,
                    'event' => $event,
                    'attempt' => $attempt,
                    'result' => $result
                ));
                
                // Wait before retry
                sleep(self::RETRY_DELAY);
            } catch (Exception $e) {
                $this->log_error('Event trigger exception', array(
                    'message' => $e->getMessage(),
                    'channel' => $channel,
                    'event' => $event,
                    'attempt' => $attempt
                ));
            }
        }
        
        return false;
    }
    
    /**
     * Get event timeout
     *
     * @return int Timeout in seconds
     */
    public function get_event_timeout() {
        return self::EVENT_TIMEOUT;
    }
    
    /**
     * Log successful event trigger
     *
     * @param string $channel Channel name
     * @param string $event Event name
     * @param array $data Event data
     */
    private function log_success($channel, $event, $data) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->logger->info(sprintf(
                'Pusher event triggered successfully: %s - %s',
                $channel,
                $event
            ), array(
                'source' => 'wc_realtime_pusher',
                'channel' => $channel,
                'event' => $event,
                'data_keys' => array_keys($data)
            ));
        }
    }
    
    /**
     * Log error with context
     *
     * @param string $message Error message
     * @param array $context Additional context
     */
    private function log_error($message, $context = array()) {
        $this->logger->error(
            'WC Realtime Analytics Pusher Error: ' . $message, 
            array_merge($context, array(
                'source' => 'wc_realtime_pusher'
            ))
        );
    }
    
    /**
     * Get Pusher key for client-side use
     *
     * @return string Pusher key
     */
    public function get_key() {
        return $this->config['key'];
    }
    
    /**
     * Get Pusher cluster for client-side use
     *
     * @return string Pusher cluster
     */
    public function get_cluster() {
        return $this->config['cluster'];
    }
    
    /**
     * Test Pusher connection
     *
     * @return bool Connection status
     */
    public function test_connection() {
        if (!$this->is_configured()) {
            return false;
        }
        
        try {
            // Send a test event
            $test_result = $this->trigger('wc-analytics', 'connection_test', array(
                'message' => 'Connection test from WooCommerce Real-time Analytics',
                'timestamp' => current_time('mysql')
            ));
            
            return $test_result;
        } catch (Exception $e) {
            $this->log_error('Connection test failed', array(
                'message' => $e->getMessage()
            ));
            return false;
        }
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
        // Sanitize inputs
        $app_id = sanitize_text_field($app_id);
        $key = sanitize_text_field($key);
        $secret = sanitize_text_field($secret);
        $cluster = sanitize_text_field($cluster);
        
        // Update options
        update_option('wc_realtime_pusher_app_id', $app_id);
        update_option('wc_realtime_pusher_key', $key);
        update_option('wc_realtime_pusher_secret', $secret);
        update_option('wc_realtime_pusher_cluster', $cluster);
        
        // Reload configuration
        $this->load_configuration();
        
        // Reinitialize Pusher
        $this->init_pusher();
        
        // Test connection
        return $this->test_connection();
    }
}
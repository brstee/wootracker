<?php
/**
 * Class WC_Realtime_Admin
 * 
 * Handles admin interface for WooCommerce Real-time Analytics
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WC_Realtime_Admin {
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
     * Data handler
     *
     * @var WC_Realtime_Data
     */
    private $data;
    
    /**
     * Constructor
     *
     * @param WC_Realtime_DB $db Database handler
     * @param WC_Realtime_Pusher $pusher Pusher handler
     * @param WC_Realtime_Data $data Data handler (optional)
     */
    public function __construct($db, $pusher, $data = null) {
        $this->db = $db;
        $this->pusher = $pusher;
        $this->data = $data;
        
        // Initialize admin
        $this->init();
    }
    
    /**
     * Initialize admin functionality
     */
    public function init() {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // AJAX handlers for dashboard data
        add_action('wp_ajax_wc_realtime_get_stats', array($this, 'ajax_get_stats'));
    }
    
    /**
     * Add menu items to the admin dashboard
     */
    public function add_admin_menu() {
        // Check if user has proper permissions
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        // Main analytics page
        add_menu_page(
            __('Real-time Analytics', 'wc-realtime-analytics'),
            __('Real-time Analytics', 'wc-realtime-analytics'),
            'manage_woocommerce',
            'wc-realtime-analytics',
            array($this, 'render_dashboard_page'),
            'dashicons-chart-area',
            58
        );
        
        // Settings page
        add_submenu_page(
            'wc-realtime-analytics',
            __('Settings', 'wc-realtime-analytics'),
            __('Settings', 'wc-realtime-analytics'),
            'manage_woocommerce',
            'wc-realtime-analytics-settings',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        // Register settings with WordPress
        register_setting(
            'wc_realtime_analytics',
            'wc_realtime_pusher_app_id',
            array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => '',
            )
        );
        
        register_setting(
            'wc_realtime_analytics',
            'wc_realtime_pusher_key',
            array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => '',
            )
        );
        
        register_setting(
            'wc_realtime_analytics',
            'wc_realtime_pusher_secret',
            array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => '',
            )
        );
        
        register_setting(
            'wc_realtime_analytics',
            'wc_realtime_pusher_cluster',
            array(
                'type' => 'string',
                'sanitize_callback' => array($this, 'sanitize_pusher_cluster'),
                'default' => 'mt1',
            )
        );
    }
    
    /**
     * Sanitize Pusher cluster value
     *
     * @param string $cluster The cluster value to sanitize
     * @return string Sanitized cluster value
     */
    public function sanitize_pusher_cluster($cluster) {
        $valid_clusters = array('mt1', 'us2', 'us3', 'eu', 'ap1', 'ap2', 'ap3', 'ap4');
        
        $cluster = sanitize_text_field($cluster);
        
        if (!in_array($cluster, $valid_clusters, true)) {
            return 'mt1'; // Default to mt1 if invalid
        }
        
        return $cluster;
    }
    
    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page
     */
    public function enqueue_admin_assets($hook) {
        // Only load on plugin pages
        if ($hook != 'toplevel_page_wc-realtime-analytics' && $hook != 'real-time-analytics_page_wc-realtime-analytics-settings') {
            return;
        }
        
        // Check capability
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        // Generate a nonce specifically for admin AJAX requests
        $admin_ajax_nonce = wp_create_nonce('wc_realtime_admin_nonce');
        
        // Enqueue Pusher
        wp_enqueue_script(
            'pusher-js',
            'https://js.pusher.com/7.0/pusher.min.js',
            array(),
            '7.0',
            true
        );
        
        // Enqueue Chart.js
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js',
            array(),
            '3.7.1',
            true
        );
        
        // Enqueue Date Range Picker dependencies
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('jquery-ui', 'https://code.jquery.com/ui/1.13.1/themes/base/jquery-ui.css');
        
        // Enqueue admin script
        wp_enqueue_script(
            'wc-realtime-admin',
            WCRA_PLUGIN_URL . 'assets/js/dashboard.js',
            array('jquery', 'pusher-js', 'chartjs', 'jquery-ui-datepicker'),
            WCRA_VERSION,
            true
        );
        
        // Enqueue charts script
        wp_enqueue_script(
            'wc-realtime-charts',
            WCRA_PLUGIN_URL . 'assets/js/charts.js',
            array('jquery', 'chartjs', 'wc-realtime-admin'),
            WCRA_VERSION,
            true
        );
        
        // Enqueue admin style
        wp_enqueue_style(
            'wc-realtime-admin',
            WCRA_PLUGIN_URL . 'assets/css/dashboard.css',
            array(),
            WCRA_VERSION
        );
        
        // Pass data to JavaScript
        wp_localize_script('wc-realtime-admin', 'wcRealtimeAdmin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => $admin_ajax_nonce,
            'pusher_key' => $this->pusher->get_key(),
            'pusher_cluster' => $this->pusher->get_cluster(),
            'is_pusher_configured' => $this->pusher->is_configured(),
            'locale' => array(
                'loading' => __('Loading...', 'wc-realtime-analytics'),
                'visitors' => __('Visitors', 'wc-realtime-analytics'),
                'add_to_cart' => __('Add to Cart', 'wc-realtime-analytics'),
                'checkout' => __('Checkout', 'wc-realtime-analytics'),
                'purchase' => __('Purchase', 'wc-realtime-analytics'),
                'atc_rate' => __('Add to Cart Rate', 'wc-realtime-analytics'),
                'checkout_rate' => __('Checkout Rate', 'wc-realtime-analytics'),
                'purchase_rate' => __('Purchase Rate', 'wc-realtime-analytics'),
                'product' => __('Product', 'wc-realtime-analytics'),
                'country' => __('Country', 'wc-realtime-analytics'),
                'no_data' => __('No data available for this period', 'wc-realtime-analytics'),
                'error' => __('An error occurred', 'wc-realtime-analytics'),
                'conversion_funnel' => __('Conversion Funnel', 'wc-realtime-analytics'),
            )
        ));
    }
    
    /**
     * Render the main dashboard page
     */
    public function render_dashboard_page() {
        // Check capability
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'wc-realtime-analytics'));
        }
        
        ?>
        <div class="wrap wc-realtime-analytics-wrap">
            <h1><?php _e('WooCommerce Real-time Analytics', 'wc-realtime-analytics'); ?></h1>
            
            <?php
            // Show notice if Pusher is not configured
            if (!$this->pusher->is_configured()) {
                echo '<div class="notice notice-warning inline"><p>';
                echo sprintf(
                    __('Pusher is not fully configured. Real-time updates will not work. Please go to the <a href="%s">settings page</a> to complete the setup.', 'wc-realtime-analytics'),
                    esc_url(admin_url('admin.php?page=wc-realtime-analytics-settings'))
                );
                echo '</p></div>';
            }
            ?>
            
            <div class="wc-realtime-date-filter">
                <select id="wc-realtime-timeframe">
                    <option value="today" selected><?php _e('Today', 'wc-realtime-analytics'); ?></option>
                    <option value="yesterday"><?php _e('Yesterday', 'wc-realtime-analytics'); ?></option>
                    <option value="this_week"><?php _e('This Week', 'wc-realtime-analytics'); ?></option>
                    <option value="this_month"><?php _e('This Month', 'wc-realtime-analytics'); ?></option>
                    <option value="last_7_days"><?php _e('Last 7 Days', 'wc-realtime-analytics'); ?></option>
                    <option value="last_30_days"><?php _e('Last 30 Days', 'wc-realtime-analytics'); ?></option>
                    <option value="custom"><?php _e('Custom Range', 'wc-realtime-analytics'); ?></option>
                </select>
                
                <div id="wc-realtime-custom-range" style="display: none;">
                    <input type="text" id="wc-realtime-date-from" placeholder="<?php _e('From', 'wc-realtime-analytics'); ?>" />
                    <input type="text" id="wc-realtime-date-to" placeholder="<?php _e('To', 'wc-realtime-analytics'); ?>" />
                    <button id="wc-realtime-apply-range" class="button"><?php _e('Apply', 'wc-realtime-analytics'); ?></button>
                </div>
            </div>
            
            <div class="wc-realtime-dashboard">
                <!-- Store Overview Section -->
                <div class="wc-realtime-card wc-realtime-store-overview">
                    <h2><?php _e('Store Overview', 'wc-realtime-analytics'); ?></h2>
                    <div class="wc-realtime-card-content">
                        <div class="wc-realtime-stat-grid">
                            <div class="wc-realtime-stat">
                                <div class="wc-realtime-stat-label"><?php _e('Visitors', 'wc-realtime-analytics'); ?></div>
                                <div class="wc-realtime-stat-value" id="store-visitors">0</div>
                            </div>
                            <div class="wc-realtime-stat">
                                <div class="wc-realtime-stat-label"><?php _e('Add to Cart', 'wc-realtime-analytics'); ?></div>
                                <div class="wc-realtime-stat-value" id="store-add-to-cart">0</div>
                                <div class="wc-realtime-stat-rate" id="store-atc-rate">0%</div>
                            </div>
                            <div class="wc-realtime-stat">
                                <div class="wc-realtime-stat-label"><?php _e('Checkout', 'wc-realtime-analytics'); ?></div>
                                <div class="wc-realtime-stat-value" id="store-checkout">0</div>
                                <div class="wc-realtime-stat-rate" id="store-checkout-rate">0%</div>
                            </div>
                            <div class="wc-realtime-stat">
                                <div class="wc-realtime-stat-label"><?php _e('Purchase', 'wc-realtime-analytics'); ?></div>
                                <div class="wc-realtime-stat-value" id="store-purchase">0</div>
                                <div class="wc-realtime-stat-rate" id="store-purchase-rate">0%</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Live Events Section -->
                <div class="wc-realtime-card wc-realtime-live-events">
                    <h2><?php _e('Live Events', 'wc-realtime-analytics'); ?></h2>
                    <div class="wc-realtime-card-content">
                        <div id="live-events-container">
                            <div class="wc-realtime-empty-state">
                                <?php _e('Waiting for events...', 'wc-realtime-analytics'); ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Chart Section -->
                <div class="wc-realtime-card wc-realtime-chart-container full-width">
                    <h2><?php _e('Conversion Funnel', 'wc-realtime-analytics'); ?></h2>
                    <div class="wc-realtime-card-content">
                        <canvas id="conversion-chart"></canvas>
                    </div>
                </div>
                
                <!-- Products Table Section -->
                <div class="wc-realtime-card wc-realtime-products-table full-width">
                    <h2><?php _e('Products Performance', 'wc-realtime-analytics'); ?></h2>
                    <div class="wc-realtime-card-content">
                        <table class="wc-realtime-table" id="products-table">
                            <thead>
                                <tr>
                                    <th><?php _e('ID', 'wc-realtime-analytics'); ?></th>
                                    <th><?php _e('Product Name', 'wc-realtime-analytics'); ?></th>
                                    <th><?php _e('Visitors', 'wc-realtime-analytics'); ?></th>
                                    <th><?php _e('Add to Cart', 'wc-realtime-analytics'); ?></th>
                                    <th><?php _e('Checkout', 'wc-realtime-analytics'); ?></th>
                                    <th><?php _e('Purchase', 'wc-realtime-analytics'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="6" class="wc-realtime-loading">
                                        <?php _e('Loading...', 'wc-realtime-analytics'); ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Countries Table Section -->
                <div class="wc-realtime-card wc-realtime-countries-table full-width">
                    <h2><?php _e('Traffic by Country', 'wc-realtime-analytics'); ?></h2>
                    <div class="wc-realtime-card-content">
                        <table class="wc-realtime-table" id="countries-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th><?php _e('Country', 'wc-realtime-analytics'); ?></th>
                                    <th><?php _e('Visitors', 'wc-realtime-analytics'); ?></th>
                                    <th><?php _e('Add to Cart', 'wc-realtime-analytics'); ?></th>
                                    <th><?php _e('Checkout', 'wc-realtime-analytics'); ?></th>
                                    <th><?php _e('Purchase', 'wc-realtime-analytics'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="6" class="wc-realtime-loading">
                                        <?php _e('Loading...', 'wc-realtime-analytics'); ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render the settings page
     */
    public function render_settings_page() {
        // Check capability
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'wc-realtime-analytics'));
        }
        
        // Check if settings are being saved
        if (isset($_POST['wc_realtime_save_settings']) && check_admin_referer('wc_realtime_settings_nonce')) {
            $app_id = isset($_POST['wc_realtime_pusher_app_id']) ? sanitize_text_field($_POST['wc_realtime_pusher_app_id']) : '';
            $key = isset($_POST['wc_realtime_pusher_key']) ? sanitize_text_field($_POST['wc_realtime_pusher_key']) : '';
            $secret = isset($_POST['wc_realtime_pusher_secret']) ? sanitize_text_field($_POST['wc_realtime_pusher_secret']) : '';
            $cluster = isset($_POST['wc_realtime_pusher_cluster']) ? $this->sanitize_pusher_cluster($_POST['wc_realtime_pusher_cluster']) : 'mt1';
            
            // Save settings
            if ($this->pusher->save_settings($app_id, $key, $secret, $cluster)) {
                // Show success message
                echo '<div class="notice notice-success is-dismissible"><p>' . 
                     esc_html__('Settings saved successfully.', 'wc-realtime-analytics') . 
                     '</p></div>';
            } else {
                // Show error message
                echo '<div class="notice notice-error is-dismissible"><p>' . 
                     esc_html__('Failed to save settings.', 'wc-realtime-analytics') . 
                     '</p></div>';
            }
        }
        
        ?>
        <div class="wrap">
            <h1><?php _e('WooCommerce Real-time Analytics Settings', 'wc-realtime-analytics'); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('wc_realtime_settings_nonce'); ?>
                
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php _e('Pusher App ID', 'wc-realtime-analytics'); ?></th>
                        <td>
                            <input type="text" name="wc_realtime_pusher_app_id" value="<?php echo esc_attr(get_option('wc_realtime_pusher_app_id')); ?>" class="regular-text" />
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row"><?php _e('Pusher Key', 'wc-realtime-analytics'); ?></th>
                        <td>
                            <input type="text" name="wc_realtime_pusher_key" value="<?php echo esc_attr(get_option('wc_realtime_pusher_key')); ?>" class="regular-text" />
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row"><?php _e('Pusher Secret', 'wc-realtime-analytics'); ?></th>
                        <td>
                            <input type="password" name="wc_realtime_pusher_secret" value="<?php echo esc_attr(get_option('wc_realtime_pusher_secret')); ?>" class="regular-text" />
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row"><?php _e('Pusher Cluster', 'wc-realtime-analytics'); ?></th>
                        <td>
                            <select name="wc_realtime_pusher_cluster">
                                <option value="mt1" <?php selected(get_option('wc_realtime_pusher_cluster'), 'mt1'); ?>>mt1 (US East)</option>
                                <option value="us2" <?php selected(get_option('wc_realtime_pusher_cluster'), 'us2'); ?>>us2 (US East)</option>
                                <option value="us3" <?php selected(get_option('wc_realtime_pusher_cluster'), 'us3'); ?>>us3 (US West)</option>
                                <option value="eu" <?php selected(get_option('wc_realtime_pusher_cluster'), 'eu'); ?>>eu (Europe)</option>
                                <option value="ap1" <?php selected(get_option('wc_realtime_pusher_cluster'), 'ap1'); ?>>ap1 (Asia Pacific)</option>
                                <option value="ap2" <?php selected(get_option('wc_realtime_pusher_cluster'), 'ap2'); ?>>ap2 (Asia Pacific)</option>
                                <option value="ap3" <?php selected(get_option('wc_realtime_pusher_cluster'), 'ap3'); ?>>ap3 (Asia Pacific)</option>
                                <option value="ap4" <?php selected(get_option('wc_realtime_pusher_cluster'), 'ap4'); ?>>ap4 (Asia Pacific)</option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="wc_realtime_save_settings" class="button-primary" value="<?php esc_attr_e('Save Settings', 'wc-realtime-analytics'); ?>" />
                </p>
            </form>
            
            <hr>
            
            <h2><?php _e('Setup Instructions', 'wc-realtime-analytics'); ?></h2>
            <ol>
                <li><?php printf(
                    esc_html__('Create a free account at %s', 'wc-realtime-analytics'),
                    '<a href="https://pusher.com/" target="_blank">Pusher.com</a>'
                ); ?></li>
                <li><?php esc_html_e('Create a new Channels app in your Pusher dashboard', 'wc-realtime-analytics'); ?></li>
                <li><?php esc_html_e('Copy the App ID, Key, and Secret from your Pusher app', 'wc-realtime-analytics'); ?></li>
                <li><?php esc_html_e('Select the appropriate cluster for your region', 'wc-realtime-analytics'); ?></li>
                <li><?php esc_html_e('Enter these details above and save the settings', 'wc-realtime-analytics'); ?></li>
            </ol>
            
            <p><?php esc_html_e('Once configured, real-time analytics will begin tracking automatically.', 'wc-realtime-analytics'); ?></p>
        </div>
        <?php
    }
    
    /**
     * AJAX handler to get statistics
     */
    public function ajax_get_stats() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'wc_realtime_admin_nonce')) {
            wp_send_json_error(array(
                'message' => __('Security check failed', 'wc-realtime-analytics')
            ), 403);
            exit;
        }
        
        // Check capability
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('Permission denied', 'wc-realtime-analytics')
            ), 403);
            exit;
        }
        
        // Get timeframe parameter
        $timeframe = isset($_POST['timeframe']) ? sanitize_text_field($_POST['timeframe']) : 'today';
        
        // Validate timeframe
        $valid_timeframes = array(
            'today', 'yesterday', 'this_week', 'this_month', 
            'last_7_days', 'last_30_days', 'custom'
        );
        
        if (!in_array($timeframe, $valid_timeframes, true)) {
            wp_send_json_error(array(
                'message' => __('Invalid timeframe', 'wc-realtime-analytics')
            ), 400);
            exit;
        }
        
        // Get date range for custom timeframe
        $from_date = '';
        $to_date = '';
        
        if ($timeframe === 'custom') {
            $from_date = isset($_POST['from_date']) ? sanitize_text_field($_POST['from_date']) : '';
            $to_date = isset($_POST['to_date']) ? sanitize_text_field($_POST['to_date']) : '';
            
            // Validate date format
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) {
                wp_send_json_error(array(
                    'message' => __('Invalid date format', 'wc-realtime-analytics')
                ), 400);
                exit;
            }
        }
        
        // Get statistics based on timeframe
        $stats = array();
        
        try {
            switch ($timeframe) {
                case 'today':
                    $stats = $this->db->get_today_stats();
                    break;
                    
                case 'yesterday':
                    $stats = $this->db->get_yesterday_stats();
                    break;
                    
                case 'this_week':
                    $stats = $this->db->get_this_week_stats();
                    break;
                    
                case 'this_month':
                    $stats = $this->db->get_this_month_stats();
                    break;
                    
                case 'last_7_days':
                    $stats = $this->db->get_last_7_days_stats();
                    break;
                    
                case 'last_30_days':
                    $stats = $this->db->get_last_30_days_stats();
                    break;
                    
                case 'custom':
                    if (!empty($from_date) && !empty($to_date)) {
                        $stats = $this->db->get_custom_range_stats($from_date, $to_date);
                    } else {
                        $stats = $this->db->get_today_stats();
                    }
                    break;
                    
                default:
                    $stats = $this->db->get_today_stats();
                    break;
            }
            
            // Ensure we have a properly structured response
            if (!isset($stats['store']) || !isset($stats['products']) || !isset($stats['countries'])) {
                $stats = array(
                    'store' => isset($stats['store']) ? $stats['store'] : array(),
                    'products' => isset($stats['products']) ? $stats['products'] : array(),
                    'countries' => isset($stats['countries']) ? $stats['countries'] : array()
                );
            }
            
            wp_send_json_success($stats);
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => $e->getMessage()
            ), 500);
        }
    }
}
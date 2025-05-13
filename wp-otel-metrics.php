<?php
/**
 * Plugin Name: WP OpenTelemetry Metrics
 * Description: OpenTelemetry metrics for WordPress - Phase 2 Professional Implementation
 * Version: 2.0.0
 * Author: Phil Wilkinson
 * Author URI: www.philwilky.me
 */

namespace WPOtel;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WP_OTEL_VERSION', '2.0.0');
define('WP_OTEL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_OTEL_PLUGIN_URL', plugin_dir_url(__FILE__));

// Import Pro classes if available
use WPOtel\Pro\LicenseManager;
use WPOtel\Pro\BatchManager;
use WPOtel\Pro\ExporterManager;

/**
 * WordPress OpenTelemetry - Phase 2 Version
 * Includes Pro features while maintaining backward compatibility
 */
class MinimalPlugin {
    const VERSION = '2.0.0';
    
    private $config;
    private $current_trace;
    private $start_time;
    
    // Pro features
    private $license_manager;
    private $batch_manager;
    private $exporter_manager;
    private $is_pro = false;
    
    public function __construct() {
        $this->config = $this->get_config();
        
        // Check for Pro features
        if (file_exists(plugin_dir_path(__FILE__) . 'includes/class-license-manager.php')) {
            require_once plugin_dir_path(__FILE__) . 'includes/class-license-manager.php';
            require_once plugin_dir_path(__FILE__) . 'includes/class-batch-manager.php';
            require_once plugin_dir_path(__FILE__) . 'includes/class-exporter-manager.php';
            require_once plugin_dir_path(__FILE__) . 'includes/exporters/class-base-exporter.php';
            require_once plugin_dir_path(__FILE__) . 'includes/exporters/class-jaeger-exporter.php';
            require_once plugin_dir_path(__FILE__) . 'includes/exporters/class-datadog-exporter.php';
            
            $this->license_manager = new LicenseManager();
            $this->is_pro = $this->license_manager->is_valid();
            
            if ($this->is_pro) {
                $pro_config = apply_filters('wpotel_config', $this->config);
                $this->batch_manager = new BatchManager($pro_config);
                $this->exporter_manager = new ExporterManager($pro_config);
            }
        }
        
        $this->init_hooks();
    }
    
    private function get_config() {
        $config = [
            'enabled' => get_option('wpotel_enabled', true),
            'endpoint' => get_option('wpotel_endpoint', 'http://192.168.0.165:9411/api/v2/spans'),
            'service_name' => get_option('wpotel_service_name', parse_url(home_url(), PHP_URL_HOST)),
            'sampling_rate' => get_option('wpotel_sampling_rate', 0.1),
            'environment' => wp_get_environment_type(),
            'debug_mode' => get_option('wpotel_debug_mode', false),
            'log_to_file' => get_option('wpotel_log_to_file', false)
        ];
        
        // Apply Pro config if licensed
        if ($this->is_pro) {
            $config = apply_filters('wpotel_pro_config', $config);
        }
        
        return $config;
    }
    
    private function init_hooks() {
        if (!$this->config['enabled']) return;
        
        // Core tracing
        add_action('init', [$this, 'start_trace'], 1);
        
        // Use appropriate end trace method based on license
        if ($this->is_pro) {
            add_action('shutdown', [$this, 'end_trace_pro'], 9999);
        } else {
            add_action('shutdown', [$this, 'end_trace'], 9999);
        }
        
        // Admin interface
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        
        // Activation hook - needs to be outside the class
        register_activation_hook(__FILE__, __NAMESPACE__ . '\wp_otel_activate');
        
        // Admin notices
        add_action('admin_notices', [$this, 'show_admin_notices']);
        
        // AJAX handlers
        add_action('wp_ajax_wpotel_test_connection', [$this, 'ajax_test_connection']);
        
    // Pro hooks - but enqueue assets for all users on the plugin page
    if ($this->is_pro || is_admin()) {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_pro_assets']);
    }
    
    if ($this->is_pro) {
        add_filter('wpotel_pro_config', [$this, 'add_pro_config']);
        add_action('wp_ajax_wpotel_test_exporter', [$this, 'ajax_test_exporter']);
    }
    
    // This should be available for all users trying to activate a license
    add_action('wp_ajax_wpotel_activate_license', [$this, 'ajax_activate_license']);
}
    
    public function add_pro_config($config) {
        return array_merge($config, [
            'batch_size' => get_option('wpotel_batch_size', 50),
            'export_interval' => get_option('wpotel_export_interval', 5),
            'adaptive_sampling' => get_option('wpotel_adaptive_sampling', true),
            'compression' => get_option('wpotel_compression', true),
            'enabled_exporters' => get_option('wpotel_enabled_exporters', ['jaeger'])
        ]);
    }
    
    public function enqueue_pro_assets($hook) {
        error_log('Hook: ' . $hook); // Debug line to see what hook is being passed
        
        // Check if we're on the plugin's admin page
        if (strpos($hook, 'wp-otel-metrics') === false) {
            return;
        }
        
        wp_enqueue_style(
            'wpotel-pro-admin',
            plugin_dir_url(__FILE__) . 'assets/css/admin.css',
            [],
            self::VERSION
        );
        
        wp_enqueue_script(
            'wpotel-pro-admin',
            plugin_dir_url(__FILE__) . 'assets/js/admin.js',
            ['jquery'],
            self::VERSION,
            true
        );
        
        // Make sure ajaxurl is available
        wp_localize_script('wpotel-pro-admin', 'wpotel_admin', [
            'nonce' => wp_create_nonce('wpotel_admin'),
            'ajax_url' => admin_url('admin-ajax.php'),
            'ajaxurl' => admin_url('admin-ajax.php') // Add this as backup
        ]);
    }
    
    public function start_trace() {
        // Simple sampling decision
        if (mt_rand(1, 1000) > ($this->config['sampling_rate'] * 1000)) {
            if ($this->config['debug_mode']) {
                $this->log('Trace skipped due to sampling rate');
            }
            return;
        }
        
        $this->start_time = microtime(true);
        
        $this->current_trace = [
            'traceID' => $this->generate_id(32),
            'spans' => [[
                'traceID' => $this->generate_id(32), 
                'spanID' => $this->generate_id(16),
                'operationName' => 'wordpress.request',
                'startTime' => $this->start_time * 1000000,
                'tags' => $this->get_request_tags(),
                'process' => [
                    'serviceName' => $this->config['service_name'],
                    'tags' => [
                        ['key' => 'environment', 'value' => $this->config['environment']],
                        ['key' => 'wordpress.version', 'value' => get_bloginfo('version')],
                        ['key' => 'plugin.version', 'value' => self::VERSION]
                    ]
                ]
            ]]
        ];
        
        if ($this->config['debug_mode']) {
            $this->log('Started trace: ' . $this->current_trace['traceID']);
        }
    }
    
    public function end_trace() {
        if (!$this->current_trace) return;
        
        $span = &$this->current_trace['spans'][0];
        $span['duration'] = (microtime(true) * 1000000) - $span['startTime'];
        $span['tags'][] = ['key' => 'http.status_code', 'value' => http_response_code()];
        
        // Add performance metrics
        $memory_peak = memory_get_peak_usage(true);
        $span['tags'][] = ['key' => 'php.memory_peak_mb', 'value' => round($memory_peak / 1048576, 2)];
        
        // Store metric locally for admin dashboard
        $this->store_metric('request_latency', $span['duration'] / 1000); // Convert to ms
        
        // Determine format based on endpoint
        if (strpos($this->config['endpoint'], '9411') !== false) {
            // Zipkin format
            $data = $this->convert_to_zipkin_format();
        } else {
            // Jaeger format
            $data = [$this->current_trace];
        }
        
        // Log trace data if enabled
        if ($this->config['log_to_file']) {
            $this->log('Trace Data: ' . json_encode($data, JSON_PRETTY_PRINT));
        }
        
        // Send trace - don't block
        $response = wp_remote_post($this->config['endpoint'], [
            'body' => json_encode($data),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 1,
            'blocking' => false
        ]);
        
        if ($this->config['debug_mode'] && !is_wp_error($response)) {
            $this->log(sprintf(
                'Trace sent to %s: %s (%.2fms)',
                $this->config['endpoint'],
                $this->current_trace['traceID'],
                $span['duration'] / 1000
            ));
        }
    }
    
    public function end_trace_pro() {
        if (!$this->current_trace) return;
        
        $span = &$this->current_trace['spans'][0];
        $span['duration'] = (microtime(true) * 1000000) - $span['startTime'];
        $span['tags'][] = ['key' => 'http.status_code', 'value' => http_response_code()];
        
        // Add performance metrics
        $memory_peak = memory_get_peak_usage(true);
        $span['tags'][] = ['key' => 'php.memory_peak_mb', 'value' => round($memory_peak / 1048576, 2)];
        
        // Store metric locally for admin dashboard
        $this->store_metric('request_latency', $span['duration'] / 1000); // Convert to ms
        
        // Use batch manager instead of direct export
        $this->batch_manager->add_trace($this->current_trace);
        
        if ($this->config['debug_mode']) {
            $this->log(sprintf(
                'Trace added to batch: %s (%.2fms)',
                $this->current_trace['traceID'],
                $span['duration'] / 1000
            ));
        }
    }
    
    private function convert_to_zipkin_format() {
        $span = $this->current_trace['spans'][0];
        $tags = [];
        
        // Convert tags from array to object
        foreach ($span['tags'] as $tag) {
            $tags[$tag['key']] = (string)$tag['value'];
        }
        
        $zipkin_span = [
            'id' => $span['spanID'],
            'traceId' => $span['traceID'],
            'name' => $span['operationName'],
            'timestamp' => (int)$span['startTime'],
            'duration' => (int)$span['duration'],
            'localEndpoint' => [
                'serviceName' => $this->config['service_name']
            ],
            'tags' => $tags
        ];
        
        return [$zipkin_span];
    }
    
    private function log($message) {
        if ($this->config['debug_mode'] || $this->config['log_to_file']) {
            error_log('[WP OpenTelemetry] ' . $message);
        }
    }
    
    private function get_request_tags() {
        $tags = [
            ['key' => 'http.method', 'value' => $_SERVER['REQUEST_METHOD'] ?? 'GET'],
            ['key' => 'http.url', 'value' => home_url($_SERVER['REQUEST_URI'] ?? '/')],
            ['key' => 'http.user_agent', 'value' => $_SERVER['HTTP_USER_AGENT'] ?? ''],
            ['key' => 'http.remote_addr', 'value' => $_SERVER['REMOTE_ADDR'] ?? ''],
            ['key' => 'wordpress.is_admin', 'value' => is_admin()],
            ['key' => 'wordpress.is_ajax', 'value' => wp_doing_ajax()],
            ['key' => 'wordpress.is_cron', 'value' => wp_doing_cron()],
        ];
        
        // Add WordPress-specific context
        if (is_singular()) {
            $tags[] = ['key' => 'wordpress.post_id', 'value' => get_the_ID()];
            $tags[] = ['key' => 'wordpress.post_type', 'value' => get_post_type()];
        }
        
        if (is_author()) {
            $tags[] = ['key' => 'wordpress.author_id', 'value' => get_queried_object_id()];
        }
        
        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if ($term) {
                $tags[] = ['key' => 'wordpress.term_id', 'value' => $term->term_id];
                $tags[] = ['key' => 'wordpress.taxonomy', 'value' => $term->taxonomy];
            }
        }
        
        return $tags;
    }
    
    private function generate_id($hex_length) {
        return bin2hex(random_bytes($hex_length / 2));
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'OpenTelemetry Metrics',
            'OTel Metrics',
            'manage_options',
            'wp-otel-metrics',
            [$this, 'render_admin_page'],
            'dashicons-chart-area',
            100
        );
    }
    public function register_settings() {
        register_setting('wpotel_settings', 'wpotel_enabled');
        register_setting('wpotel_settings', 'wpotel_endpoint');
        register_setting('wpotel_settings', 'wpotel_service_name');
        register_setting('wpotel_settings', 'wpotel_sampling_rate');
        register_setting('wpotel_settings', 'wpotel_debug_mode');
        register_setting('wpotel_settings', 'wpotel_log_to_file');
        register_setting('wpotel_settings', 'wpotel_license_key');
        
        // Pro settings
        if ($this->is_pro) {
            register_setting('wpotel_settings', 'wpotel_batch_size');
            register_setting('wpotel_settings', 'wpotel_export_interval');
            register_setting('wpotel_settings', 'wpotel_adaptive_sampling');
            register_setting('wpotel_settings', 'wpotel_compression');
            register_setting('wpotel_settings', 'wpotel_enabled_exporters');
        }
        
        add_settings_section(
            'wpotel_settings_section',
            'OpenTelemetry Settings',
            [$this, 'settings_section_callback'],
            'wp-otel-metrics'
        );
        
        add_settings_field(
            'wpotel_enabled',
            'Enable Tracing',
            [$this, 'enabled_callback'],
            'wp-otel-metrics',
            'wpotel_settings_section'
        );
        
        add_settings_field(
            'wpotel_endpoint',
            'Tracing Endpoint',
            [$this, 'endpoint_callback'],
            'wp-otel-metrics',
            'wpotel_settings_section'
        );
        
        add_settings_field(
            'wpotel_service_name',
            'Service Name',
            [$this, 'service_name_callback'],
            'wp-otel-metrics',
            'wpotel_settings_section'
        );
        
        add_settings_field(
            'wpotel_sampling_rate',
            'Sampling Rate',
            [$this, 'sampling_rate_callback'],
            'wp-otel-metrics',
            'wpotel_settings_section'
        );
        
        add_settings_field(
            'wpotel_debug_mode',
            'Debug Mode',
            [$this, 'debug_mode_callback'],
            'wp-otel-metrics',
            'wpotel_settings_section'
        );
        
        add_settings_field(
            'wpotel_log_to_file',
            'Log Traces to File',
            [$this, 'log_to_file_callback'],
            'wp-otel-metrics',
            'wpotel_settings_section'
        );
        
        // Add test button
        add_settings_field(
            'wpotel_test',
            'Test Connection',
            [$this, 'test_connection_callback'],
            'wp-otel-metrics',
            'wpotel_settings_section'
        );
        
        // Pro settings section
        if ($this->is_pro) {
            add_settings_section(
                'wpotel_pro_settings',
                'Professional Settings',
                function() {
                    echo '<p>Advanced configuration options for Pro users.</p>';
                },
                'wp-otel-pro'
            );
            
            add_settings_field(
                'wpotel_batch_size',
                'Batch Size',
                function() {
                    $value = get_option('wpotel_batch_size', 50);
                    echo '<input type="number" name="wpotel_batch_size" value="' . esc_attr($value) . '" min="1" max="500" />';
                    echo '<p class="description">Number of traces to batch before sending (reduces network overhead)</p>';
                },
                'wp-otel-pro',
                'wpotel_pro_settings'
            );
            
            add_settings_field(
                'wpotel_export_interval',
                'Export Interval',
                function() {
                    $value = get_option('wpotel_export_interval', 5);
                    echo '<input type="number" name="wpotel_export_interval" value="' . esc_attr($value) . '" min="1" max="60" />';
                    echo '<p class="description">Seconds between batch exports</p>';
                },
                'wp-otel-pro',
                'wpotel_pro_settings'
            );
        }
    }
    
    public function settings_section_callback() {
        echo '<p>Configure your OpenTelemetry tracing settings. Start with the free Jaeger backend or connect to your existing observability platform.</p>';
    }
    
    public function enabled_callback() {
        $enabled = get_option('wpotel_enabled', true);
        echo '<input type="checkbox" name="wpotel_enabled" value="1" ' . checked(1, $enabled, false) . ' />';
        echo '<p class="description">Enable or disable OpenTelemetry tracing</p>';
    }
    
    public function endpoint_callback() {
        $endpoint = get_option('wpotel_endpoint', 'http://192.168.0.165:9411/api/v2/spans');
        echo '<input type="text" name="wpotel_endpoint" value="' . esc_attr($endpoint) . '" class="regular-text" />';
        echo '<p class="description">Tracing endpoint URL. Common endpoints:</p>';
        echo '<ul class="description">';
        echo '<li>For Local by Flywheel (Windows): <code>http://host.docker.internal:14268/api/traces</code></li>';
        echo '<li>For Local by Flywheel (Mac): <code>http://docker.for.mac.localhost:14268/api/traces</code></li>';
        echo '<li>Standard Docker: <code>http://localhost:14268/api/traces</code></li>';
        echo '<li>Zipkin format: <code>http://host.docker.internal:9411/api/v2/spans</code></li>';
        echo '<li>Working Local laptop: <code>http://192.168.0.165:9411/api/v2/spans</code></li>';
        echo '</ul>';
    }
    
    public function service_name_callback() {
        $service_name = get_option('wpotel_service_name', parse_url(home_url(), PHP_URL_HOST));
        echo '<input type="text" name="wpotel_service_name" value="' . esc_attr($service_name) . '" class="regular-text" />';
        echo '<p class="description">Service name to identify your WordPress site</p>';
    }
    
    public function sampling_rate_callback() {
        $sampling_rate = get_option('wpotel_sampling_rate', 0.1);
        echo '<input type="number" name="wpotel_sampling_rate" value="' . esc_attr($sampling_rate) . '" min="0" max="1" step="0.01" />';
        echo '<p class="description">Sampling rate (0.0 to 1.0). Default: 0.1 (10% of requests)</p>';
    }
    
    public function debug_mode_callback() {
        $debug_mode = get_option('wpotel_debug_mode', false);
        echo '<input type="checkbox" name="wpotel_debug_mode" value="1" ' . checked(1, $debug_mode, false) . ' />';
        echo '<p class="description">Enable debug mode to log trace information to your PHP error log</p>';
    }
    
    public function log_to_file_callback() {
        $log_to_file = get_option('wpotel_log_to_file', false);
        echo '<input type="checkbox" name="wpotel_log_to_file" value="1" ' . checked(1, $log_to_file, false) . ' />';
        echo '<p class="description">Log full trace data to your PHP error log (generates lots of output!)</p>';
        
        // Show the log file location if possible
        $log_file = ini_get('error_log');
        if ($log_file) {
            echo '<p class="description">Your log file location: <code>' . esc_html($log_file) . '</code></p>';
        }
    }
    
    public function test_connection_callback() {
        ?>
        <button type="button" id="test-jaeger-connection" class="button">Test Connection</button>
        <span id="test-result"></span>
        <script>
        jQuery(document).ready(function($) {
            $('#test-jaeger-connection').on('click', function() {
                var button = $(this);
                var resultSpan = $('#test-result');
                
                button.prop('disabled', true);
                resultSpan.html('<span style="color: blue;">Testing...</span>');
                
                $.post(ajaxurl, {
                    action: 'wpotel_test_connection',
                    _ajax_nonce: '<?php echo wp_create_nonce('wpotel_test_connection'); ?>'
                }, function(response) {
                    if (response.success) {
                        resultSpan.html('<span style="color: green;">' + response.data + '</span>');
                    } else {
                        resultSpan.html('<span style="color: red;">' + response.data + '</span>');
                    }
                    button.prop('disabled', false);
                });
            });
        });
        </script>
        <?php
    }
    
    public function ajax_test_connection() {
        check_ajax_referer('wpotel_test_connection');
        
        if (!current_user_can('manage_options')) {
            wp_die();
        }
        
        $endpoint = get_option('wpotel_endpoint', 'http://localhost:14268/api/traces');
        
        // Create a test trace
        $test_trace = [
            'traceID' => $this->generate_id(32),
            'spans' => [[
                'traceID' => $this->generate_id(32),
                'spanID' => $this->generate_id(16),
                'operationName' => 'test.connection',
                'startTime' => microtime(true) * 1000000,
                'duration' => 1000,
                'tags' => [
                    ['key' => 'test', 'value' => true],
                    ['key' => 'service.name', 'value' => $this->config['service_name']]
                ],
                'process' => [
                    'serviceName' => $this->config['service_name'],
                    'tags' => [
                        ['key' => 'test', 'value' => 'connection test']
                    ]
                ]
            ]]
        ];
        
        // Use correct format based on endpoint
        if (strpos($endpoint, '9411') !== false) {
            $data = $this->convert_to_zipkin_format_test($test_trace);
        } else {
            $data = [$test_trace];
        }
        
        $response = wp_remote_post($endpoint, [
            'body' => json_encode($data),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 5,
            'blocking' => true
        ]);
        
        if (is_wp_error($response)) {
            wp_send_json_error('Connection failed: ' . $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code === 202 || $status_code === 200) {
            wp_send_json_success('Connection successful! Check Jaeger UI for test trace.');
        } else {
            wp_send_json_error("Connection failed: HTTP $status_code - $body");
        }
    }
    
    public function ajax_test_exporter() {
        check_ajax_referer('wpotel_admin');
        
        if (!current_user_can('manage_options')) {
            wp_die();
        }
        
        $exporter = $_POST['exporter'] ?? '';
        
        // Create test trace
        $test_trace = [
            'traceID' => $this->generate_id(32),
            'spans' => [[
                'traceID' => $this->generate_id(32),
                'spanID' => $this->generate_id(16),
                'operationName' => 'test.exporter.' . $exporter,
                'startTime' => microtime(true) * 1000000,
                'duration' => 1000,
                'tags' => [
                    ['key' => 'test', 'value' => true],
                    ['key' => 'exporter', 'value' => $exporter]
                ]
            ]]
        ];
        
        // Test the specific exporter
        $exporters = $this->exporter_manager->get_available_exporters();
        if (isset($exporters[$exporter])) {
            // Temporarily enable only this exporter
            $original_exporters = get_option('wpotel_enabled_exporters');
            update_option('wpotel_enabled_exporters', [$exporter]);
            
            // Export test trace
            $this->exporter_manager->export_to_all([$test_trace]);
            
            // Restore original exporters
            update_option('wpotel_enabled_exporters', $original_exporters);
            
            wp_send_json_success('Test trace sent to ' . $exporters[$exporter]['name']);
        } else {
            wp_send_json_error('Unknown exporter: ' . $exporter);
        }
    }
    
    public function ajax_activate_license() {
        // Verify nonce
        check_ajax_referer('wpotel_admin', '_ajax_nonce');
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            wp_die();
        }
        
        // Get the license key from POST data
        $license_key = isset($_POST['license_key']) ? sanitize_text_field($_POST['license_key']) : '';
        
        if (empty($license_key)) {
            wp_send_json_error('Please enter a license key');
            return;
        }
        
        // For testing, use simple validation with hardcoded keys
        $valid_licenses = ['DEMO-PRO-KEY', 'TEST-LICENSE-123'];
        
        if (in_array($license_key, $valid_licenses)) {
            // Save the license key and status
            update_option('wpotel_license_key', $license_key);
            update_option('wpotel_license_status', 'active');
            update_option('wpotel_license_expires', date('Y-m-d', strtotime('+1 year')));
            
            // In production, you would make an API call to your license server here
            // $response = wp_remote_post($this->api_url . 'activate', [
            //     'body' => [
            //         'license_key' => $license_key,
            //         'product_id' => 'wp-otel-pro',
            //         'site_url' => home_url()
            //     ]
            // ]);
            
            wp_send_json_success('License activated successfully!');
        } else {
            // License key is invalid
            update_option('wpotel_license_key', $license_key);
            update_option('wpotel_license_status', 'inactive');
            
            wp_send_json_error('Invalid license key. Please use DEMO-PRO-KEY or TEST-LICENSE-123 for testing.');
        }
    }
    
    private function convert_to_zipkin_format_test($trace) {
        $span = $trace['spans'][0];
        $tags = [];
        
        foreach ($span['tags'] as $tag) {
            $tags[$tag['key']] = (string)$tag['value'];
        }
        
        return [[
            'id' => $span['spanID'],
            'traceId' => $span['traceID'],
            'name' => $span['operationName'],
            'timestamp' => (int)$span['startTime'],
            'duration' => (int)$span['duration'],
            'localEndpoint' => [
                'serviceName' => $this->config['service_name']
            ],
            'tags' => $tags
        ]];
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $metrics = get_option('wpotel_metrics', []);
        $latency_metrics = $metrics['request_latency'] ?? [];
        
        // Debug: Show raw metric count
        $debug_info = "Total metrics stored: " . count($latency_metrics);
        
        // Calculate stats
        $total_requests = count($latency_metrics);
        $avg_latency = 0;
        $min_latency = PHP_INT_MAX;
        $max_latency = 0;
        
        if ($total_requests > 0) {
            $total = 0;
            foreach ($latency_metrics as $metric) {
                $value = $metric['value'];
                $total += $value;
                $min_latency = min($min_latency, $value);
                $max_latency = max($max_latency, $value);
            }
            $avg_latency = $total / $total_requests;
        }
        
        ?>
        <div class="wrap">
            <h1>
                OpenTelemetry Metrics 
                <?php if ($this->is_pro): ?>
                    <span class="wpotel-pro-badge">PRO</span>
                <?php endif; ?>
            </h1>
            
            <?php settings_errors('wpotel_settings'); ?>
            
            <?php if (!$this->is_pro): ?>
            <div class="notice notice-info">
                <p>
                    <strong>Unlock Pro Features!</strong> 
                    Get multiple exporters, batching, compression, and more.
                    <a href="#license" class="button button-primary">Enter License Key</a>
                </p>
            </div>
            <?php endif; ?>
            
            <!-- Tab Navigation -->
            <h2 class="nav-tab-wrapper">
                <a href="#settings" class="nav-tab nav-tab-active">Settings</a>
                <?php if ($this->is_pro): ?>
                <a href="#exporters" class="nav-tab">Exporters</a>
                <a href="#performance" class="nav-tab">Performance</a>
                <?php endif; ?>
                <a href="#license" class="nav-tab">License</a>
            </h2>
            
            <form method="post" action="options.php">
                <!-- Settings Tab -->
                <div id="settings" class="wpotel-tab-content">
                    <?php
                    settings_fields('wpotel_settings');
                    do_settings_sections('wp-otel-metrics');
                    
                    if ($this->is_pro) {
                        do_settings_sections('wp-otel-pro');
                    }
                    
                    submit_button();
                    ?>
                </div>
                
                <?php if ($this->is_pro): ?>
                <!-- Exporters Tab -->
                <div id="exporters" class="wpotel-tab-content" style="display:none;">
                    <h2>Configure Exporters</h2>
                    <p>Select and configure the backends where you want to send your trace data.</p>
                    
                    <?php
                    $exporters = $this->exporter_manager->get_available_exporters();
                    $enabled_exporters = get_option('wpotel_enabled_exporters', ['jaeger']);
                    
                    foreach ($exporters as $key => $exporter):
                    ?>
                    <div class="wpotel-exporter-card">
                        <h3>
                            <input type="checkbox" name="wpotel_enabled_exporters[]" value="<?php echo $key; ?>" 
                                <?php checked(in_array($key, $enabled_exporters)); ?> />
                            <?php echo $exporter['name']; ?>
                        </h3>
                        <p><?php echo $exporter['description']; ?></p>
                        
                        <?php if (in_array($key, $enabled_exporters)): ?>
                        <table class="form-table">
                            <?php foreach ($exporter['settings'] as $setting_key => $setting): ?>
                            <tr>
                                <th scope="row"><?php echo $setting['label']; ?></th>
                                <td>
                                    <?php if ($setting['type'] === 'select'): ?>
                                        <select name="wpotel_exporter_<?php echo $key; ?>_<?php echo $setting_key; ?>">
                                            <?php foreach ($setting['options'] as $option_value => $option_label): ?>
                                            <option value="<?php echo $option_value; ?>" 
                                                <?php selected(get_option('wpotel_exporter_' . $key . '_' . $setting_key), $option_value); ?>>
                                                <?php echo $option_label; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <input type="<?php echo $setting['type']; ?>" 
                                               name="wpotel_exporter_<?php echo $key; ?>_<?php echo $setting_key; ?>" 
                                               value="<?php echo esc_attr(get_option('wpotel_exporter_' . $key . '_' . $setting_key, $setting['default'])); ?>" 
                                               class="regular-text" />
                                    <?php endif; ?>
                                    <p class="description"><?php echo $setting['description']; ?></p>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                        <button type="button" class="button test-exporter-connection" data-exporter="<?php echo $key; ?>">
                            Test Connection
                        </button>
                        <span id="test-result-<?php echo $key; ?>"></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php submit_button(); ?>
                </div>
                
                <!-- Performance Tab -->
                <div id="performance" class="wpotel-tab-content" style="display:none;">
                    <h2>Performance Dashboard</h2>
                    
                    <div class="card">
                        <h3>Real-time Metrics</h3>
                        <div class="wpotel-metrics-grid">
                            <div class="wpotel-metric">
                                <span class="wpotel-metric-label">Total Requests Traced</span>
                                <span class="wpotel-metric-value"><?php echo number_format($total_requests); ?></span>
                            </div>
                            <div class="wpotel-metric">
                                <span class="wpotel-metric-label">Average Latency</span>
                                <span class="wpotel-metric-value <?php echo $avg_latency > 500 ? 'warning' : ''; ?>">
                                    <?php echo number_format($avg_latency, 2); ?> ms
                                </span>
                            </div>
                            <div class="wpotel-metric">
                                <span class="wpotel-metric-label">Min Latency</span>
                                <span class="wpotel-metric-value"><?php echo number_format($min_latency == PHP_INT_MAX ? 0 : $min_latency, 2); ?> ms</span>
                            </div>
                            <div class="wpotel-metric">
                                <span class="wpotel-metric-label">Max Latency</span>
                                <span class="wpotel-metric-value <?php echo $max_latency > 1000 ? 'error' : ''; ?>">
                                    <?php echo number_format($max_latency, 2); ?> ms
                                </span>
                            </div>
                        </div>
                        
                        <?php if (!empty($latency_metrics)): ?>
                        <canvas id="latencyChart" width="400" height="200" style="margin-top: 20px;"></canvas>
                        <?php else: ?>
                        <p class="description">No metrics collected yet. Generate some traffic to see metrics.</p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="card" style="margin-top: 20px;">
                        <h3>Export Statistics</h3>
                        <p><strong>Batch Size:</strong> <?php echo get_option('wpotel_batch_size', 50); ?> traces</p>
                        <p><strong>Export Interval:</strong> Every <?php echo get_option('wpotel_export_interval', 5); ?> seconds</p>
                        <p><strong>Compression:</strong> <?php echo get_option('wpotel_compression', true) ? 'Enabled' : 'Disabled'; ?></p>
                        <p><strong>Adaptive Sampling:</strong> <?php echo get_option('wpotel_adaptive_sampling', true) ? 'Enabled' : 'Disabled'; ?></p>
                    </div>
                </div>
                <?php endif; ?>
                
<!-- License Tab -->
<div id="license" class="wpotel-tab-content" style="display:none;">
    <h2>License Management</h2>
    
    <table class="form-table">
        <tr>
            <th scope="row">License Key</th>
            <td>
                <input type="text" id="wpotel_license_key" 
                       value="<?php echo esc_attr(get_option('wpotel_license_key', '')); ?>" 
                       class="regular-text" />
                <?php if ($this->is_pro): ?>
                    <span style="color: green;">✓ Active</span>
                    <p class="description">License expires: <?php echo date('F j, Y', strtotime(get_option('wpotel_license_expires', '+1 year'))); ?></p>
                <?php else: ?>
                    <button type="button" id="activate-license" class="button">
                        Activate License
                    </button>
                    <span id="license-result"></span>
                <?php endif; ?>
            </td>
        </tr>
    </table>
    
    <?php if (!$this->is_pro): ?>
    <div class="card" style="margin-top: 20px;">
        <h3>Pro Features</h3>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th>Feature</th>
                    <th>Free</th>
                    <th>Pro</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Basic Tracing</td>
                    <td style="color: green;">✓</td>
                    <td style="color: green;">✓</td>
                </tr>
                <tr>
                    <td>Multiple Exporters</td>
                    <td style="color: red;">✗</td>
                    <td style="color: green;">✓</td>
                </tr>
                <tr>
                    <td>Trace Batching</td>
                    <td style="color: red;">✗</td>
                    <td style="color: green;">✓</td>
                </tr>
                <tr>
                    <td>Data Compression</td>
                    <td style="color: red;">✗</td>
                    <td style="color: green;">✓</td>
                </tr>
                <tr>
                    <td>Adaptive Sampling</td>
                    <td style="color: red;">✗</td>
                    <td style="color: green;">✓</td>
                </tr>
                <tr>
                    <td>Priority Support</td>
                    <td style="color: red;">✗</td>
                    <td style="color: green;">✓</td>
                </tr>
            </tbody>
        </table>
        <p style="margin-top: 20px;">
            <a href="https://yoursite.com/pricing" target="_blank" class="button button-primary">
                Get Pro License - $29/month
            </a>
        </p>
    </div>
    <?php endif; ?>
</div>
            
            <div class="card" style="margin-top: 20px;">
                <h2>Request Performance</h2>
                <p class="description"><?php echo $debug_info; ?></p>
                <p><strong>Total Requests Traced:</strong> <?php echo number_format($total_requests); ?></p>
                <p><strong>Average Latency:</strong> <?php echo number_format($avg_latency, 2); ?> ms</p>
                <p><strong>Min Latency:</strong> <?php echo number_format($min_latency == PHP_INT_MAX ? 0 : $min_latency, 2); ?> ms</p>
                <p><strong>Max Latency:</strong> <?php echo number_format($max_latency, 2); ?> ms</p>
                
                <?php if (!empty($latency_metrics)): ?>
                    <canvas id="latencyChartMain" width="400" height="200" style="margin-top: 20px;"></canvas>
                <?php else: ?>
                    <p class="description">No metrics collected yet. Generate some traffic to see metrics.</p>
                <?php endif; ?>
            </div>
            
            <div class="card" style="margin-top: 20px; background-color: #f0f8ff; border-left: 4px solid #0073aa;">
                <h2>Quick Start Guide</h2>
                <ol>
                    <li><strong>Install Jaeger:</strong> <code>docker run -d --name jaeger -e COLLECTOR_ZIPKIN_HOST_PORT=:9411 -p 16686:16686 -p 14268:14268 -p 9411:9411 jaegertracing/all-in-one</code></li>
                    <li><strong>Configure Endpoint:</strong> Set the endpoint to:
                        <ul>
                            <li>For Local by Flywheel: <code>http://192.168.0.165:9411/api/v2/spans</code> (use your machine's IP)</li>
                            <li>For standard Docker: <code>http://localhost:9411/api/v2/spans</code></li>
                        </ul>
                    </li>
                    <li><strong>Set Sampling Rate:</strong> Start with 0.1 (10%) for production sites</li>
                    <li><strong>View Traces:</strong> Open Jaeger UI at <code>http://localhost:16686</code></li>
                </ol>
            </div>
            
            <?php if ($this->config['debug_mode']): ?>
            <div class="card" style="margin-top: 20px; background-color: #fff3cd; border-left: 4px solid #ffc107;">
                <h2>Debug Information</h2>
                <p><strong>Debug Mode:</strong> Enabled</p>
                <p><strong>Log to File:</strong> <?php echo $this->config['log_to_file'] ? 'Enabled' : 'Disabled'; ?></p>
                <p><strong>PHP Error Log:</strong> <code><?php echo esc_html(ini_get('error_log')); ?></code></p>
                <p><strong>Current Settings:</strong></p>
                <pre><?php echo esc_html(json_encode($this->config, JSON_PRETTY_PRINT)); ?></pre>
                
                <?php if ($this->current_trace): ?>
                <p><strong>Last Trace ID:</strong> <?php echo esc_html($this->current_trace['traceID'] ?? 'None'); ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($latency_metrics)): ?>
            <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Main chart
                var ctx = document.getElementById('latencyChartMain')?.getContext('2d');
                if (ctx) {
                    var data = <?php echo json_encode(array_map(function($metric) {
                        return [
                            'x' => date('H:i:s', $metric['timestamp']),
                            'y' => $metric['value']
                        ];
                    }, array_slice($latency_metrics, -50))); ?>;
                    
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            datasets: [{
                                label: 'Request Latency (ms)',
                                data: data,
                                borderColor: 'rgb(75, 192, 192)',
                                tension: 0.1
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                title: {
                                    display: true,
                                    text: 'Last 50 Requests'
                                }
                            },
                            scales: {
                                x: {
                                    type: 'category',
                                    title: {
                                        display: true,
                                        text: 'Time'
                                    }
                                },
                                y: {
                                    title: {
                                        display: true,
                                        text: 'Latency (ms)'
                                    },
                                    beginAtZero: true
                                }
                            }
                        }
                    });
                }
                
                // Performance tab chart (if Pro)
                <?php if ($this->is_pro): ?>
                var perfCtx = document.getElementById('latencyChart')?.getContext('2d');
                if (perfCtx) {
                    new Chart(perfCtx, {
                        type: 'line',
                        data: {
                            datasets: [{
                                label: 'Request Latency (ms)',
                                data: data,
                                borderColor: 'rgb(75, 192, 192)',
                                tension: 0.1
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                title: {
                                    display: true,
                                    text: 'Performance Over Time'
                                }
                            },
                            scales: {
                                x: {
                                    type: 'category',
                                    title: {
                                        display: true,
                                        text: 'Time'
                                    }
                                },
                                y: {
                                    title: {
                                        display: true,
                                        text: 'Latency (ms)'
                                    },
                                    beginAtZero: true
                                }
                            }
                        }
                    });
                }
                <?php endif; ?>
            });
            </script>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function store_metric($metric_name, $value) {
        $metrics = get_option('wpotel_metrics', []);
        
        if (!isset($metrics[$metric_name])) {
            $metrics[$metric_name] = [];
        }
        
        $metrics[$metric_name][] = [
            'timestamp' => current_time('timestamp'),
            'value' => $value
        ];
        
        // Keep only last 1000 entries
        if (count($metrics[$metric_name]) > 1000) {
            $metrics[$metric_name] = array_slice($metrics[$metric_name], -1000);
        }
        
        update_option('wpotel_metrics', $metrics);
    }
    
    public function show_admin_notices() {
        if (get_transient('wpotel_welcome_notice')) {
            ?>
            <div class="notice notice-info is-dismissible">
                <p><strong>Welcome to WP OpenTelemetry <?php echo $this->is_pro ? 'Pro' : ''; ?>!</strong></p>
                <p>Get started by <a href="<?php echo admin_url('admin.php?page=wp-otel-metrics'); ?>">configuring your settings</a>.</p>
                <?php if (!$this->is_pro): ?>
                <p>Upgrade to Pro for advanced features like multiple exporters, batching, and more!</p>
                <?php endif; ?>
            </div>
            <?php
            delete_transient('wpotel_welcome_notice');
        }
    }
}

// Activation function - needs to be outside the class
function wp_otel_activate() {
    // Set up default options
    add_option('wpotel_enabled', true);
    add_option('wpotel_endpoint', 'http://192.168.0.165:9411/api/v2/spans');
    add_option('wpotel_service_name', parse_url(home_url(), PHP_URL_HOST));
    add_option('wpotel_sampling_rate', 0.1);
    add_option('wpotel_first_install', current_time('mysql'));
    
    // Check if this is an upgrade from Phase 1
    $existing_version = get_option('wpotel_version');
    if ($existing_version && version_compare($existing_version, '2.0.0', '<')) {
        add_option('wpotel_upgraded_from_v1', true);
    }
    
    // Update version
    update_option('wpotel_version', '2.0.0');
    
    // Show welcome notice
    set_transient('wpotel_welcome_notice', true, 5);
}

// Initialize the plugin
function wp_otel_init() {
    $plugin = new MinimalPlugin();
}
add_action('plugins_loaded', __NAMESPACE__ . '\wp_otel_init');
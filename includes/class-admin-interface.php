<?php
/**
 * Admin Interface for WP OpenTelemetry
 */

namespace WPOtel;

class AdminInterface {
    
    private $core_plugin;
    private $is_pro;
    private $config;
    
    public function __construct($core_plugin, $is_pro = false) {
        $this->core_plugin = $core_plugin;
        $this->is_pro = $is_pro;
        $this->config = $core_plugin->get_config();
    }
    
    public function init() {
        // Admin interface hooks
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'show_admin_notices']);
        
        // AJAX handlers
        add_action('wp_ajax_wpotel_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_wpotel_activate_license', [$this, 'ajax_activate_license']);
        
        // Pro hooks
        if ($this->is_pro || is_admin()) {
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        }
        
        if ($this->is_pro) {
            add_action('wp_ajax_wpotel_test_exporter', [$this, 'ajax_test_exporter']);
        }
    }
    
    public function enqueue_admin_assets($hook) {
        // Check if we're on the plugin's admin page
        if (strpos($hook, 'wp-otel-metrics') === false) {
            return;
        }
        
        wp_enqueue_style(
            'wpotel-admin',
            WP_OTEL_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WP_OTEL_VERSION
        );
        
        wp_enqueue_script(
            'wpotel-admin',
            WP_OTEL_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            WP_OTEL_VERSION,
            true
        );
        
        wp_localize_script('wpotel-admin', 'wpotel_admin', [
            'nonce' => wp_create_nonce('wpotel_admin'),
            'ajax_url' => admin_url('admin-ajax.php'),
            'ajaxurl' => admin_url('admin-ajax.php')
        ]);
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
        
        add_submenu_page(
            'wp-otel-metrics',
            'Plugin Tests',
            'Run Tests',
            'manage_options',
            'wp-otel-metrics-tests',
            [$this, 'render_test_page']
        );
    }
    
    public function register_settings() {
        // Register all settings
        $settings = [
            'wpotel_enabled',
            'wpotel_endpoint', 
            'wpotel_service_name',
            'wpotel_sampling_rate',
            'wpotel_debug_mode',
            'wpotel_log_to_file',
            'wpotel_license_key',
            'wpotel_trace_database'
        ];
        
        foreach ($settings as $setting) {
            register_setting('wpotel_settings', $setting);
        }
        
        // Pro settings
        if ($this->is_pro) {
            $pro_settings = [
                'wpotel_batch_size',
                'wpotel_export_interval', 
                'wpotel_adaptive_sampling',
                'wpotel_compression',
                'wpotel_enabled_exporters'
            ];
            
            foreach ($pro_settings as $setting) {
                register_setting('wpotel_settings', $setting);
            }
        }
        
        // Add settings sections and fields
        $this->add_settings_sections();
        $this->add_settings_fields();
    }
    
    private function add_settings_sections() {
        add_settings_section(
            'wpotel_settings_section',
            'OpenTelemetry Settings',
            [$this, 'settings_section_callback'],
            'wp-otel-metrics'
        );
        
        if ($this->is_pro) {
            add_settings_section(
                'wpotel_pro_settings',
                'Professional Settings',
                function() {
                    echo '<p>Advanced configuration options for Pro users.</p>';
                },
                'wp-otel-pro'
            );
        }
    }
    
    private function add_settings_fields() {
        $fields = [
            'wpotel_enabled' => ['Enable Tracing', 'enabled_callback'],
            'wpotel_endpoint' => ['Tracing Endpoint', 'endpoint_callback'],
            'wpotel_service_name' => ['Service Name', 'service_name_callback'],
            'wpotel_sampling_rate' => ['Sampling Rate', 'sampling_rate_callback'],
            'wpotel_debug_mode' => ['Debug Mode', 'debug_mode_callback'],
            'wpotel_log_to_file' => ['Log Traces to File', 'log_to_file_callback'],
            'wpotel_trace_database' => ['Trace Database Queries', 'trace_database_callback'],
            'wpotel_test' => ['Test Connection', 'test_connection_callback']
        ];
        
        foreach ($fields as $field_id => [$label, $callback]) {
            add_settings_field(
                $field_id,
                $label,
                [$this, $callback],
                'wp-otel-metrics',
                'wpotel_settings_section'
            );
        }
        
        // Pro fields
        if ($this->is_pro) {
            $pro_fields = [
                'wpotel_batch_size' => 'Batch Size',
                'wpotel_export_interval' => 'Export Interval'
            ];
            
            foreach ($pro_fields as $field_id => $label) {
                add_settings_field(
                    $field_id,
                    $label,
                    [$this, 'render_pro_field'],
                    'wp-otel-pro',
                    'wpotel_pro_settings',
                    ['field_id' => $field_id, 'label' => $label]
                );
            }
        }
    }
    
    // Settings callbacks
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
        echo '<li>For Local by Flywheel: <code>http://192.168.0.165:9411/api/v2/spans</code> (use your machine\'s IP)</li>';
        echo '<li>For standard Docker: <code>http://localhost:9411/api/v2/spans</code></li>';
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
        
        $log_file = ini_get('error_log');
        if ($log_file) {
            echo '<p class="description">Your log file location: <code>' . esc_html($log_file) . '</code></p>';
        }
    }
    
    public function trace_database_callback() {
        $trace_database = get_option('wpotel_trace_database', true);
        echo '<input type="checkbox" name="wpotel_trace_database" value="1" ' . checked(1, $trace_database, false) . ' />';
        echo '<p class="description">Enable database query tracing with plugin/theme identification</p>';
        echo '<p class="description"><strong>What this shows:</strong> Which plugins, themes, or WordPress core functions are making database queries and how long they take.</p>';
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
    
    public function render_pro_field($args) {
        $field_id = $args['field_id'];
        $value = get_option($field_id, $field_id === 'wpotel_batch_size' ? 50 : 5);
        
        echo '<input type="number" name="' . esc_attr($field_id) . '" value="' . esc_attr($value) . '" min="1" max="' . ($field_id === 'wpotel_batch_size' ? '500' : '60') . '" />';
        
        $descriptions = [
            'wpotel_batch_size' => 'Number of traces to batch before sending (reduces network overhead)',
            'wpotel_export_interval' => 'Seconds between batch exports'
        ];
        
        if (isset($descriptions[$field_id])) {
            echo '<p class="description">' . $descriptions[$field_id] . '</p>';
        }
    }
    
    // AJAX handlers
    public function ajax_test_connection() {
        check_ajax_referer('wpotel_test_connection');
        
        if (!current_user_can('manage_options')) {
            wp_die();
        }
        
        $endpoint = get_option('wpotel_endpoint', 'http://localhost:14268/api/traces');
        
        // Create a test trace
        $test_trace = [
            'traceID' => $this->core_plugin->generate_id(32),
            'spans' => [[
                'traceID' => $this->core_plugin->generate_id(32),
                'spanID' => $this->core_plugin->generate_id(16),
                'operationName' => 'test.connection',
                'startTime' => microtime(true) * 1000000,
                'duration' => 1000,
                'tags' => [
                    ['key' => 'test', 'value' => true],
                    ['key' => 'service.name', 'value' => $this->config['service_name']]
                ]
            ]]
        ];
        
        // Convert to appropriate format
        if (strpos($endpoint, '9411') !== false) {
            $data = $this->convert_test_trace_to_zipkin($test_trace);
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
        
        if ($status_code === 202 || $status_code === 200) {
            wp_send_json_success('Connection successful! Check your monitoring UI for test trace.');
        } else {
            wp_send_json_error("Connection failed: HTTP $status_code");
        }
    }
    
    public function ajax_activate_license() {
        check_ajax_referer('wpotel_admin', '_ajax_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $license_key = isset($_POST['license_key']) ? sanitize_text_field($_POST['license_key']) : '';
        
        if (empty($license_key)) {
            wp_send_json_error('Please enter a license key');
        }
        
        // For testing, use simple validation
        $valid_licenses = ['DEMO-PRO-KEY', 'TEST-LICENSE-123'];
        
        if (in_array($license_key, $valid_licenses)) {
            update_option('wpotel_license_key', $license_key);
            update_option('wpotel_license_status', 'active');
            update_option('wpotel_license_expires', date('Y-m-d', strtotime('+1 year')));
            
            wp_send_json_success('License activated successfully!');
        } else {
            wp_send_json_error('Invalid license key. Please use DEMO-PRO-KEY or TEST-LICENSE-123 for testing.');
        }
    }
    
    public function ajax_test_exporter() {
        // Implementation for Pro users
        wp_send_json_error('Pro feature not implemented in this context');
    }
    
    private function convert_test_trace_to_zipkin($trace) {
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
        // Load admin page renderer
        require_once WP_OTEL_PLUGIN_DIR . 'includes/admin/class-admin-page-renderer.php';
        $renderer = new AdminPageRenderer($this->core_plugin, $this->is_pro);
        $renderer->render();
    }
    
    public function render_test_page() {
        // Load test page renderer  
        require_once WP_OTEL_PLUGIN_DIR . 'includes/admin/class-test-page-renderer.php';
        $renderer = new TestPageRenderer($this->core_plugin, $this->is_pro);
        $renderer->render();
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
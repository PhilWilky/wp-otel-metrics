<?php
class WP_OTEL_Metrics {
    
    private $start_time;
    
    public function __construct() {
        $this->start_time = microtime(true);
    }
    
    public function init() {
        // Hook into WordPress to measure request latency
        add_action('init', array($this, 'start_request_timing'));
        add_action('shutdown', array($this, 'end_request_timing'));
        
        // Track HTTP status/errors
        $this->track_http_status();
        
        // Initialize admin dashboard
        if (is_admin()) {
            $admin = new WP_OTEL_Admin();
            $admin->init();
        }
        
        // Add settings page
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    public function register_settings() {
        register_setting('wp_otel_settings', 'wp_otel_endpoint');
        register_setting('wp_otel_settings', 'wp_otel_service_name');
        register_setting('wp_otel_settings', 'wp_otel_debug_mode');
        
        // Add settings section to the admin page
        add_settings_section(
            'wp_otel_settings_section',
            'OpenTelemetry Settings',
            array($this, 'settings_section_callback'),
            'wp-otel-metrics'
        );
        
        // Add settings fields
        add_settings_field(
            'wp_otel_endpoint', 
            'OpenTelemetry Endpoint', 
            array($this, 'endpoint_callback'), 
            'wp-otel-metrics', 
            'wp_otel_settings_section'
        );
        
        add_settings_field(
            'wp_otel_service_name', 
            'Service Name', 
            array($this, 'service_name_callback'), 
            'wp-otel-metrics', 
            'wp_otel_settings_section'
        );
        
        add_settings_field(
            'wp_otel_debug_mode', 
            'Debug Mode', 
            array($this, 'debug_mode_callback'), 
            'wp-otel-metrics', 
            'wp_otel_settings_section'
        );
    }
    
    public function settings_section_callback() {
        echo '<p>Configure OpenTelemetry integration settings.</p>';
    }
    
    public function endpoint_callback() {
        $endpoint = get_option('wp_otel_endpoint', 'http://localhost:9411/api/v2/spans');
        echo '<input type="text" name="wp_otel_endpoint" value="' . esc_attr($endpoint) . '" size="50" />';
        echo '<p class="description">The endpoint URL for your OpenTelemetry receiver (e.g., http://localhost:9411/api/v2/spans for Zipkin)</p>';
    }
    
    public function service_name_callback() {
        $service_name = get_option('wp_otel_service_name', 'wordpress');
        echo '<input type="text" name="wp_otel_service_name" value="' . esc_attr($service_name) . '" />';
        echo '<p class="description">The service name to use in OpenTelemetry</p>';
    }
    
    public function debug_mode_callback() {
        $debug_mode = get_option('wp_otel_debug_mode', '0');
        echo '<input type="checkbox" name="wp_otel_debug_mode" value="1" ' . checked('1', $debug_mode, false) . ' />';
        echo '<p class="description">Enable debug mode to log OpenTelemetry operations to the WordPress error log</p>';
    }
    
    public function start_request_timing() {
        // Reset start time at beginning of request
        $this->start_time = microtime(true);
    }
    
    public function end_request_timing() {
        $end_time = microtime(true);
        $latency = ($end_time - $this->start_time) * 1000; // Convert to milliseconds
        
        // Store the metric locally
        $this->store_metric('request_latency', $latency);
        
        // Send to OpenTelemetry
        $attributes = array(
            'http.method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'http.url' => isset($_SERVER['REQUEST_URI']) ? home_url($_SERVER['REQUEST_URI']) : '',
            'http.host' => $_SERVER['HTTP_HOST'] ?? '',
            'http.user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        );
        
        do_action('wp_otel_store_metric', 'request_latency', $latency, $attributes);
    }
    
    private function store_metric($metric_name, $value) {
        // Get existing metrics
        $metrics = get_option('wp_otel_metrics', array());
        
        // Add timestamp
        $timestamp = current_time('timestamp');
        
        // Create metric entry
        $metric_entry = array(
            'timestamp' => $timestamp,
            'value' => $value
        );
        
        // Initialize metric array if it doesn't exist
        if (!isset($metrics[$metric_name])) {
            $metrics[$metric_name] = array();
        }
        
        // Add new metric
        $metrics[$metric_name][] = $metric_entry;
        
        // Keep only last 1000 entries
        if (count($metrics[$metric_name]) > 1000) {
            $metrics[$metric_name] = array_slice($metrics[$metric_name], -1000);
        }
        
        // Update option
        update_option('wp_otel_metrics', $metrics);
    }

    public function track_http_status() {
        add_action('wp', array($this, 'check_http_status'));
    }
    
    public function check_http_status() {
        $is_error = is_404();
        $value = $is_error ? 1 : 0;
        
        // Store metric locally
        $this->store_metric('error_rate', $value);
        
        // Only send errors to OpenTelemetry to reduce data volume
        if ($is_error) {
            $attributes = array(
                'http.method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
                'http.url' => isset($_SERVER['REQUEST_URI']) ? home_url($_SERVER['REQUEST_URI']) : '',
                'http.status_code' => 404,
            );
            
            do_action('wp_otel_store_metric', 'error_rate', $value, $attributes);
        }
    }
}
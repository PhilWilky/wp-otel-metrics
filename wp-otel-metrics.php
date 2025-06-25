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

// Autoload classes
require_once plugin_dir_path(__FILE__) . 'includes/class-autoloader.php';
$autoloader = new Autoloader();

// Load core classes
require_once plugin_dir_path(__FILE__) . 'includes/class-core-plugin.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-database-tracer.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-admin-interface.php';

// Load Pro classes if available
use WPOtel\Pro\LicenseManager;
use WPOtel\Pro\BatchManager;
use WPOtel\Pro\ExporterManager;

/**
 * Main Plugin Class
 */
class MinimalPlugin {
    const VERSION = '2.0.0';
    
    private $core_plugin;
    private $database_tracer;
    private $admin_interface;
    
    // Pro features
    private $license_manager;
    private $batch_manager;
    private $exporter_manager;
    private $is_pro = false;
    private $config = null;
    
    public function __construct() {
        // Initialize in the correct order
        $this->load_pro_features();
        $this->init_core_components();
        $this->init_hooks();
    }
    
    private function load_pro_features() {
        if (file_exists(plugin_dir_path(__FILE__) . 'includes/class-license-manager.php')) {
            require_once plugin_dir_path(__FILE__) . 'includes/class-license-manager.php';
            require_once plugin_dir_path(__FILE__) . 'includes/class-batch-manager.php';
            require_once plugin_dir_path(__FILE__) . 'includes/class-exporter-manager.php';
            require_once plugin_dir_path(__FILE__) . 'includes/exporters/class-base-exporter.php';
            require_once plugin_dir_path(__FILE__) . 'includes/exporters/class-jaeger-exporter.php';
            require_once plugin_dir_path(__FILE__) . 'includes/exporters/class-datadog-exporter.php';
            
            $this->license_manager = new LicenseManager();
            $this->is_pro = $this->license_manager->is_valid();
        }
    }
    
    private function init_core_components() {
        // Get config AFTER pro features are loaded
        $config = $this->get_config();
        
        $this->core_plugin = new CorePlugin($config, $this->is_pro);
        $this->database_tracer = new DatabaseTracer($this->core_plugin);
        $this->admin_interface = new AdminInterface($this->core_plugin, $this->is_pro);
        
        // Initialize Pro components AFTER core plugin is created
        if ($this->is_pro) {
            $this->batch_manager = new BatchManager($config);
            $this->exporter_manager = new ExporterManager($config);
            
            // Pass Pro components to core plugin
            $this->core_plugin->set_pro_components(
                $this->batch_manager, 
                $this->exporter_manager
            );
        }
    }
    
    private function get_config() {
        if ($this->config !== null) {
            return $this->config;
        }
        
        $this->config = [
            'enabled' => get_option('wpotel_enabled', true),
            'endpoint' => get_option('wpotel_endpoint', 'http://192.168.0.165:9411/api/v2/spans'),
            'service_name' => get_option('wpotel_service_name', parse_url(home_url(), PHP_URL_HOST)),
            'sampling_rate' => get_option('wpotel_sampling_rate', 0.1),
            'environment' => wp_get_environment_type(),
            'debug_mode' => get_option('wpotel_debug_mode', false),
            'log_to_file' => get_option('wpotel_log_to_file', false),
            'trace_database' => get_option('wpotel_trace_database', true)
        ];
        
        // Apply Pro config if licensed
        if ($this->is_pro) {
            $this->config = apply_filters('wpotel_pro_config', $this->config);
            $this->config = array_merge($this->config, [
                'batch_size' => get_option('wpotel_batch_size', 50),
                'export_interval' => get_option('wpotel_export_interval', 5),
                'adaptive_sampling' => get_option('wpotel_adaptive_sampling', true),
                'compression' => get_option('wpotel_compression', true),
                'enabled_exporters' => get_option('wpotel_enabled_exporters', ['jaeger'])
            ]);
        }
        
        return $this->config;
    }
    
    private function init_hooks() {
        // Activation hook
        register_activation_hook(__FILE__, __NAMESPACE__ . '\wp_otel_activate');
        
        // Initialize components immediately (we're already on plugins_loaded)
        $this->init_all_components();
    }
    
    public function init_all_components() {
        if ($this->core_plugin->is_enabled()) {
            $this->core_plugin->init();
            $this->database_tracer->init();
        }
        $this->admin_interface->init();
    }
    
    // Expose methods for backward compatibility
    public function __call($method, $args) {
        if (method_exists($this->core_plugin, $method)) {
            return call_user_func_array([$this->core_plugin, $method], $args);
        }
        if (method_exists($this->admin_interface, $method)) {
            return call_user_func_array([$this->admin_interface, $method], $args);
        }
        throw new \BadMethodCallException("Method {$method} does not exist");
    }
}

// Activation function
function wp_otel_activate() {
    // Set up default options
    add_option('wpotel_enabled', true);
    add_option('wpotel_endpoint', 'http://192.168.0.165:9411/api/v2/spans');
    add_option('wpotel_service_name', parse_url(home_url(), PHP_URL_HOST));
    add_option('wpotel_sampling_rate', 0.1);
    add_option('wpotel_trace_database', true);
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
    global $wp_otel_plugin;
    $wp_otel_plugin = new MinimalPlugin();
}

// Use plugins_loaded instead of init to avoid circular dependency
add_action('plugins_loaded', __NAMESPACE__ . '\wp_otel_init');
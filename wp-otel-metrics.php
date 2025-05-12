<?php
/**
 * Plugin Name: WP OpenTelemetry Metrics
 * Description: OpenTelemetry metrics for WordPress
 * Version: 1.0.0
 * Author: Phil Wilkinson
 * Author URI: www.philwilky.me
 */

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WP_OTEL_VERSION', '1.0.0');
define('WP_OTEL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_OTEL_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once WP_OTEL_PLUGIN_DIR . 'includes/class-wp-otel-metrics.php';
require_once WP_OTEL_PLUGIN_DIR . 'includes/class-wp-otel-admin.php';
require_once WP_OTEL_PLUGIN_DIR . 'includes/class-wp-otel-integration.php';

// Initialize the plugin
function wp_otel_init() {
    // Initialize main plugin
    $plugin = new WP_OTEL_Metrics();
    $plugin->init();
    
    // Initialize OpenTelemetry integration
    $otel = WP_OTEL_Integration::get_instance();
    $otel->init();
}
add_action('plugins_loaded', 'wp_otel_init');

/**
 * Get the current OpenTelemetry trace context
 * 
 * @return array|null Array containing trace_id and span_id, or null if unavailable
 */
function wp_otel_get_trace_context() {
    $otel = WP_OTEL_Integration::get_instance();
    if (method_exists($otel, 'get_trace_context')) {
        return $otel->get_trace_context();
    }
    return null;
}

/**
 * Create a new OpenTelemetry span for custom operations
 * 
 * @param string $name Span name
 * @param array $attributes Optional attributes
 * @return object|null Span object or null if OpenTelemetry is not available
 */
function wp_otel_create_span($name, $attributes = []) {
    $otel = WP_OTEL_Integration::get_instance();
    if (method_exists($otel, 'create_span')) {
        return $otel->create_span($name, $attributes);
    }
    return null;
}
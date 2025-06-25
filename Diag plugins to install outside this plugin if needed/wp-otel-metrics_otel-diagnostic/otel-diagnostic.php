
<?php
/**
 * Plugin Name: OpenTelemetry Comprehensive Diagnostic
 * Description: Comprehensive diagnostic tool for OpenTelemetry plugin issues
 * Version: 1.0.0
 * Author: Debug Tool
 * Requires at least: 5.0
 * Tested up to: 6.4
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

class OTelComprehensiveDiagnostic {
    
    private $results = [];
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('wp_ajax_run_otel_diagnostic', [$this, 'run_diagnostic']);
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'tools.php',
            'OpenTelemetry Diagnostics',
            'OTel Diagnostics',
            'manage_options',
            'otel-diagnostics',
            [$this, 'render_diagnostic_page']
        );
    }
    
    public function render_diagnostic_page() {
        ?>
        <div class="wrap">
            <h1>🔍 OpenTelemetry Comprehensive Diagnostics</h1>
            
            <div class="notice notice-info">
                <p><strong>What this tool does:</strong></p>
                <ul>
                    <li>Tests if database traces are being created correctly</li>
                    <li>Verifies trace export to your monitoring backend</li>
                    <li>Identifies configuration issues</li>
                    <li>Shows exactly what data is being sent to Zipkin/Jaeger</li>
                </ul>
            </div>
            
            <button id="run-diagnostic" class="button button-primary button-hero">
                🚀 Run Complete Diagnostic
            </button>
            
            <div id="diagnostic-results" style="margin-top: 20px;"></div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#run-diagnostic').on('click', function() {
                var button = $(this);
                var results = $('#diagnostic-results');
                
                button.prop('disabled', true).text('Running Diagnostics...');
                results.html('<div class="notice notice-info"><p>Running comprehensive diagnostics...</p></div>');
                
                $.post(ajaxurl, {
                    action: 'run_otel_diagnostic',
                    nonce: '<?php echo wp_create_nonce('otel_diagnostic'); ?>'
                }, function(response) {
                    if (response.success) {
                        results.html(response.data.html);
                    } else {
                        results.html('<div class="notice notice-error"><p>Error: ' + response.data + '</p></div>');
                    }
                    button.prop('disabled', false).text('🚀 Run Complete Diagnostic');
                });
            });
        });
        </script>
        <?php
    }
    
    public function run_diagnostic() {
        if (!check_ajax_referer('otel_diagnostic', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $this->results = [];
        
        // Run all diagnostic tests
        $this->test_plugin_availability();
        $this->test_configuration();
        $this->test_trace_creation();
        $this->test_database_queries();
        $this->test_export_functionality();
        $this->test_zipkin_connectivity();
        $this->generate_test_trace();
        
        wp_send_json_success([
            'html' => $this->generate_results_html()
        ]);
    }
    
    private function test_plugin_availability() {
        global $wp_otel_plugin;
        
        $this->add_result('Plugin Availability', 'section');
        
        if (!$wp_otel_plugin) {
            $this->add_result('❌ OpenTelemetry plugin not found', 'error');
            return;
        }
        
        $this->add_result('✅ OpenTelemetry plugin loaded', 'success');
        
        // Test plugin methods
        $required_methods = ['get_config', 'get_current_trace', 'start_trace', 'generate_id'];
        foreach ($required_methods as $method) {
            if (method_exists($wp_otel_plugin, $method)) {
                $this->add_result("✅ Method $method exists", 'success');
            } else {
                $this->add_result("❌ Method $method missing", 'error');
            }
        }
    }
    
    private function test_configuration() {
        global $wp_otel_plugin;
        
        $this->add_result('Configuration Tests', 'section');
        
        if (!$wp_otel_plugin) {
            $this->add_result('❌ Cannot test configuration - plugin not loaded', 'error');
            return;
        }
        
        $config = $wp_otel_plugin->get_config();
        
        $this->add_result('📋 Current Configuration:', 'info');
        $this->add_result('<pre>' . json_encode($config, JSON_PRETTY_PRINT) . '</pre>', 'raw');
        
        // Test specific config values
        if ($config['enabled']) {
            $this->add_result('✅ Tracing enabled', 'success');
        } else {
            $this->add_result('❌ Tracing disabled', 'error');
            return;
        }
        
        if (!empty($config['endpoint'])) {
            $this->add_result('✅ Endpoint configured: ' . $config['endpoint'], 'success');
        } else {
            $this->add_result('❌ No endpoint configured', 'error');
        }
        
        if ($config['trace_database']) {
            $this->add_result('✅ Database tracing enabled', 'success');
        } else {
            $this->add_result('⚠️ Database tracing disabled', 'warning');
        }
        
        if (defined('SAVEQUERIES') && SAVEQUERIES) {
            $this->add_result('✅ SAVEQUERIES enabled', 'success');
        } else {
            $this->add_result('❌ SAVEQUERIES not enabled - database timing will be inaccurate', 'error');
        }
    }
    
    private function test_trace_creation() {
        global $wp_otel_plugin;
        
        $this->add_result('Trace Creation Tests', 'section');
        
        if (!$wp_otel_plugin) {
            $this->add_result('❌ Cannot test trace creation - plugin not loaded', 'error');
            return;
        }
        
        // Check if there's a current trace
        $current_trace = $wp_otel_plugin->get_current_trace();
        
        if ($current_trace) {
            $this->add_result('✅ Active trace found', 'success');
            $this->add_result('📋 Trace ID: ' . $current_trace['traceID'], 'info');
            $this->add_result('📋 Span count: ' . count($current_trace['spans']), 'info');
            
            // Show current trace structure
            $this->add_result('📋 Current Trace Structure:', 'info');
            $this->add_result('<pre>' . json_encode($current_trace, JSON_PRETTY_PRINT) . '</pre>', 'raw');
        } else {
            $this->add_result('⚠️ No active trace (this could be due to sampling)', 'warning');
            
            // Try to create a trace manually
            $wp_otel_plugin->start_trace();
            $trace_after_start = $wp_otel_plugin->get_current_trace();
            
            if ($trace_after_start) {
                $this->add_result('✅ Manual trace creation successful', 'success');
            } else {
                $this->add_result('❌ Manual trace creation failed', 'error');
            }
        }
    }
    
    private function test_database_queries() {
        global $wpdb;
        
        $this->add_result('Database Query Tests', 'section');
        
        // Count queries before
        $queries_before = count($wpdb->queries ?? []);
        $this->add_result("📋 Queries before test: $queries_before", 'info');
        
        // Run some test queries
        $test_queries = [
            "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_status = 'publish' LIMIT 1",
            "SELECT COUNT(*) FROM {$wpdb->posts}",
            "SELECT option_name FROM {$wpdb->options} WHERE option_name = 'blogname'"
        ];
        
        foreach ($test_queries as $i => $query) {
            $start = microtime(true);
            $result = $wpdb->get_results($query);
            $duration = (microtime(true) - $start) * 1000;
            
            $this->add_result("✅ Test query " . ($i + 1) . " executed in " . round($duration, 2) . "ms", 'success');
        }
        
        // Count queries after
        $queries_after = count($wpdb->queries ?? []);
        $new_queries = $queries_after - $queries_before;
        $this->add_result("📋 New queries executed: $new_queries", 'info');
        
        if ($new_queries > 0) {
            $this->add_result('✅ Database queries are being captured', 'success');
            
            // Show last few queries
            $recent_queries = array_slice($wpdb->queries ?? [], -3);
            $this->add_result('📋 Recent queries:', 'info');
            foreach ($recent_queries as $q) {
                if (is_array($q) && count($q) >= 2) {
                    $this->add_result('• ' . substr($q[0], 0, 80) . '... (' . ($q[1] * 1000) . 'ms)', 'info');
                }
            }
        } else {
            $this->add_result('❌ No new database queries captured', 'error');
        }
    }
    
    private function test_export_functionality() {
        global $wp_otel_plugin;
        
        $this->add_result('Export Functionality Tests', 'section');
        
        if (!$wp_otel_plugin) {
            $this->add_result('❌ Cannot test export - plugin not loaded', 'error');
            return;
        }
        
        $config = $wp_otel_plugin->get_config();
        $endpoint = $config['endpoint'] ?? '';
        
        if (empty($endpoint)) {
            $this->add_result('❌ No endpoint configured for testing', 'error');
            return;
        }
        
        // Create a test trace for export
        $test_trace = [
            'traceID' => bin2hex(random_bytes(16)),
            'spans' => [[
                'traceID' => bin2hex(random_bytes(16)),
                'spanID' => bin2hex(random_bytes(8)),
                'operationName' => 'diagnostic.test',
                'startTime' => microtime(true) * 1000000,
                'duration' => 5000, // 5ms
                'tags' => [
                    ['key' => 'test.type', 'value' => 'diagnostic'],
                    ['key' => 'test.time', 'value' => date('Y-m-d H:i:s')]
                ]
            ]]
        ];
        
        // Format for the endpoint
        if (strpos($endpoint, '9411') !== false) {
            // Zipkin format
            $data = $this->convert_to_zipkin_format([$test_trace]);
            $format = 'Zipkin';
        } else {
            // Jaeger format
            $data = [$test_trace];
            $format = 'Jaeger';
        }
        
        $this->add_result("📋 Preparing test export in $format format", 'info');
        $this->add_result('📋 Export data:', 'info');
        $this->add_result('<pre>' . json_encode($data, JSON_PRETTY_PRINT) . '</pre>', 'raw');
        
        // Try to export
        $response = wp_remote_post($endpoint, [
            'body' => json_encode($data),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 5,
            'blocking' => true
        ]);
        
        if (is_wp_error($response)) {
            $this->add_result('❌ Export failed: ' . $response->get_error_message(), 'error');
        } else {
            $status_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            
            if ($status_code === 200 || $status_code === 202) {
                $this->add_result("✅ Export successful (HTTP $status_code)", 'success');
            } else {
                $this->add_result("❌ Export failed with HTTP $status_code", 'error');
                $this->add_result("Response: $response_body", 'error');
            }
        }
    }
    
    private function test_zipkin_connectivity() {
        $this->add_result('Zipkin/Jaeger Connectivity Tests', 'section');
        
        // Test common endpoints
        $endpoints = [
            'Zipkin (Local)' => 'http://localhost:9411/api/v2/spans',
            'Zipkin (Docker)' => 'http://192.168.0.165:9411/api/v2/spans',
            'Jaeger (Local)' => 'http://localhost:14268/api/traces'
        ];
        
        foreach ($endpoints as $name => $url) {
            $response = wp_remote_get($url, ['timeout' => 2]);
            
            if (is_wp_error($response)) {
                $this->add_result("❌ $name not reachable: " . $response->get_error_message(), 'warning');
            } else {
                $status_code = wp_remote_retrieve_response_code($response);
                if ($status_code < 500) { // Any response under 500 means it's reachable
                    $this->add_result("✅ $name is reachable (HTTP $status_code)", 'success');
                } else {
                    $this->add_result("⚠️ $name returned HTTP $status_code", 'warning');
                }
            }
        }
    }
    
    private function generate_test_trace() {
        global $wp_otel_plugin, $wpdb;
        
        $this->add_result('Live Trace Generation Test', 'section');
        
        if (!$wp_otel_plugin) {
            $this->add_result('❌ Cannot generate test trace - plugin not loaded', 'error');
            return;
        }
        
        // Force create a trace (bypass sampling)
        $trace_id = bin2hex(random_bytes(16));
        $span_id = bin2hex(random_bytes(8));
        
        $test_trace = [
            'traceID' => $trace_id,
            'spans' => [[
                'traceID' => $trace_id,
                'spanID' => $span_id,
                'operationName' => 'diagnostic.full.test',
                'startTime' => microtime(true) * 1000000,
                'tags' => [
                    ['key' => 'test.type', 'value' => 'comprehensive_diagnostic'],
                    ['key' => 'test.timestamp', 'value' => date('c')],
                    ['key' => 'wordpress.version', 'value' => get_bloginfo('version')]
                ]
            ]]
        ];
        
        // Manually add some database spans
        $db_queries = [
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' LIMIT 1",
            "SELECT COUNT(*) FROM {$wpdb->users}"
        ];
        
        foreach ($db_queries as $query) {
            $start = microtime(true);
            $wpdb->get_results($query);
            $duration = (microtime(true) - $start) * 1000000; // microseconds
            
            $test_trace['spans'][] = [
                'traceID' => $trace_id,
                'spanID' => bin2hex(random_bytes(8)),
                'parentSpanID' => $span_id,
                'operationName' => 'mysql.query',
                'startTime' => $start * 1000000,
                'duration' => $duration,
                'tags' => [
                    ['key' => 'db.statement', 'value' => $query],
                    ['key' => 'db.type', 'value' => 'mysql'],
                    ['key' => 'component', 'value' => 'database'],
                    ['key' => 'test.generated', 'value' => true]
                ]
            ];
        }
        
        // Complete the main span
        $test_trace['spans'][0]['duration'] = 50000; // 50ms
        
        $this->add_result('✅ Generated test trace with ' . count($test_trace['spans']) . ' spans', 'success');
        $this->add_result('📋 Test Trace ID: ' . $trace_id, 'info');
        
        // Export the test trace
        $config = $wp_otel_plugin->get_config();
        $endpoint = $config['endpoint'] ?? '';
        
        if ($endpoint) {
            if (strpos($endpoint, '9411') !== false) {
                $data = $this->convert_to_zipkin_format([$test_trace]);
            } else {
                $data = [$test_trace];
            }
            
            $response = wp_remote_post($endpoint, [
                'body' => json_encode($data),
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 5,
                'blocking' => true
            ]);
            
            if (!is_wp_error($response)) {
                $status_code = wp_remote_retrieve_response_code($response);
                if ($status_code === 200 || $status_code === 202) {
                    $this->add_result('✅ Test trace exported successfully!', 'success');
                    $this->add_result('🔍 Check your monitoring UI for trace: ' . $trace_id, 'info');
                } else {
                    $this->add_result("❌ Test trace export failed: HTTP $status_code", 'error');
                }
            } else {
                $this->add_result('❌ Test trace export failed: ' . $response->get_error_message(), 'error');
            }
        }
    }
    
    private function convert_to_zipkin_format($traces) {
        $zipkin_spans = [];
        
        foreach ($traces as $trace) {
            foreach ($trace['spans'] as $span) {
                $tags = [];
                foreach ($span['tags'] ?? [] as $tag) {
                    $tags[$tag['key']] = (string)$tag['value'];
                }
                
                $zipkin_span = [
                    'id' => $span['spanID'],
                    'traceId' => $span['traceID'],
                    'name' => $span['operationName'],
                    'timestamp' => (int)$span['startTime'],
                    'duration' => (int)$span['duration'],
                    'localEndpoint' => [
                        'serviceName' => 'wordpress-otel-diagnostic'
                    ],
                    'tags' => $tags
                ];
                
                if (isset($span['parentSpanID'])) {
                    $zipkin_span['parentId'] = $span['parentSpanID'];
                }
                
                $zipkin_spans[] = $zipkin_span;
            }
        }
        
        return $zipkin_spans;
    }
    
    private function add_result($message, $type) {
        $this->results[] = ['message' => $message, 'type' => $type];
    }
    
    private function generate_results_html() {
        $html = '<div class="otel-diagnostic-results">';
        
        foreach ($this->results as $result) {
            $class = '';
            switch ($result['type']) {
                case 'section':
                    $html .= '<h2 style="margin-top: 30px; padding-bottom: 10px; border-bottom: 2px solid #0073aa;">' . $result['message'] . '</h2>';
                    continue 2;
                case 'success':
                    $class = 'notice-success';
                    break;
                case 'error':
                    $class = 'notice-error';
                    break;
                case 'warning':
                    $class = 'notice-warning';
                    break;
                case 'info':
                    $class = 'notice-info';
                    break;
                case 'raw':
                    $html .= '<div style="background: #f5f5f5; padding: 10px; margin: 10px 0; border-left: 4px solid #ccc;">' . $result['message'] . '</div>';
                    continue 2;
            }
            
            $html .= '<div class="notice ' . $class . ' inline" style="margin: 5px 0; padding: 8px 12px;"><p>' . $result['message'] . '</p></div>';
        }
        
        $html .= '</div>';
        
        return $html;
    }
}

// Initialize the diagnostic tool
new OTelComprehensiveDiagnostic();
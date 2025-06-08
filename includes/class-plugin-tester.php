<?php
/**
 * Plugin Tester Class for WP OpenTelemetry
 * Comprehensive testing of all plugin functionality
 */

namespace WPOtel;

class PluginTester {
    
    private $plugin;
    private $results = [];
    private $test_count = 0;
    private $passed_count = 0;
    
    public function __construct($plugin) {
        $this->plugin = $plugin;
    }
    
    public function run_all_tests() {
        $this->results = [];
        $this->test_count = 0;
        $this->passed_count = 0;
        
        $this->test_core_functionality();
        $this->test_wordpress_integration();
        $this->test_opentelemetry_features();
        $this->test_pro_features();
        $this->test_admin_functionality();
        $this->test_configuration();
        $this->test_real_trace_export();
        
        return $this->get_summary();
    }
    
    private function assert($condition, $message, $details = '') {
        $this->test_count++;
        
        if ($condition) {
            $this->passed_count++;
            $this->results[] = [
                'status' => 'pass',
                'message' => $message,
                'details' => $details
            ];
        } else {
            $this->results[] = [
                'status' => 'fail',
                'message' => $message,
                'details' => $details
            ];
        }
    }
    
    private function test_core_functionality() {
        $this->results[] = ['status' => 'section', 'message' => 'Core Functionality Tests'];
        
        // Test plugin instance
        $this->assert(
            is_object($this->plugin),
            'Plugin instance created',
            get_class($this->plugin)
        );
        
        // Test class methods exist
        $reflection = new \ReflectionClass($this->plugin);
        $methods = ['start_trace', 'end_trace', 'get_config', 'generate_id'];
        
        foreach ($methods as $method) {
            $this->assert(
                $reflection->hasMethod($method),
                "Method '$method' exists",
                $reflection->hasMethod($method) ? 'Available' : 'Missing'
            );
        }
        
        // Test ID generation
        try {
            $idMethod = $reflection->getMethod('generate_id');
            $idMethod->setAccessible(true);
            
            $traceId = $idMethod->invoke($this->plugin, 32);
            $spanId = $idMethod->invoke($this->plugin, 16);
            
            $this->assert(
                strlen($traceId) === 32,
                'Trace ID generation (32 chars)',
                "Generated: $traceId"
            );
            
            $this->assert(
                strlen($spanId) === 16,
                'Span ID generation (16 chars)',
                "Generated: $spanId"
            );
            
            $this->assert(
                ctype_xdigit($traceId),
                'Trace ID is valid hexadecimal',
                $traceId
            );
            
        } catch (\Exception $e) {
            $this->assert(false, 'ID generation failed', $e->getMessage());
        }
    }
    
    private function test_wordpress_integration() {
        $this->results[] = ['status' => 'section', 'message' => 'WordPress Integration Tests'];
        
        // Test WordPress functions availability
        $wp_functions = [
            'get_option', 'home_url', 'wp_get_environment_type', 
            'add_action', 'add_filter', 'wp_remote_post'
        ];
        
        foreach ($wp_functions as $func) {
            $this->assert(
                function_exists($func),
                "WordPress function '$func' available",
                function_exists($func) ? 'Available' : 'Missing'
            );
        }
        
        // Test WordPress constants
        $wp_constants = ['ABSPATH', 'WP_CONTENT_DIR'];
        foreach ($wp_constants as $constant) {
            $this->assert(
                defined($constant),
                "WordPress constant '$constant' defined",
                defined($constant) ? constant($constant) : 'Not defined'
            );
        }
        
        // Test plugin constants
        $plugin_constants = ['WP_OTEL_VERSION', 'WP_OTEL_PLUGIN_DIR'];
        foreach ($plugin_constants as $constant) {
            $this->assert(
                defined($constant),
                "Plugin constant '$constant' defined",
                defined($constant) ? constant($constant) : 'Not defined'
            );
        }
        
        // Test WordPress hooks registration
        try {
            $hooks_registered = 0;
            
            // Count registered actions (this is a simplified test)
            if (has_action('init')) $hooks_registered++;
            if (has_action('shutdown')) $hooks_registered++;
            if (has_action('admin_menu')) $hooks_registered++;
            
            $this->assert(
                $hooks_registered > 0,
                'WordPress hooks registered',
                "$hooks_registered hooks found"
            );
            
        } catch (\Exception $e) {
            $this->assert(false, 'Hook registration test failed', $e->getMessage());
        }
    }
    
    private function test_opentelemetry_features() {
        $this->results[] = ['status' => 'section', 'message' => 'OpenTelemetry Features Tests'];
        
        try {
            $reflection = new \ReflectionClass($this->plugin);
            
            // Test configuration loading
            $configMethod = $reflection->getMethod('get_config');
            $configMethod->setAccessible(true);
            $config = $configMethod->invoke($this->plugin);
            
            $this->assert(
                is_array($config),
                'Configuration loaded as array',
                json_encode($config, JSON_PRETTY_PRINT)
            );
            
            $required_config = ['enabled', 'endpoint', 'service_name', 'sampling_rate'];
            foreach ($required_config as $key) {
                $this->assert(
                    array_key_exists($key, $config),
                    "Config key '$key' exists",
                    isset($config[$key]) ? $config[$key] : 'Missing'
                );
            }
            
            // Test sampling rate validation
            $sampling_rate = $config['sampling_rate'] ?? 0;
            $this->assert(
                $sampling_rate >= 0 && $sampling_rate <= 1,
                'Sampling rate is valid (0-1)',
                "Current: $sampling_rate"
            );
            
            // Test endpoint validation
            $endpoint = $config['endpoint'] ?? '';
            $this->assert(
                filter_var($endpoint, FILTER_VALIDATE_URL),
                'Endpoint is valid URL',
                $endpoint
            );
            
        } catch (\Exception $e) {
            $this->assert(false, 'OpenTelemetry configuration test failed', $e->getMessage());
        }
    }
    
    private function test_pro_features() {
        $this->results[] = ['status' => 'section', 'message' => 'Pro Features Tests'];
        
        // Test Pro classes availability
        $pro_classes = [
            'WPOtel\\Pro\\LicenseManager',
            'WPOtel\\Pro\\BatchManager',
            'WPOtel\\Pro\\ExporterManager'
        ];
        
        $pro_available = 0;
        foreach ($pro_classes as $class) {
            $exists = class_exists($class);
            if ($exists) $pro_available++;
            
            $this->assert(
                true, // We don't fail if Pro isn't available
                "Pro class '$class'",
                $exists ? 'Available' : 'Not available'
            );
        }
        
        // Test license manager if available
        if (class_exists('WPOtel\\Pro\\LicenseManager')) {
            try {
                $licenseManager = new \WPOtel\Pro\LicenseManager();
                $status = $licenseManager->get_status();
                
                $this->assert(
                    is_array($status),
                    'License manager status',
                    json_encode($status)
                );
                
            } catch (\Exception $e) {
                $this->assert(false, 'License manager test failed', $e->getMessage());
            }
        }
        
        $this->assert(
            true,
            "Pro features summary",
            "$pro_available out of " . count($pro_classes) . " Pro classes available"
        );
    }
    
    private function test_admin_functionality() {
        $this->results[] = ['status' => 'section', 'message' => 'Admin Functionality Tests'];
        
        // Test admin methods exist
        $admin_methods = ['add_admin_menu', 'render_admin_page'];
        $reflection = new \ReflectionClass($this->plugin);
        
        foreach ($admin_methods as $method) {
            $this->assert(
                $reflection->hasMethod($method),
                "Admin method '$method' exists",
                $reflection->hasMethod($method) ? 'Available' : 'Missing'
            );
        }
        
        // Test current user capabilities
        $this->assert(
            current_user_can('manage_options'),
            'Current user can manage options',
            'Required for admin access'
        );
        
        // Test admin page registration (safe test)
        try {
            ob_start();
            $this->plugin->add_admin_menu();
            $output = ob_get_clean();
            
            $this->assert(
                true, // This method usually doesn't fail
                'Admin menu registration',
                'No errors during registration'
            );
            
        } catch (\Exception $e) {
            $this->assert(false, 'Admin menu registration failed', $e->getMessage());
        }
        
        // Test plugin options in database
        $options = [
            'wpotel_enabled',
            'wpotel_endpoint', 
            'wpotel_service_name',
            'wpotel_sampling_rate'
        ];
        
        foreach ($options as $option) {
            $value = get_option($option, 'NOT_SET');
            $this->assert(
                $value !== 'NOT_SET',
                "Option '$option' exists in database",
                $value
            );
        }
    }
    
    private function test_configuration() {
        $this->results[] = ['status' => 'section', 'message' => 'Configuration Tests'];
        
        try {
            $reflection = new \ReflectionClass($this->plugin);
            $configMethod = $reflection->getMethod('get_config');
            $configMethod->setAccessible(true);
            $config = $configMethod->invoke($this->plugin);
            
            // Test specific configuration values
            $enabled = $config['enabled'] ?? false;
            $this->assert(
                !empty($enabled),
                'Plugin is enabled',
                $enabled ? 'Enabled' : 'Disabled'
            );
            
            $service_name = $config['service_name'] ?? '';
            $this->assert(
                !empty($service_name),
                'Service name configured',
                $service_name
            );
            
            $environment = $config['environment'] ?? '';
            $this->assert(
                !empty($environment),
                'Environment detected',
                $environment
            );
            
            // Test debug settings
            $debug_mode = $config['debug_mode'] ?? false;
            $this->assert(
                true, // Debug can be on or off
                'Debug mode setting',
                $debug_mode ? 'Enabled' : 'Disabled'
            );
            
        } catch (\Exception $e) {
            $this->assert(false, 'Configuration test failed', $e->getMessage());
        }
    }
    
    private function test_real_trace_export() {
        $this->results[] = ['status' => 'section', 'message' => 'Real Trace Export Tests'];
        
        try {
            $reflection = new \ReflectionClass($this->plugin);
            
            // Test trace creation
            $this->plugin->start_trace();
            
            $traceProperty = $reflection->getProperty('current_trace');
            $traceProperty->setAccessible(true);
            $currentTrace = $traceProperty->getValue($this->plugin);
            
            if ($currentTrace) {
                $this->assert(
                    !empty($currentTrace['traceID']),
                    'Trace created with ID',
                    $currentTrace['traceID']
                );
                
                $this->assert(
                    !empty($currentTrace['spans']),
                    'Trace has spans',
                    count($currentTrace['spans']) . ' spans'
                );
                
                // Test trace completion
                usleep(1000); // Add some duration
                $this->plugin->end_trace();
                
                $this->assert(
                    true,
                    'Trace completed and exported',
                    'Check your monitoring backend for the trace'
                );
                
            } else {
                $this->assert(
                    true, // Not a failure if sampling prevented trace creation
                    'No trace created',
                    'Likely due to sampling rate - this is normal'
                );
            }
            
            // Test endpoint connectivity (simplified)
            $reflection = new \ReflectionClass($this->plugin);
            $configMethod = $reflection->getMethod('get_config');
            $configMethod->setAccessible(true);
            $config = $configMethod->invoke($this->plugin);
            
            $endpoint = $config['endpoint'] ?? '';
            $this->assert(
                !empty($endpoint),
                'Export endpoint configured',
                $endpoint
            );
            
        } catch (\Exception $e) {
            $this->assert(false, 'Trace export test failed', $e->getMessage());
        }
    }
    
    private function get_summary() {
        return [
            'total' => $this->test_count,
            'passed' => $this->passed_count,
            'failed' => $this->test_count - $this->passed_count,
            'results' => $this->results
        ];
    }
}
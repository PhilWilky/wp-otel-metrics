<?php
/**
 * OpenTelemetry integration for WordPress plugin
 * File: includes/class-wp-otel-integration.php
 */

class WP_OTEL_Integration {
    private static $instance = null;
    private $exporter;
    private $meter;
    private $tracer;
    private $current_span;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function init() {
        // Only proceed if we have the required extensions
        if (!extension_loaded('curl')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-warning"><p>OpenTelemetry integration requires the PHP cURL extension.</p></div>';
            });
            return;
        }
        
        // Setup OpenTelemetry
        $this->setup_otel();
        
        // Hook into WordPress early to capture the whole request
        add_action('plugins_loaded', array($this, 'start_request_span'), 5); // Very early
        add_action('shutdown', array($this, 'end_request_span'), 999); // Very late
        
        // Hook into metrics collection
        add_action('wp_otel_store_metric', array($this, 'record_metric'), 10, 3);
    }
    
    private function setup_otel() {
        // Include Composer autoloader if it exists
        $autoload_path = WP_OTEL_PLUGIN_DIR . 'vendor/autoload.php';
        if (file_exists($autoload_path)) {
            require_once $autoload_path;
        } else {
            // No autoloader, use simplified alternative
            $this->setup_simplified_otel();
            return;
        }
        
        try {
            // Setup OpenTelemetry SDK if libraries are available
            if (class_exists('\OpenTelemetry\SDK\Trace\TracerProvider')) {
                // Create exporter
                $exporter = new \OpenTelemetry\Contrib\Zipkin\Exporter(
                    get_option('wp_otel_endpoint', 'http://localhost:9411/api/v2/spans'),
                    get_option('wp_otel_service_name', 'wordpress')
                );
                
                // Create tracer
                $tracerProvider = \OpenTelemetry\SDK\Trace\TracerProviderFactory::create()
                    ->addSpanProcessor(new \OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor($exporter))
                    ->build();
                
                $this->tracer = $tracerProvider->getTracer('wordpress-tracer');
                
                // Create meter for metrics
                $meterProvider = \OpenTelemetry\SDK\Metrics\MeterProviderFactory::create()
                    ->addMetricExporter($exporter)
                    ->build();
                
                $this->meter = $meterProvider->getMeter('wordpress-metrics');
                
                // Create basic counters and histograms
                $this->setup_metrics();
            } else {
                // Fallback to simplified implementation
                $this->setup_simplified_otel();
            }
        } catch (Exception $e) {
            // Log error in debug mode
            if (get_option('wp_otel_debug_mode') == '1') {
                error_log('OpenTelemetry initialization error: ' . $e->getMessage());
            }
            
            // Fallback to simplified implementation
            $this->setup_simplified_otel();
        }
    }
    
    private function setup_simplified_otel() {
        // Create a simplified exporter that uses curl directly
        $this->exporter = new WP_OTEL_Simple_Exporter();
    }
    
    private function setup_metrics() {
        // Setup the basic metrics we want to track
        if (isset($this->meter)) {
            // Create a histogram for request latency
            $this->requestLatency = $this->meter->createHistogram(
                'http.server.duration',
                'ms',
                'Measures the duration of HTTP requests'
            );
            
            // Create a counter for errors
            $this->errorCounter = $this->meter->createCounter(
                'http.server.errors',
                '{errors}',
                'Counts the number of HTTP errors'
            );
        }
    }

    /**
     * Start the main request span to capture the entire WordPress request
     */
    public function start_request_span() {
        if (!isset($this->tracer)) {
            // Create a virtual span for the simplified implementation
            $this->current_span = [
                'trace_id' => $this->generate_id(16),
                'span_id' => $this->generate_id(8),
                'start_time' => microtime(true),
                'attributes' => []
            ];
            
            // Store the trace context
            $this->store_trace_context();
            return;
        }
        
        // Extract trace context from headers if present
        $carrier = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                // Convert HTTP_TRACEPARENT to traceparent
                $header = str_replace('_', '-', strtolower(substr($key, 5)));
                $carrier[$header] = $value;
            }
        }
        
        // Start a new span as the root span for this request
        $this->current_span = $this->tracer->spanBuilder('http.request')
            ->startSpan();
        
        // Set standard attributes
        $this->current_span->setAttribute('http.method', $_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->current_span->setAttribute('http.url', isset($_SERVER['REQUEST_URI']) ? home_url($_SERVER['REQUEST_URI']) : '');
        $this->current_span->setAttribute('http.host', $_SERVER['HTTP_HOST'] ?? '');
        $this->current_span->setAttribute('http.user_agent', $_SERVER['HTTP_USER_AGENT'] ?? '');
        $this->current_span->setAttribute('service.name', get_option('wp_otel_service_name', 'wordpress'));
        
        // Store the current trace and span ID for access in the request
        $this->store_trace_context();
    }
    
    /**
     * End the main request span
     */
    public function end_request_span() {
        if (!isset($this->current_span)) {
            return;
        }
        
        if (is_array($this->current_span)) {
            // For simplified implementation, calculate duration
            $end_time = microtime(true);
            $duration = ($end_time - $this->current_span['start_time']) * 1000; // ms
            
            // Send span data as a metric
            if (isset($this->exporter)) {
                $status_code = function_exists('http_response_code') ? http_response_code() : 200;
                
                $attributes = array_merge($this->current_span['attributes'], [
                    'http.status_code' => $status_code,
                    'http.duration_ms' => $duration
                ]);
                
                $this->exporter->export('http.request', $duration, $attributes);
            }
            
            return;
        }
        
        // For OpenTelemetry SDK implementation
        if (method_exists($this->current_span, 'end')) {
            // Set the status code
            if (function_exists('http_response_code')) {
                $status_code = http_response_code();
                $this->current_span->setAttribute('http.status_code', $status_code);
            }
            
            // End the span
            $this->current_span->end();
        }
    }
    
    /**
     * Store the current trace context for access during the request
     */
    private function store_trace_context() {
        if (!isset($this->current_span)) return;
        
        if (is_array($this->current_span)) {
            // For simplified implementation
            $trace_context = [
                'trace_id' => $this->current_span['trace_id'],
                'span_id' => $this->current_span['span_id']
            ];
        } else {
            // For OpenTelemetry SDK implementation
            $context = $this->current_span->getContext();
            $trace_context = [
                'trace_id' => $context->getTraceId(),
                'span_id' => $context->getSpanId()
            ];
        }
        
        // Store as a transient for very short access
        set_transient('wp_otel_trace_id', $trace_context['trace_id'], 60);
        set_transient('wp_otel_span_id', $trace_context['span_id'], 60);
        
        // Also make it available as a global
        $GLOBALS['wp_otel_trace_context'] = $trace_context;
    }
    
    /**
     * Generate a random ID for trace or span
     */
    private function generate_id($bytes) {
        return bin2hex(random_bytes($bytes));
    }
    
    /**
     * Get the current trace context
     * @return array|null Trace context or null if not available
     */
    public function get_trace_context() {
        if (isset($GLOBALS['wp_otel_trace_context'])) {
            return $GLOBALS['wp_otel_trace_context'];
        }
        
        // Try to get from transient
        $trace_id = get_transient('wp_otel_trace_id');
        $span_id = get_transient('wp_otel_span_id');
        
        if ($trace_id && $span_id) {
            return [
                'trace_id' => $trace_id,
                'span_id' => $span_id
            ];
        }
        
        return null;
    }
    
    /**
     * Create a new child span
     * @param string $name Span name
     * @param array $attributes Span attributes
     * @return mixed Span object or null
     */
    public function create_span($name, $attributes = []) {
        if (!isset($this->current_span)) return null;
        
        if (is_array($this->current_span)) {
            // For simplified implementation
            $span = [
                'trace_id' => $this->current_span['trace_id'],
                'span_id' => $this->generate_id(8),
                'parent_id' => $this->current_span['span_id'],
                'name' => $name,
                'start_time' => microtime(true),
                'attributes' => $attributes
            ];
            
            return new WP_OTEL_Simple_Span($span, $this->exporter);
        }
        
        // For OpenTelemetry SDK implementation
        if (isset($this->tracer) && method_exists($this->tracer, 'spanBuilder')) {
            // Create child span from current span
            $span = $this->tracer->spanBuilder($name)
                ->startSpan();
            
            // Set attributes
            foreach ($attributes as $key => $value) {
                $span->setAttribute($key, $value);
            }
            
            return $span;
        }
        
        return null;
    }
    
    public function record_metric($metric_name, $value, $attributes = []) {
        // Only proceed if we have OpenTelemetry set up
        if (empty($this->meter) && empty($this->exporter)) {
            return;
        }
        
        // Add standard attributes including trace context
        $trace_context = $this->get_trace_context();
        $attributes = array_merge([
            'service.name' => get_option('wp_otel_service_name', 'wordpress'),
            'application' => 'wordpress',
        ], $attributes);
        
        if ($trace_context) {
            $attributes['trace_id'] = $trace_context['trace_id'];
            $attributes['span_id'] = $trace_context['span_id'];
        }
        
        // Debug mode
        if (get_option('wp_otel_debug_mode') == '1') {
            error_log(sprintf(
                'OpenTelemetry metric: %s = %s with attributes: %s',
                $metric_name,
                $value,
                json_encode($attributes)
            ));
        }
        
        // Record metric based on type
        if ($metric_name === 'request_latency' && isset($this->requestLatency)) {
            $this->requestLatency->record($value, $attributes);
        } elseif ($metric_name === 'error_rate' && isset($this->errorCounter) && $value == 1) {
            $this->errorCounter->add(1, $attributes);
        } elseif (isset($this->exporter)) {
            // Use simplified exporter as fallback
            $this->exporter->export($metric_name, $value, $attributes);
        }
    }
}

/**
 * Simple span implementation for when OpenTelemetry SDK is not available
 */
class WP_OTEL_Simple_Span {
    private $data;
    private $exporter;
    private $ended = false;
    
    public function __construct($data, $exporter) {
        $this->data = $data;
        $this->exporter = $exporter;
    }
    
    public function setAttribute($key, $value) {
        $this->data['attributes'][$key] = $value;
        return $this;
    }
    
    public function end() {
        if ($this->ended) return;
        
        $this->ended = true;
        $end_time = microtime(true);
        $duration = ($end_time - $this->data['start_time']) * 1000; // ms
        
        // Export the span as a metric
        if ($this->exporter) {
            $this->data['attributes']['duration_ms'] = $duration;
            $this->exporter->export('span.' . $this->data['name'], $duration, $this->data['attributes']);
        }
    }
    
    public function getContext() {
        return [
            'getTraceId' => function() { return $this->data['trace_id']; },
            'getSpanId' => function() { return $this->data['span_id']; }
        ];
    }
}

/**
 * Simple OpenTelemetry exporter that doesn't require the full SDK
 */
class WP_OTEL_Simple_Exporter {
    private $endpoint;
    private $service_name;
    private $debug_mode;
    
    public function __construct() {
        $this->endpoint = get_option('wp_otel_endpoint', 'http://localhost:9411/api/v2/spans');
        $this->service_name = get_option('wp_otel_service_name', 'wordpress');
        $this->debug_mode = get_option('wp_otel_debug_mode', '0') == '1';
    }
    
    public function export($name, $value, $attributes = []) {
        // Only export if we have an endpoint configured
        if (empty($this->endpoint) || $this->endpoint === 'http://localhost:9411/api/v2/spans') {
            // Don't try to export if using the default endpoint and it hasn't been changed
            if ($this->debug_mode) {
                error_log('OpenTelemetry export skipped: No custom endpoint configured');
            }
            return false;
        }
        
        // Create a simple metric in OpenTelemetry format
        $timestamp = microtime(true) * 1000000; // microseconds
        
        $metric = [
            'name' => $name,
            'kind' => 'metric',
            'timestamp' => $timestamp,
            'value' => $value,
            'attributes' => $attributes
        ];
        
        // Add service name if not already in attributes
        if (!isset($attributes['service.name'])) {
            $metric['attributes']['service.name'] = $this->service_name;
        }
        
        // Debug mode - log to WordPress error log
        if ($this->debug_mode) {
            error_log('OpenTelemetry export: ' . json_encode($metric));
        }
        
        // Send using curl
        return $this->send_metric($metric);
    }
    
    private function send_metric($metric) {
        // Only proceed if curl is available
        if (!function_exists('curl_init')) {
            if ($this->debug_mode) {
                error_log('OpenTelemetry export error: curl extension not available');
            }
            return false;
        }
        
        // Format for Zipkin-style endpoint
        $zipkin_span = $this->format_for_zipkin($metric);
        
        // Log the formatted span in debug mode
        if ($this->debug_mode) {
            error_log('OpenTelemetry Zipkin format: ' . json_encode($zipkin_span));
        }
        
        // Encode the metric
        $json = json_encode([$zipkin_span]);
        
        // Send using curl
        $ch = curl_init($this->endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json)
        ]);
        
        // Set a reasonable timeout
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        // Execute curl request
        $result = curl_exec($ch);
        $error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Log errors in debug mode
        if ($this->debug_mode) {
            error_log('OpenTelemetry export response: HTTP ' . $http_code . ' - ' . ($result ?: $error));
        }
        
        return ($http_code >= 200 && $http_code < 300);
    }
    
    /**
     * Format a metric in Zipkin format
     */
    // private function format_for_zipkin($metric) {
    //     // Extract trace and span IDs if available
    //     $trace_id = isset($metric['attributes']['trace_id']) ? $metric['attributes']['trace_id'] : $this->generate_id(16);
    //     $span_id = isset($metric['attributes']['span_id']) ? $metric['attributes']['span_id'] : $this->generate_id(8);
        
    //     // Remove from attributes to avoid duplication
    //     unset($metric['attributes']['trace_id']);
    //     unset($metric['attributes']['span_id']);
        
    //     // Convert to microseconds for Zipkin
    //     $timestamp_micros = floor($metric['timestamp']);
    //     $duration_micros = isset($metric['attributes']['duration_ms']) ? 
    //         floor($metric['attributes']['duration_ms'] * 1000) : 1000; // Default to 1ms
        
    //     // Ensure service name is set directly in localEndpoint
    //     $service_name = $this->service_name;
    //     if (isset($metric['attributes']['service.name'])) {
    //         $service_name = $metric['attributes']['service.name'];
    //     }
        
    //     // Format for Zipkin
    //     return [
    //         'id' => $span_id,
    //         'traceId' => $trace_id,
    //         'name' => $metric['name'],
    //         'timestamp' => $timestamp_micros,
    //         'duration' => $duration_micros,
    //         'localEndpoint' => [
    //             'serviceName' => $service_name
    //         ],
    //         'tags' => $this->format_tags($metric['attributes'], $metric['value'])
    //     ];
    // }
    private function format_for_zipkin($metric) {
        // Extract trace and span IDs if available, or generate new ones
        $trace_id = isset($metric['attributes']['trace_id']) ? $metric['attributes']['trace_id'] : $this->generate_id(16);
        $span_id = isset($metric['attributes']['span_id']) ? $metric['attributes']['span_id'] : $this->generate_id(8);
        
        // Get service name from attributes or use the configured default
        $service_name = isset($metric['attributes']['service.name']) ? 
                         $metric['attributes']['service.name'] : 
                         $this->service_name;
        
        // Convert to microseconds for Zipkin if needed
        $timestamp_micros = is_int($metric['timestamp']) ? $metric['timestamp'] : round(microtime(true) * 1000000);
        
        // Use the real duration if available, or calculate it
        $duration_micros = isset($metric['attributes']['duration_ms']) ? 
                            floor($metric['attributes']['duration_ms'] * 1000) : 
                            1000; // Default to 1ms
        
        // Create tags from attributes and value
        $tags = ['value' => (string)$metric['value']];
        foreach ($metric['attributes'] as $key => $value) {
            // Skip special attributes we're using elsewhere
            if ($key != 'trace_id' && $key != 'span_id' && $key != 'service.name' && $key != 'duration_ms') {
                // Zipkin tags must be strings
                $tags[$key] = is_array($value) || is_object($value) ? json_encode($value) : (string)$value;
            }
        }
        
        // Format for Zipkin
        $span = [
            'id' => $span_id,
            'traceId' => $trace_id,
            'name' => $metric['name'],
            'timestamp' => $timestamp_micros,
            'duration' => $duration_micros,
            'localEndpoint' => [
                'serviceName' => $service_name
            ],
            'tags' => $tags
        ];
        
        // Debug output in log if enabled
        if ($this->debug_mode) {
            error_log('Zipkin span: ' . json_encode($span));
        }
        
        return $span;
    }
    
    
    /**
     * Format attributes and value as tags for Zipkin
     */
    private function format_tags($attributes, $value) {
        $tags = ['value' => (string)$value];
        
        foreach ($attributes as $key => $attr_value) {
            // Zipkin only supports string values for tags
            $tags[$key] = is_array($attr_value) || is_object($attr_value) ? 
                json_encode($attr_value) : (string)$attr_value;
        }
        
        return $tags;
    }
    
    /**
     * Generate a random ID for trace or span
     */
    private function generate_id($bytes) {
        return bin2hex(random_bytes($bytes));
    }
}
<?php
/**
 * Core Plugin functionality for WP OpenTelemetry
 */

namespace WPOtel;

class CorePlugin {
    
    private $config;
    private $is_pro;
    private $current_trace;
    private $start_time;
    
    // Pro components
    private $batch_manager;
    private $exporter_manager;
    
    public function __construct($config, $is_pro = false) {
        $this->config = $config;
        $this->is_pro = $is_pro;
    }
    
    public function set_pro_components($batch_manager, $exporter_manager) {
        $this->batch_manager = $batch_manager;
        $this->exporter_manager = $exporter_manager;
    }
    
    public function init() {
        if (!$this->config['enabled']) {
            return;
        }
        
        // Core tracing hooks
        add_action('init', [$this, 'start_trace'], 1);
        
        // Use appropriate end trace method based on license
        if ($this->is_pro && $this->batch_manager) {
            add_action('shutdown', [$this, 'end_trace_pro'], 9999);
        } else {
            add_action('shutdown', [$this, 'end_trace'], 9999);
        }
    }
    
    public function is_enabled() {
        return $this->config['enabled'];
    }
    
    public function get_config() {
        return $this->config;
    }
    
    public function get_current_trace() {
        return $this->current_trace;
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
                        ['key' => 'plugin.version', 'value' => WP_OTEL_VERSION]
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
    
    public function add_span_to_current_trace($span_data) {
        if (!$this->current_trace) {
            return false;
        }
        
        $this->current_trace['spans'][] = $span_data;
        return true;
    }
    
    public function generate_id($hex_length) {
        return bin2hex(random_bytes($hex_length / 2));
    }
    
    public function log($message) {
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
    
    private function convert_to_zipkin_format() {
        $zipkin_spans = [];
        
        // Process ALL spans in the current trace, not just the first one
        foreach ($this->current_trace['spans'] as $span) {
            $tags = [];
            
            // Convert tags from array format to object format
            foreach ($span['tags'] as $tag) {
                $tags[$tag['key']] = (string)$tag['value'];
            }
            
            $zipkin_span = [
                'id' => $span['spanID'],
                'traceId' => $this->current_trace['traceID'], // Use trace ID from trace, not span
                'name' => $span['operationName'],
                'timestamp' => (int)$span['startTime'],
                'duration' => (int)($span['duration'] ?? 0),
                'localEndpoint' => [
                    'serviceName' => $this->config['service_name']
                ],
                'tags' => $tags
            ];
            
            // Add parent relationship if it exists
            if (isset($span['parentSpanID'])) {
                $zipkin_span['parentId'] = $span['parentSpanID'];
            }
            
            $zipkin_spans[] = $zipkin_span;
        }
        
        if ($this->config['debug_mode']) {
            $this->log(sprintf(
                'Converted trace to Zipkin format: %d spans (Trace ID: %s)',
                count($zipkin_spans),
                $this->current_trace['traceID']
            ));
            
            // Log each span for debugging
            foreach ($zipkin_spans as $i => $span) {
                $this->log(sprintf(
                    'Zipkin span %d: %s (parent: %s)',
                    $i + 1,
                    $span['name'],
                    $span['parentId'] ?? 'none'
                ));
            }
        }
        
        return $zipkin_spans;
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
}
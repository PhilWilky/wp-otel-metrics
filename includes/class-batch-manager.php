<?php
/**
 * Batch Manager for WP OpenTelemetry Pro
 * Handles batching and periodic export of traces
 */

namespace WPOtel\Pro;

class BatchManager {
    
    private $config;
    private $batch_option_key = 'wpotel_trace_batch';
    private $last_export_key = 'wpotel_last_export';
    
    public function __construct($config) {
        $this->config = $config;
        
        // Register shutdown hook for batch export
        add_action('shutdown', [$this, 'maybe_export_batch'], 9998);
        
        // Register cron for periodic exports
        add_action('wpotel_batch_export', [$this, 'export_batch']);
        
        // Schedule cron if not already scheduled
        if (!wp_next_scheduled('wpotel_batch_export')) {
            wp_schedule_event(time(), 'wpotel_interval', 'wpotel_batch_export');
        }
        
        // Add custom cron interval
        add_filter('cron_schedules', [$this, 'add_cron_interval']);
    }
    
    public function add_cron_interval($schedules) {
        $interval = $this->config['export_interval'] ?? 5;
        $schedules['wpotel_interval'] = [
            'interval' => $interval,
            'display' => sprintf('Every %d seconds', $interval)
        ];
        return $schedules;
    }
    
    public function add_trace($trace) {
        $batch = get_option($this->batch_option_key, []);
        $batch[] = $trace;
        
        update_option($this->batch_option_key, $batch, false);
        
        // Check if we should export immediately
        if (count($batch) >= ($this->config['batch_size'] ?? 50)) {
            $this->export_batch();
        }
    }
    
    public function maybe_export_batch() {
        $last_export = get_option($this->last_export_key, 0);
        $interval = $this->config['export_interval'] ?? 5;
        
        // Export if interval has passed
        if ((time() - $last_export) >= $interval) {
            $this->export_batch();
        }
    }
    
    public function export_batch() {
        $batch = get_option($this->batch_option_key, []);
        
        if (empty($batch)) {
            return;
        }
        
        // Log batch export
        if ($this->config['debug_mode']) {
            error_log(sprintf(
                '[WP OpenTelemetry] Exporting batch of %d traces',
                count($batch)
            ));
        }
        
        // If we have an exporter manager, use it
        if (isset($GLOBALS['wpotel_exporter_manager'])) {
            $GLOBALS['wpotel_exporter_manager']->export_to_all($batch);
        } else {
            // Fallback to direct export
            $endpoint = $this->config['endpoint'];
            
            if (strpos($endpoint, '9411') !== false) {
                // Zipkin format
                $data = $this->convert_batch_to_zipkin($batch);
            } else {
                // Jaeger format
                $data = ['batch' => $batch];
            }
            
            // Compression if enabled
            if ($this->config['compression'] ?? false) {
                $body = gzencode(json_encode($data));
                $headers = [
                    'Content-Type' => 'application/json',
                    'Content-Encoding' => 'gzip'
                ];
            } else {
                $body = json_encode($data);
                $headers = ['Content-Type' => 'application/json'];
            }
            
            // Send the batch
            $response = wp_remote_post($endpoint, [
                'body' => $body,
                'headers' => $headers,
                'timeout' => 2,
                'blocking' => false
            ]);
            
            if ($this->config['debug_mode'] && !is_wp_error($response)) {
                error_log(sprintf(
                    '[WP OpenTelemetry] Batch exported successfully (%d traces) to %s',
                    count($batch),
                    $endpoint
                ));
            }
        }
        
        // Clear the batch
        update_option($this->batch_option_key, [], false);
        update_option($this->last_export_key, time());
    }
    
    private function convert_batch_to_zipkin($batch) {
        $zipkin_spans = [];
        
        foreach ($batch as $trace) {
            // Process ALL spans in each trace, not just the first one
            foreach ($trace['spans'] as $span) {
                $tags = [];
                
                // Convert tags from array format to object format
                foreach ($span['tags'] as $tag) {
                    $tags[$tag['key']] = (string)$tag['value'];
                }
                
                $zipkin_span = [
                    'id' => $span['spanID'],
                    'traceId' => $trace['traceID'], // Use trace ID from trace root
                    'name' => $span['operationName'],
                    'timestamp' => (int)$span['startTime'],
                    'duration' => (int)$span['duration'],
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
        }
        
        if ($this->config['debug_mode']) {
            error_log(sprintf(
                '[WP OpenTelemetry] Converted batch to Zipkin: %d total spans from %d traces',
                count($zipkin_spans),
                count($batch)
            ));
            
            // Log span breakdown
            $span_types = [];
            foreach ($zipkin_spans as $span) {
                $span_types[$span['name']] = ($span_types[$span['name']] ?? 0) + 1;
            }
            
            foreach ($span_types as $type => $count) {
                error_log(sprintf(
                    '[WP OpenTelemetry] - %s: %d spans',
                    $type,
                    $count
                ));
            }
        }
        
        return $zipkin_spans;
    }
}
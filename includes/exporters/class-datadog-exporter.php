<?php
/**
 * DataDog Exporter
 */

namespace WPOtel\Pro\Exporters;

class DatadogExporter extends BaseExporter {
    
    public function get_name() {
        return 'Datadog';
    }
    
    public function get_settings() {
        return [
            'api_key' => [
                'label' => 'API Key',
                'type' => 'text',
                'default' => '',
                'description' => 'Your Datadog API key'
            ],
            'region' => [
                'label' => 'Region',
                'type' => 'select',
                'default' => 'us1',
                'options' => [
                    'us1' => 'US1',
                    'us3' => 'US3',
                    'us5' => 'US5',
                    'eu1' => 'EU1'
                ],
                'description' => 'Datadog region'
            ]
        ];
    }
    
    public function export($traces) {
        // Datadog API endpoints based on region
        $region = get_option('wpotel_exporter_datadog_region', 'us1');
        $api_key = get_option('wpotel_exporter_datadog_api_key', '');
        
        if (empty($api_key)) {
            if ($this->config['debug_mode']) {
                error_log('[WP OpenTelemetry] Datadog export failed: No API key configured');
            }
            return false;
        }
        
        $endpoints = [
            'us1' => 'https://http-intake.logs.datadoghq.com',
            'us3' => 'https://http-intake.logs.us3.datadoghq.com',
            'us5' => 'https://http-intake.logs.us5.datadoghq.com',
            'eu1' => 'https://http-intake.logs.datadoghq.eu'
        ];
        
        $endpoint = $endpoints[$region] ?? $endpoints['us1'];
        $endpoint .= '/api/v1/traces';
        
        // Convert traces to Datadog format
        $datadog_traces = $this->convert_to_datadog_format($traces);
        
        $headers = [
            'DD-API-KEY' => $api_key,
            'Content-Type' => 'application/json'
        ];
        
        return $this->send_http_request($endpoint, $datadog_traces, $headers);
    }
    
    private function convert_to_datadog_format($traces) {
        $datadog_spans = [];
        
        foreach ($traces as $trace) {
            if (isset($trace['spans'][0])) {
                $span = $trace['spans'][0];
                
                // Convert tags array to object
                $tags = [];
                foreach ($span['tags'] as $tag) {
                    $tags[$tag['key']] = (string)$tag['value'];
                }
                
                $datadog_spans[] = [
                    'trace_id' => hexdec(substr($span['traceID'], 0, 16)),
                    'span_id' => hexdec(substr($span['spanID'], 0, 16)),
                    'parent_id' => null,
                    'name' => $span['operationName'],
                    'resource' => $tags['http.url'] ?? $span['operationName'],
                    'service' => $this->config['service_name'],
                    'type' => 'web',
                    'start' => (int)($span['startTime'] * 1000), // Convert to nanoseconds
                    'duration' => (int)($span['duration'] * 1000),
                    'meta' => $tags,
                    'metrics' => []
                ];
            }
        }
        
        return [$datadog_spans]; // Datadog expects array of traces
    }
}
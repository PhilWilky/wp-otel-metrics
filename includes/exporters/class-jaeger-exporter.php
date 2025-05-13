<?php
/**
 * Jaeger Exporter
 */

namespace WPOtel\Pro\Exporters;

class JaegerExporter extends BaseExporter {
    
    public function get_name() {
        return 'Jaeger';
    }
    
    public function get_settings() {
        return [
            'endpoint' => [
                'label' => 'Collector Endpoint',
                'type' => 'text',
                'default' => 'http://localhost:14268/api/traces',
                'description' => 'Jaeger collector HTTP endpoint'
            ]
        ];
    }
    
    public function export($traces) {
        $endpoint = get_option('wpotel_exporter_jaeger_endpoint', $this->config['endpoint']);
        
        // Check if it's Zipkin format
        if (strpos($endpoint, '9411') !== false) {
            // Convert to Zipkin format
            $data = $this->convert_to_zipkin_format($traces);
        } else {
            // Standard Jaeger format
            $data = ['batch' => $traces];
        }
        
        return $this->send_http_request($endpoint, $data);
    }
    
    private function convert_to_zipkin_format($traces) {
        $zipkin_spans = [];
        
        foreach ($traces as $trace) {
            if (isset($trace['spans'][0])) {
                $span = $trace['spans'][0];
                $tags = [];
                
                foreach ($span['tags'] as $tag) {
                    $tags[$tag['key']] = (string)$tag['value'];
                }
                
                $zipkin_spans[] = [
                    'id' => $span['spanID'],
                    'traceId' => $span['traceID'],
                    'name' => $span['operationName'],
                    'timestamp' => (int)$span['startTime'],
                    'duration' => (int)$span['duration'],
                    'localEndpoint' => [
                        'serviceName' => $this->config['service_name']
                    ],
                    'tags' => $tags
                ];
            }
        }
        
        return $zipkin_spans;
    }
}
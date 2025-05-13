<?php
/**
 * OTLP Exporter for WP OpenTelemetry Pro
 */

namespace WPOtel\Pro\Exporters;

class OTLPExporter extends BaseExporter {
    public function get_name() {
        return 'OTLP';
    }
    
    public function get_description() {
        return 'Export using OpenTelemetry Protocol to any compatible backend';
    }
    
    public function get_settings_fields() {
        return [
            'endpoint' => [
                'label' => 'OTLP Endpoint',
                'type' => 'text',
                'default' => 'http://localhost:4318/v1/traces',
                'description' => 'OTLP HTTP endpoint'
            ],
            'headers' => [
                'label' => 'Custom Headers',
                'type' => 'textarea',
                'default' => '',
                'description' => 'Custom headers in format: key=value (one per line)'
            ]
        ];
    }
    
    public function export($data) {
        $endpoint = get_option('wpotel_exporter_otlp_endpoint', 'http://localhost:4318/v1/traces');
        $headers_raw = get_option('wpotel_exporter_otlp_headers', '');
        
        // Parse custom headers
        $headers = ['Content-Type' => 'application/json'];
        if ($headers_raw) {
            foreach (explode("\n", $headers_raw) as $header) {
                $parts = explode('=', $header, 2);
                if (count($parts) === 2) {
                    $headers[trim($parts[0])] = trim($parts[1]);
                }
            }
        }
        
        // Convert to OTLP format
        $formatted_data = $this->format_for_otlp($data);
        
        return $this->send_request($endpoint, $formatted_data, $headers);
    }
    
    private function format_for_otlp($data) {
        $resource_spans = [];
        
        foreach ($data as $trace) {
            $scope_spans = [];
            
            foreach ($trace['spans'] as $span) {
                $scope_spans[] = [
                    'traceId' => $span['traceID'],
                    'spanId' => $span['spanID'],
                    'name' => $span['operationName'],
                    'startTimeUnixNano' => (string)($span['startTime'] * 1000),
                    'endTimeUnixNano' => (string)(($span['startTime'] + $span['duration']) * 1000),
                    'attributes' => $this->convert_to_otlp_attributes($span['tags'])
                ];
            }
            
            $resource_spans[] = [
                'resource' => [
                    'attributes' => [
                        [
                            'key' => 'service.name',
                            'value' => ['stringValue' => $this->config['service_name']]
                        ]
                    ]
                ],
                'scopeSpans' => [
                    [
                        'spans' => $scope_spans
                    ]
                ]
            ];
        }
        
        return ['resourceSpans' => $resource_spans];
    }
    
    private function convert_to_otlp_attributes($tags) {
        $attributes = [];
        
        foreach ($tags as $tag) {
            $attribute = [
                'key' => $tag['key'],
                'value' => []
            ];
            
            // Determine value type
            if (is_bool($tag['value'])) {
                $attribute['value']['boolValue'] = $tag['value'];
            } elseif (is_int($tag['value'])) {
                $attribute['value']['intValue'] = (string)$tag['value'];
            } elseif (is_float($tag['value'])) {
                $attribute['value']['doubleValue'] = $tag['value'];
            } else {
                $attribute['value']['stringValue'] = (string)$tag['value'];
            }
            
            $attributes[] = $attribute;
        }
        
        return $attributes;
    }
}
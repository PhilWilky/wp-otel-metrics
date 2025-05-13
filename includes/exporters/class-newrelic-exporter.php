<?php
/**
 * New Relic Exporter for WP OpenTelemetry Pro
 */

namespace WPOtel\Pro\Exporters;

class NewRelicExporter extends BaseExporter {
    public function get_name() {
        return 'New Relic';
    }
    
    public function get_description() {
        return 'Export traces to New Relic APM';
    }
    
    public function get_settings_fields() {
        return [
            'api_key' => [
                'label' => 'Insert API Key',
                'type' => 'text',
                'default' => '',
                'description' => 'Your New Relic Insert API key'
            ],
            'region' => [
                'label' => 'Region',
                'type' => 'select',
                'options' => [
                    'us' => 'US',
                    'eu' => 'EU'
                ],
                'default' => 'us',
                'description' => 'Your New Relic account region'
            ]
        ];
    }
    
    public function export($data) {
        $api_key = get_option('wpotel_exporter_newrelic_api_key');
        $region = get_option('wpotel_exporter_newrelic_region', 'us');
        
        if (!$api_key) {
            error_log('WP OTel: New Relic API key not configured');
            return false;
        }
        
        $endpoint = $region === 'eu' 
            ? 'https://trace-api.eu.newrelic.com/trace/v1'
            : 'https://trace-api.newrelic.com/trace/v1';
        
        $headers = [
            'Api-Key' => $api_key,
            'Content-Type' => 'application/json',
            'Data-Format' => 'zipkin',
            'Data-Format-Version' => '2'
        ];
        
        // Convert to Zipkin format for New Relic
        $formatted_data = $this->format_for_newrelic($data);
        
        return $this->send_request($endpoint, $formatted_data, $headers);
    }
    
    private function format_for_newrelic($data) {
        // Convert to Zipkin format
        $spans = [];
        
        foreach ($data as $trace) {
            foreach ($trace['spans'] as $span) {
                $spans[] = [
                    'id' => $span['spanID'],
                    'traceId' => $span['traceID'],
                    'name' => $span['operationName'],
                    'timestamp' => (int)$span['startTime'],
                    'duration' => (int)$span['duration'],
                    'localEndpoint' => [
                        'serviceName' => $this->config['service_name']
                    ],
                    'tags' => array_merge(
                        $this->convert_tags($span['tags']),
                        ['newrelic.source' => 'wordpress.otel']
                    )
                ];
            }
        }
        
        return $spans;
    }
    
    private function convert_tags($tags) {
        $converted = [];
        foreach ($tags as $tag) {
            $converted[$tag['key']] = (string)$tag['value'];
        }
        return $converted;
    }
}
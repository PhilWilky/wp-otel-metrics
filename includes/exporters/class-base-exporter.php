<?php
/**
 * Base Exporter Class
 */

namespace WPOtel\Pro\Exporters;

abstract class BaseExporter {
    
    protected $config;
    
    public function __construct($config) {
        $this->config = $config;
    }
    
    abstract public function export($traces);
    
    abstract public function get_name();
    
    abstract public function get_settings();
    
    protected function send_http_request($url, $data, $headers = []) {
        $default_headers = ['Content-Type' => 'application/json'];
        $headers = array_merge($default_headers, $headers);
        
        $response = wp_remote_post($url, [
            'body' => json_encode($data),
            'headers' => $headers,
            'timeout' => 2,
            'blocking' => true
        ]);
        
        if (is_wp_error($response)) {
            if ($this->config['debug_mode']) {
                error_log('[WP OpenTelemetry] Export error: ' . $response->get_error_message());
            }
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        return $status_code >= 200 && $status_code < 300;
    }
}
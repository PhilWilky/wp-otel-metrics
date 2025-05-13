<?php
/**
 * Webhook Exporter for WP OpenTelemetry Pro
 */

namespace WPOtel\Pro\Exporters;

class WebhookExporter extends BaseExporter {
    public function get_name() {
        return 'Custom Webhook';
    }
    
    public function get_description() {
        return 'Export traces to a custom webhook endpoint';
    }
    
    public function get_settings_fields() {
        return [
            'url' => [
                'label' => 'Webhook URL',
                'type' => 'text',
                'default' => '',
                'description' => 'Your custom webhook endpoint'
            ],
            'auth_header' => [
                'label' => 'Authorization Header',
                'type' => 'text',
                'default' => '',
                'description' => 'Optional authorization header value'
            ]
        ];
    }
    
    public function export($data) {
        $url = get_option('wpotel_exporter_webhook_url');
        $auth_header = get_option('wpotel_exporter_webhook_auth_header');
        
        if (!$url) {
            error_log('WP OTel: Webhook URL not configured');
            return false;
        }
        
        $headers = ['Content-Type' => 'application/json'];
        if ($auth_header) {
            $headers['Authorization'] = $auth_header;
        }
        
        return $this->send_request($url, $data, $headers);
    }
}
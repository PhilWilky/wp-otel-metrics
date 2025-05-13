<?php
/**
 * License Manager for WP OpenTelemetry Pro
 */

namespace WPOtel\Pro;

class LicenseManager {
    private $api_url = 'https://yoursite.com/wp-json/license/v1/';
    private $product_id = 'wp-otel-pro';
    
    public function __construct() {
        add_action('admin_init', [$this, 'activate_license']);
        add_action('wpotel_daily_license_check', [$this, 'check_license']);
        
        if (!wp_next_scheduled('wpotel_daily_license_check')) {
            wp_schedule_event(time(), 'daily', 'wpotel_daily_license_check');
        }
    }
    
    public function activate_license() {
        if (!isset($_POST['wpotel_activate_license'])) {
            return;
        }
        
        $license_key = sanitize_text_field($_POST['wpotel_license_key']);
        
        // For testing, use simple validation
        // In production, make API call to your license server
        $valid_licenses = ['DEMO-PRO-KEY', 'TEST-LICENSE-123'];
        
        if (in_array($license_key, $valid_licenses)) {
            update_option('wpotel_license_key', $license_key);
            update_option('wpotel_license_status', 'active');
            update_option('wpotel_license_expires', date('Y-m-d', strtotime('+1 year')));
            
            add_settings_error('wpotel_settings', 'license_activated', 'License activated successfully!', 'updated');
        } else {
            add_settings_error('wpotel_settings', 'license_error', 'Invalid license key', 'error');
        }
    }
    
    public function is_valid() {
        $status = get_option('wpotel_license_status');
        $expires = get_option('wpotel_license_expires');
        
        return $status === 'active' && strtotime($expires) > time();
    }
    
    public function get_status() {
        return [
            'valid' => $this->is_valid(),
            'status' => get_option('wpotel_license_status', 'inactive'),
            'expires' => get_option('wpotel_license_expires'),
            'features' => get_option('wpotel_license_features', [])
        ];
    }
    
    public function check_license() {
        $license_key = get_option('wpotel_license_key');
        if (!$license_key) {
            return;
        }
        
        // In production, make API call to validate license
        // For now, just check if it's still in valid list
        $valid_licenses = ['DEMO-PRO-KEY', 'TEST-LICENSE-123'];
        
        if (!in_array($license_key, $valid_licenses)) {
            update_option('wpotel_license_status', 'inactive');
        }
    }
}
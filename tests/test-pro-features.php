// tests/test-pro-features.php
class Test_Pro_Features extends WP_UnitTestCase {
    
    public function test_license_activation() {
        $license_manager = new \WPOtel\Pro\LicenseManager();
        
        // Mock license key
        update_option('wpotel_license_key', 'TEST-KEY-123');
        update_option('wpotel_license_status', 'active');
        
        $this->assertTrue($license_manager->is_valid());
    }
    
    public function test_batch_manager() {
        $config = ['batch_size' => 2];
        $batch_manager = new \WPOtel\Pro\BatchManager($config);
        
        // Add traces
        $batch_manager->add_to_batch(['trace1']);
        $batch_manager->add_to_batch(['trace2']);
        
        // Should trigger export
        $this->expectAction('wpotel_export_batch');
    }
    
    public function test_multiple_exporters() {
        $config = [];
        $exporter_manager = new \WPOtel\Pro\ExporterManager($config);
        
        $exporters = $exporter_manager->get_available_exporters();
        
        $this->assertArrayHasKey('jaeger', $exporters);
        $this->assertArrayHasKey('datadog', $exporters);
        $this->assertArrayHasKey('newrelic', $exporters);
    }
}
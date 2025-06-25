<?php
/**
 * Autoloader for WP OpenTelemetry plugin
 */

namespace WPOtel;

class Autoloader {
    
    public function __construct() {
        spl_autoload_register([$this, 'autoload']);
    }
    
    public function autoload($class_name) {
        // Only handle our namespace
        if (strpos($class_name, 'WPOtel\\') !== 0) {
            return;
        }
        
        // Remove namespace prefix
        $class_name = str_replace('WPOtel\\', '', $class_name);
        
        // Handle Pro namespace
        if (strpos($class_name, 'Pro\\') === 0) {
            $class_name = str_replace('Pro\\', '', $class_name);
            
            // Handle exporters
            if (strpos($class_name, 'Exporters\\') === 0) {
                $class_name = str_replace('Exporters\\', '', $class_name);
                $file_name = 'class-' . strtolower(str_replace('_', '-', $this->camel_to_snake($class_name))) . '.php';
                $file_path = WP_OTEL_PLUGIN_DIR . 'includes/exporters/' . $file_name;
            } else {
                $file_name = 'class-' . strtolower(str_replace('_', '-', $this->camel_to_snake($class_name))) . '.php';
                $file_path = WP_OTEL_PLUGIN_DIR . 'includes/' . $file_name;
            }
        } else {
            // Regular classes
            $file_name = 'class-' . strtolower(str_replace('_', '-', $this->camel_to_snake($class_name))) . '.php';
            $file_path = WP_OTEL_PLUGIN_DIR . 'includes/' . $file_name;
        }
        
        if (file_exists($file_path)) {
            require_once $file_path;
        }
    }
    
    private function camel_to_snake($input) {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $input));
    }
}
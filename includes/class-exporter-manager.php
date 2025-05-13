<?php
/**
 * Exporter Manager for WP OpenTelemetry Pro
 * Manages multiple export destinations
 */

namespace WPOtel\Pro;

class ExporterManager {
    
    private $config;
    private $exporters = [];
    
    public function __construct($config) {
        $this->config = $config;
        $this->load_exporters();
    }
    
    private function load_exporters() {
        // Load all available exporters
        $exporter_files = [
            'jaeger' => 'class-jaeger-exporter.php',
            'datadog' => 'class-datadog-exporter.php',
            // 'newrelic' => 'class-newrelic-exporter.php',
            // 'otlp' => 'class-otlp-exporter.php'
        ];
        
        foreach ($exporter_files as $key => $file) {
            $file_path = plugin_dir_path(dirname(__FILE__)) . 'includes/exporters/' . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
                $class_name = '\\WPOtel\\Pro\\Exporters\\' . ucfirst($key) . 'Exporter';
                if (class_exists($class_name)) {
                    $this->exporters[$key] = new $class_name($this->config);
                }
            }
        }
    }
    
    public function get_available_exporters() {
        return [
            'jaeger' => [
                'name' => 'Jaeger',
                'description' => 'Open source distributed tracing system',
                'settings' => [
                    'endpoint' => [
                        'label' => 'Collector Endpoint',
                        'type' => 'text',
                        'default' => 'http://localhost:14268/api/traces',
                        'description' => 'Jaeger collector HTTP endpoint'
                    ]
                ]
            ],
            'datadog' => [
                'name' => 'Datadog',
                'description' => 'Cloud monitoring and analytics platform',
                'settings' => [
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
                ]
            ],
            'newrelic' => [
                'name' => 'New Relic',
                'description' => 'Application performance monitoring',
                'settings' => [
                    'api_key' => [
                        'label' => 'License Key',
                        'type' => 'text',
                        'default' => '',
                        'description' => 'Your New Relic license key'
                    ],
                    'region' => [
                        'label' => 'Data Center',
                        'type' => 'select',
                        'default' => 'us',
                        'options' => [
                            'us' => 'United States',
                            'eu' => 'Europe'
                        ],
                        'description' => 'New Relic data center region'
                    ]
                ]
            ],
            'otlp' => [
                'name' => 'OTLP',
                'description' => 'OpenTelemetry Protocol - Works with any OTLP-compatible backend',
                'settings' => [
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
                        'description' => 'Custom headers (one per line, format: Header-Name: value)'
                    ]
                ]
            ]
        ];
    }
    
    public function export_to_all($traces) {
        $enabled_exporters = $this->config['enabled_exporters'] ?? ['jaeger'];
        $results = [];
        
        foreach ($enabled_exporters as $exporter_key) {
            if (isset($this->exporters[$exporter_key])) {
                $result = $this->exporters[$exporter_key]->export($traces);
                $results[$exporter_key] = $result;
                
                if ($this->config['debug_mode']) {
                    error_log(sprintf(
                        '[WP OpenTelemetry] Exported to %s: %s',
                        $exporter_key,
                        $result ? 'success' : 'failed'
                    ));
                }
            }
        }
        
        return $results;
    }
    
    public function test_exporter($exporter_key) {
        if (!isset($this->exporters[$exporter_key])) {
            return ['success' => false, 'message' => 'Exporter not found'];
        }
        
        // Create a test trace
        $test_trace = [
            'traceID' => bin2hex(random_bytes(16)),
            'spans' => [[
                'traceID' => bin2hex(random_bytes(16)),
                'spanID' => bin2hex(random_bytes(8)),
                'operationName' => 'test.exporter',
                'startTime' => microtime(true) * 1000000,
                'duration' => 1000,
                'tags' => [
                    ['key' => 'test', 'value' => true],
                    ['key' => 'exporter', 'value' => $exporter_key]
                ]
            ]]
        ];
        
        $result = $this->exporters[$exporter_key]->export([$test_trace]);
        
        return [
            'success' => $result,
            'message' => $result ? 'Connection successful' : 'Connection failed'
        ];
    }
}
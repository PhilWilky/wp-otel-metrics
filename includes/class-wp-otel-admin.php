<?php
class WP_OTEL_Admin {
    
    public function init() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'OpenTelemetry Metrics',
            'OTel Metrics',
            'manage_options',
            'wp-otel-metrics',
            array($this, 'render_admin_page'),
            'dashicons-chart-area',
            100
        );
    }
    
    public function render_admin_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get metrics
        $metrics = get_option('wp_otel_metrics', array());
        $latency_metrics = isset($metrics['request_latency']) ? $metrics['request_latency'] : array();
        $error_metrics = isset($metrics['error_rate']) ? $metrics['error_rate'] : array();
        
        // Calculate average latency
        $avg_latency = 0;
        if (!empty($latency_metrics)) {
            $total = 0;
            foreach ($latency_metrics as $metric) {
                $total += $metric['value'];
            }
            $avg_latency = $total / count($latency_metrics);
        }
        
        // Calculate error rate percentage
        $error_count = 0;
        $error_rate = 0;
        if (!empty($error_metrics)) {
            foreach ($error_metrics as $metric) {
                if ($metric['value'] == 1) {
                    $error_count++;
                }
            }
            $error_rate = ($error_count / count($error_metrics)) * 100;
        }
        
        ?>
        <div class="wrap">
            <h1>OpenTelemetry Metrics</h1>
            
            <!-- Settings form -->
            <form method="post" action="options.php">
                <?php
                // Output security fields
                settings_fields('wp_otel_settings');
                
                // Output setting sections
                do_settings_sections('wp-otel-metrics');
                
                // Submit button
                submit_button();
                ?>
            </form>
            
            <div class="card" style="margin-top: 20px;">
                <h2>Request Latency</h2>
                <p>Average latency: <strong><?php echo number_format($avg_latency, 2); ?> ms</strong></p>
                <p>Total measurements: <strong><?php echo count($latency_metrics); ?></strong></p>
                
                <?php if (!empty($latency_metrics)): ?>
                <canvas id="latencyChart" width="400" height="200"></canvas>
                <?php endif; ?>
            </div>
            
            <div class="card" style="margin-top: 20px;">
                <h2>Error Rate</h2>
                <p>Error rate: <strong><?php echo number_format($error_rate, 2); ?>%</strong></p>
                <p>Total requests tracked: <strong><?php echo count($error_metrics); ?></strong></p>
                <p>Total errors: <strong><?php echo $error_count; ?></strong></p>
                
                <?php if (!empty($error_metrics)): ?>
                <canvas id="errorChart" width="400" height="200"></canvas>
                <?php endif; ?>
            </div>
            
            <?php if (get_option('wp_otel_debug_mode') == '1'): ?>
            <div class="card" style="margin-top: 20px; border-left: 4px solid #ffba00;">
                <h2>Debug Information</h2>
                <p><strong>Debug Mode:</strong> Enabled</p>
                <p><strong>OpenTelemetry Endpoint:</strong> <?php echo esc_html(get_option('wp_otel_endpoint', 'Not set')); ?></p>
                <p><strong>Service Name:</strong> <?php echo esc_html(get_option('wp_otel_service_name', 'wordpress')); ?></p>
                <p>Check your WordPress error log for OpenTelemetry debug messages.</p>
                
                <?php 
                $trace_context = function_exists('wp_otel_get_trace_context') ? wp_otel_get_trace_context() : null;
                if ($trace_context): 
                ?>
                <h3>Current Trace Context</h3>
                <p><strong>Trace ID:</strong> <?php echo esc_html($trace_context['trace_id'] ?? 'Not available'); ?></p>
                <p><strong>Span ID:</strong> <?php echo esc_html($trace_context['span_id'] ?? 'Not available'); ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            <?php if (!empty($latency_metrics)): ?>
            // Latency Chart
            var latencyCtx = document.getElementById('latencyChart').getContext('2d');
            var latencyData = <?php echo json_encode(array_map(function($metric) {
                return array(
                    'x' => date('H:i:s', $metric['timestamp']),
                    'y' => $metric['value']
                );
            }, $latency_metrics)); ?>;
            
            new Chart(latencyCtx, {
                type: 'line',
                data: {
                    datasets: [{
                        label: 'Request Latency (ms)',
                        data: latencyData,
                        borderColor: 'rgb(75, 192, 192)',
                        tension: 0.1
                    }]
                },
                options: {
                    scales: {
                        x: {
                            type: 'category',
                            title: {
                                display: true,
                                text: 'Time'
                            }
                        },
                        y: {
                            title: {
                                display: true,
                                text: 'Latency (ms)'
                            },
                            beginAtZero: true
                        }
                    }
                }
            });
            <?php endif; ?>
            
            <?php if (!empty($error_metrics)): ?>
            // Error Rate Chart
            var errorCtx = document.getElementById('errorChart').getContext('2d');
            var errorData = <?php echo json_encode(array_map(function($metric) {
                return array(
                    'x' => date('H:i:s', $metric['timestamp']),
                    'y' => $metric['value']
                );
            }, $error_metrics)); ?>;
            
            new Chart(errorCtx, {
                type: 'bar',
                data: {
                    datasets: [{
                        label: 'Error (1) / Success (0)',
                        data: errorData,
                        backgroundColor: function(context) {
                            var value = context.dataset.data[context.dataIndex].y;
                            return value === 1 ? 'rgba(255, 99, 132, 0.5)' : 'rgba(75, 192, 192, 0.5)';
                        },
                        borderColor: function(context) {
                            var value = context.dataset.data[context.dataIndex].y;
                            return value === 1 ? 'rgb(255, 99, 132)' : 'rgb(75, 192, 192)';
                        },
                        borderWidth: 1
                    }]
                },
                options: {
                    scales: {
                        x: {
                            type: 'category',
                            title: {
                                display: true,
                                text: 'Time'
                            }
                        },
                        y: {
                            title: {
                                display: true,
                                text: 'Error Status'
                            },
                            ticks: {
                                stepSize: 1,
                                callback: function(value) {
                                    return value === 1 ? 'Error' : 'Success';
                                }
                            },
                            min: 0,
                            max: 1
                        }
                    }
                }
            });
            <?php endif; ?>
        });
        </script>
        <?php
    }
    
    public function enqueue_admin_scripts($hook) {
        // Only load on our plugin page
        if ($hook != 'toplevel_page_wp-otel-metrics') {
            return;
        }
        
        // Enqueue Chart.js
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js',
            array('jquery'),
            '3.9.1',
            true
        );
    }
}
<?php
/**
 * Admin Page Renderer for WP OpenTelemetry
 */

namespace WPOtel;

class AdminPageRenderer {
    
    private $core_plugin;
    private $is_pro;
    private $config;
    
    public function __construct($core_plugin, $is_pro = false) {
        $this->core_plugin = $core_plugin;
        $this->is_pro = $is_pro;
        $this->config = $core_plugin->get_config();
    }
    
    public function render() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $metrics = get_option('wpotel_metrics', []);
        $latency_metrics = $metrics['request_latency'] ?? [];
        
        // Calculate stats
        $stats = $this->calculate_performance_stats($latency_metrics);
        
        ?>
        <div class="wrap">
            <h1>
                OpenTelemetry Metrics 
                <?php if ($this->is_pro): ?>
                    <span class="wpotel-pro-badge">PRO</span>
                <?php endif; ?>
            </h1>
            
            <?php settings_errors('wpotel_settings'); ?>
            
            <?php $this->render_upgrade_notice(); ?>
            
            <!-- Tab Navigation -->
            <h2 class="nav-tab-wrapper">
                <a href="#settings" class="nav-tab nav-tab-active">Settings</a>
                <?php if ($this->is_pro): ?>
                <a href="#exporters" class="nav-tab">Exporters</a>
                <a href="#performance" class="nav-tab">Performance</a>
                <?php endif; ?>
                <a href="#license" class="nav-tab">License</a>
            </h2>
            
            <form method="post" action="options.php">
                <!-- Settings Tab -->
                <div id="settings" class="wpotel-tab-content">
                    <?php
                    settings_fields('wpotel_settings');
                    do_settings_sections('wp-otel-metrics');
                    
                    if ($this->is_pro) {
                        do_settings_sections('wp-otel-pro');
                    }
                    
                    submit_button();
                    ?>
                </div>
                
                <?php if ($this->is_pro): ?>
                    <?php $this->render_exporters_tab(); ?>
                    <?php $this->render_performance_tab($stats); ?>
                <?php endif; ?>
                
                <?php $this->render_license_tab(); ?>
            </form>
            
            <?php $this->render_performance_summary($stats); ?>
            <?php $this->render_quick_start_guide(); ?>
            <?php $this->render_debug_info(); ?>
            <?php $this->render_charts($latency_metrics); ?>
        </div>
        <?php
    }
    
    private function calculate_performance_stats($latency_metrics) {
        $total_requests = count($latency_metrics);
        $avg_latency = 0;
        $min_latency = PHP_INT_MAX;
        $max_latency = 0;
        
        if ($total_requests > 0) {
            $total = 0;
            foreach ($latency_metrics as $metric) {
                $value = $metric['value'];
                $total += $value;
                $min_latency = min($min_latency, $value);
                $max_latency = max($max_latency, $value);
            }
            $avg_latency = $total / $total_requests;
        }
        
        return compact('total_requests', 'avg_latency', 'min_latency', 'max_latency');
    }
    
    private function render_upgrade_notice() {
        if (!$this->is_pro): ?>
            <div class="notice notice-info">
                <p>
                    <strong>Unlock Pro Features!</strong> 
                    Get multiple exporters, batching, compression, and more.
                    <a href="#license" class="button button-primary">Enter License Key</a>
                </p>
            </div>
        <?php endif;
    }
    
    private function render_exporters_tab() {
        // This would be implemented for Pro users
        ?>
        <div id="exporters" class="wpotel-tab-content" style="display:none;">
            <h2>Configure Exporters</h2>
            <p>Pro feature: Multiple exporter configuration would be here.</p>
        </div>
        <?php
    }
    
    private function render_performance_tab($stats) {
        ?>
        <div id="performance" class="wpotel-tab-content" style="display:none;">
            <h2>Performance Dashboard</h2>
            
            <div class="card">
                <h3>Real-time Metrics</h3>
                <div class="wpotel-metrics-grid">
                    <div class="wpotel-metric">
                        <span class="wpotel-metric-label">Total Requests Traced</span>
                        <span class="wpotel-metric-value"><?php echo number_format($stats['total_requests']); ?></span>
                    </div>
                    <div class="wpotel-metric">
                        <span class="wpotel-metric-label">Average Latency</span>
                        <span class="wpotel-metric-value <?php echo $stats['avg_latency'] > 500 ? 'warning' : ''; ?>">
                            <?php echo number_format($stats['avg_latency'], 2); ?> ms
                        </span>
                    </div>
                    <div class="wpotel-metric">
                        <span class="wpotel-metric-label">Min Latency</span>
                        <span class="wpotel-metric-value"><?php echo number_format($stats['min_latency'] == PHP_INT_MAX ? 0 : $stats['min_latency'], 2); ?> ms</span>
                    </div>
                    <div class="wpotel-metric">
                        <span class="wpotel-metric-label">Max Latency</span>
                        <span class="wpotel-metric-value <?php echo $stats['max_latency'] > 1000 ? 'error' : ''; ?>">
                            <?php echo number_format($stats['max_latency'], 2); ?> ms
                        </span>
                    </div>
                </div>
            </div>
            
            <div class="card" style="margin-top: 20px;">
                <h3>Export Statistics</h3>
                <p><strong>Batch Size:</strong> <?php echo get_option('wpotel_batch_size', 50); ?> traces</p>
                <p><strong>Export Interval:</strong> Every <?php echo get_option('wpotel_export_interval', 5); ?> seconds</p>
                <p><strong>Compression:</strong> <?php echo get_option('wpotel_compression', true) ? 'Enabled' : 'Disabled'; ?></p>
                <p><strong>Adaptive Sampling:</strong> <?php echo get_option('wpotel_adaptive_sampling', true) ? 'Enabled' : 'Disabled'; ?></p>
            </div>
        </div>
        <?php
    }
    
    private function render_license_tab() {
        ?>
        <div id="license" class="wpotel-tab-content" style="display:none;">
            <h2>License Management</h2>
            
            <table class="form-table">
                <tr>
                    <th scope="row">License Key</th>
                    <td>
                        <input type="text" id="wpotel_license_key" 
                               value="<?php echo esc_attr(get_option('wpotel_license_key', '')); ?>" 
                               class="regular-text" />
                        <?php if ($this->is_pro): ?>
                            <span style="color: green;">✓ Active</span>
                            <p class="description">License expires: <?php echo date('F j, Y', strtotime(get_option('wpotel_license_expires', '+1 year'))); ?></p>
                        <?php else: ?>
                            <button type="button" id="activate-license" class="button">
                                Activate License
                            </button>
                            <span id="license-result"></span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            
            <?php if (!$this->is_pro): ?>
            <div class="card" style="margin-top: 20px;">
                <h3>Pro Features</h3>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th>Feature</th>
                            <th>Free</th>
                            <th>Pro</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Basic Tracing</td>
                            <td style="color: green;">✓</td>
                            <td style="color: green;">✓</td>
                        </tr>
                        <tr>
                            <td>Database Query Tracing</td>
                            <td style="color: green;">✓</td>
                            <td style="color: green;">✓</td>
                        </tr>
                        <tr>
                            <td>Multiple Exporters</td>
                            <td style="color: red;">✗</td>
                            <td style="color: green;">✓</td>
                        </tr>
                        <tr>
                            <td>Trace Batching</td>
                            <td style="color: red;">✗</td>
                            <td style="color: green;">✓</td>
                        </tr>
                        <tr>
                            <td>Data Compression</td>
                            <td style="color: red;">✗</td>
                            <td style="color: green;">✓</td>
                        </tr>
                        <tr>
                            <td>Adaptive Sampling</td>
                            <td style="color: red;">✗</td>
                            <td style="color: green;">✓</td>
                        </tr>
                        <tr>
                            <td>Priority Support</td>
                            <td style="color: red;">✗</td>
                            <td style="color: green;">✓</td>
                        </tr>
                    </tbody>
                </table>
                <p style="margin-top: 20px;">
                    <a href="https://yoursite.com/pricing" target="_blank" class="button button-primary">
                        Get Pro License - $29/month
                    </a>
                </p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function render_performance_summary($stats) {
        ?>
        <div class="card" style="margin-top: 20px;">
            <h2>Request Performance Summary</h2>
            <p class="description">Total metrics stored: <?php echo $stats['total_requests']; ?></p>
            <p><strong>Total Requests Traced:</strong> <?php echo number_format($stats['total_requests']); ?></p>
            <p><strong>Average Latency:</strong> <?php echo number_format($stats['avg_latency'], 2); ?> ms</p>
            <p><strong>Min Latency:</strong> <?php echo number_format($stats['min_latency'] == PHP_INT_MAX ? 0 : $stats['min_latency'], 2); ?> ms</p>
            <p><strong>Max Latency:</strong> <?php echo number_format($stats['max_latency'], 2); ?> ms</p>
            
            <?php if ($stats['total_requests'] > 0): ?>
                <canvas id="latencyChartMain" width="400" height="200" style="margin-top: 20px;"></canvas>
            <?php else: ?>
                <p class="description">No metrics collected yet. Generate some traffic to see metrics.</p>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function render_quick_start_guide() {
        ?>
        <div class="card" style="margin-top: 20px; background-color: #f0f8ff; border-left: 4px solid #0073aa;">
            <h2>Quick Start Guide</h2>
            <ol>
                <li><strong>Install Jaeger:</strong> <code>docker run -d --name jaeger -e COLLECTOR_ZIPKIN_HOST_PORT=:9411 -p 16686:16686 -p 14268:14268 -p 9411:9411 jaegertracing/all-in-one</code></li>
                <li><strong>Configure Endpoint:</strong> Set the endpoint to:
                    <ul>
                        <li>For Local by Flywheel: <code>http://192.168.0.165:9411/api/v2/spans</code> (use your machine's IP)</li>
                        <li>For standard Docker: <code>http://localhost:9411/api/v2/spans</code></li>
                    </ul>
                </li>
                <li><strong>Set Sampling Rate:</strong> Start with 0.1 (10%) for production sites</li>
                <li><strong>Enable Database Tracing:</strong> Check "Trace Database Queries" to see which plugins are causing slow queries</li>
                <li><strong>View Traces:</strong> Open Jaeger UI at <code>http://localhost:16686</code></li>
            </ol>
        </div>
        <?php
    }
    
    private function render_debug_info() {
        if ($this->config['debug_mode']): ?>
            <div class="card" style="margin-top: 20px; background-color: #fff3cd; border-left: 4px solid #ffc107;">
                <h2>Debug Information</h2>
                <p><strong>Debug Mode:</strong> Enabled</p>
                <p><strong>Log to File:</strong> <?php echo $this->config['log_to_file'] ? 'Enabled' : 'Disabled'; ?></p>
                <p><strong>Database Tracing:</strong> <?php echo $this->config['trace_database'] ? 'Enabled' : 'Disabled'; ?></p>
                <p><strong>PHP Error Log:</strong> <code><?php echo esc_html(ini_get('error_log')); ?></code></p>
                <p><strong>Current Settings:</strong></p>
                <pre><?php echo esc_html(json_encode($this->config, JSON_PRETTY_PRINT)); ?></pre>
                
                <?php if ($this->core_plugin->get_current_trace()): ?>
                <p><strong>Current Trace ID:</strong> <?php echo esc_html($this->core_plugin->get_current_trace()['traceID'] ?? 'None'); ?></p>
                <?php endif; ?>
            </div>
        <?php endif;
    }
    
    private function render_charts($latency_metrics) {
        if (!empty($latency_metrics)): ?>
            <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Main chart
                var ctx = document.getElementById('latencyChartMain')?.getContext('2d');
                if (ctx) {
                    var data = <?php echo json_encode(array_map(function($metric) {
                        return [
                            'x' => date('H:i:s', $metric['timestamp']),
                            'y' => $metric['value']
                        ];
                    }, array_slice($latency_metrics, -50))); ?>;
                    
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            datasets: [{
                                label: 'Request Latency (ms)',
                                data: data,
                                borderColor: 'rgb(75, 192, 192)',
                                backgroundColor: 'rgba(75, 192, 192, 0.1)',
                                tension: 0.1,
                                fill: true
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                title: {
                                    display: true,
                                    text: 'Last 50 Requests Performance'
                                },
                                legend: {
                                    display: true
                                }
                            },
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
                            },
                            interaction: {
                                intersect: false,
                                mode: 'index'
                            }
                        }
                    });
                }
            });
            </script>
        <?php endif;
    }
}
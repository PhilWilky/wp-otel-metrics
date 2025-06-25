<?php
/**
 * Test Page Renderer for WP OpenTelemetry
 */

namespace WPOtel;

class TestPageRenderer {
    
    private $core_plugin;
    private $is_pro;
    
    public function __construct($core_plugin, $is_pro = false) {
        $this->core_plugin = $core_plugin;
        $this->is_pro = $is_pro;
    }
    
    public function render() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        ?>
        <div class="wrap">
            <h1>
                🧪 OpenTelemetry Plugin Tests
                <?php if ($this->is_pro): ?>
                    <span class="wpotel-pro-badge">PRO</span>
                <?php endif; ?>
            </h1>
            
            <div class="notice notice-info">
                <p><strong>Comprehensive Plugin Testing</strong> - This page tests all aspects of your OpenTelemetry plugin.</p>
            </div>
            
            <?php if (isset($_GET['run_tests']) && $_GET['run_tests'] === '1'): ?>
                <?php $this->render_test_results(); ?>
            <?php else: ?>
                <?php $this->render_test_intro(); ?>
            <?php endif; ?>
            
            <?php $this->render_test_styles(); ?>
        </div>
        <?php
    }
    
    private function render_test_results() {
        ?>
        <div id="test-results">
            <h2>🔬 Test Results</h2>
            
            <?php
            $tester = new \WPOtel\PluginTester($this->core_plugin);
            $summary = $tester->run_all_tests();
            ?>
            
            <div class="test-summary">
                <div class="test-stats">
                    <span class="stat total">Total Tests: <?php echo $summary['total']; ?></span>
                    <span class="stat passed">Passed: <?php echo $summary['passed']; ?></span>
                    <span class="stat failed">Failed: <?php echo $summary['failed']; ?></span>
                    <span class="stat percentage">
                        Success Rate: <?php echo $summary['total'] > 0 ? round(($summary['passed'] / $summary['total']) * 100, 1) : 0; ?>%
                    </span>
                </div>
            </div>
            
            <div class="test-details">
                <?php foreach ($summary['results'] as $result): ?>
                    <?php if ($result['status'] === 'section'): ?>
                        <h3 class="test-section">📋 <?php echo esc_html($result['message']); ?></h3>
                    <?php else: ?>
                        <div class="test-result test-<?php echo esc_attr($result['status']); ?>">
                            <span class="test-status">
                                <?php echo $result['status'] === 'pass' ? '✅' : '❌'; ?>
                            </span>
                            <span class="test-message"><?php echo esc_html($result['message']); ?></span>
                            <?php if (!empty($result['details'])): ?>
                                <div class="test-details-text">
                                    <code><?php echo esc_html($result['details']); ?></code>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            
            <div class="test-actions">
                <a href="<?php echo admin_url('admin.php?page=wp-otel-metrics-tests'); ?>" class="button">
                    🔄 Run Tests Again
                </a>
                <a href="<?php echo admin_url('admin.php?page=wp-otel-metrics'); ?>" class="button button-primary">
                    ⚙️ Back to Settings
                </a>
            </div>
        </div>
        <?php
    }
    
    private function render_test_intro() {
        ?>
        <div class="test-intro">
            <h2>🎯 What Will Be Tested</h2>
            
            <div class="test-categories">
                <div class="test-category">
                    <h3>🔧 Core Functionality</h3>
                    <ul>
                        <li>Plugin initialization and class loading</li>
                        <li>ID generation (trace IDs, span IDs)</li>
                        <li>Method availability and accessibility</li>
                        <li>Configuration management</li>
                    </ul>
                </div>
                
                <div class="test-category">
                    <h3>🔗 WordPress Integration</h3>
                    <ul>
                        <li>WordPress function availability</li>
                        <li>Hook registration and execution</li>
                        <li>Constants and environment detection</li>
                        <li>Database option storage</li>
                    </ul>
                </div>
                
                <div class="test-category">
                    <h3>📊 OpenTelemetry Features</h3>
                    <ul>
                        <li>Configuration loading and validation</li>
                        <li>Trace creation and management</li>
                        <li>Sampling rate functionality</li>
                        <li>Export endpoint connectivity</li>
                        <li>Real trace export to monitoring backend</li>
                    </ul>
                </div>
                
                <div class="test-category">
                    <h3>🗄️ Database Tracing</h3>
                    <ul>
                        <li>Database query interception</li>
                        <li>Plugin/theme source identification</li>
                        <li>Query performance measurement</li>
                        <li>Span creation for database operations</li>
                    </ul>
                </div>
                
                <div class="test-category">
                    <h3>⭐ Pro Features</h3>
                    <ul>
                        <li>License manager functionality</li>
                        <li>Batch manager operations</li>
                        <li>Multiple exporter support</li>
                        <li>Advanced configuration options</li>
                    </ul>
                </div>
                
                <div class="test-category">
                    <h3>🎛️ Admin Interface</h3>
                    <ul>
                        <li>Admin menu registration</li>
                        <li>Settings page functionality</li>
                        <li>User permission validation</li>
                        <li>AJAX handler testing</li>
                    </ul>
                </div>
            </div>
            
            <div class="run-tests-section">
                <a href="<?php echo admin_url('admin.php?page=wp-otel-metrics-tests&run_tests=1'); ?>" 
                   class="button button-primary button-hero">
                    🚀 Run Comprehensive Tests
                </a>
                <p class="description">
                    This will test all plugin functionality and generate a detailed report.
                    <strong>Note:</strong> This will create real traces that will be exported to your monitoring backend.
                </p>
            </div>
        </div>
        <?php
    }
    
    private function render_test_styles() {
        ?>
        <style>
        .test-summary {
            background: #f1f1f1;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        
        .test-stats .stat {
            display: inline-block;
            margin-right: 20px;
            padding: 5px 10px;
            border-radius: 3px;
            font-weight: bold;
        }
        
        .stat.total { background: #ddd; }
        .stat.passed { background: #d4edda; color: #155724; }
        .stat.failed { background: #f8d7da; color: #721c24; }
        .stat.percentage { background: #d1ecf1; color: #0c5460; }
        
        .test-section {
            margin-top: 30px;
            padding: 10px 0;
            border-bottom: 2px solid #0073aa;
            color: #0073aa;
        }
        
        .test-result {
            display: flex;
            align-items: flex-start;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        
        .test-status {
            margin-right: 10px;
            font-size: 16px;
        }
        
        .test-message {
            flex: 1;
            font-weight: 500;
        }
        
        .test-details-text {
            margin-left: 30px;
            margin-top: 5px;
        }
        
        .test-details-text code {
            background: #f5f5f5;
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 12px;
            white-space: pre-wrap;
        }
        
        .test-categories {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        
        .test-category {
            background: white;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        
        .test-category h3 {
            margin-top: 0;
            color: #0073aa;
        }
        
        .test-category ul {
            margin-bottom: 0;
        }
        
        .run-tests-section {
            text-align: center;
            margin: 40px 0;
            padding: 30px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        
        .test-actions {
            margin-top: 30px;
            text-align: center;
        }
        
        .test-actions .button {
            margin: 0 10px;
        }
        </style>
        <?php
    }
}
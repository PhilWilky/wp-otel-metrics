<?php
/**
 * Plugin Name: Database Trace Tester
 * Description: Simple plugin to test database tracing functionality
 * Version: 1.0.0
 * Author: Test Author
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class DatabaseTraceTestPlugin {
    
    public function __construct() {
        add_action('init', [$this, 'init']);
        add_action('wp_footer', [$this, 'add_test_button']);
        add_action('wp_ajax_test_db_queries', [$this, 'run_test_queries']);
        add_action('wp_ajax_nopriv_test_db_queries', [$this, 'run_test_queries']);
        add_action('admin_bar_menu', [$this, 'add_admin_bar_button'], 100);
    }
    
    public function init() {
        // Add some test actions that will trigger database queries
        add_action('wp_loaded', [$this, 'schedule_test_queries']);
    }
    
    public function schedule_test_queries() {
        // Only run on specific requests to avoid constant DB hits
        if (isset($_GET['test_db_traces'])) {
            $this->run_comprehensive_db_tests();
        }
    }
    
    public function run_comprehensive_db_tests() {
        global $wpdb;
        
        error_log('[DB Trace Test] Starting comprehensive database tests...');
        
        // Test 1: Simple SELECT query
        $posts = $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE post_status = 'publish' LIMIT 5");
        error_log('[DB Trace Test] Test 1: Selected ' . count($posts) . ' posts');
        
        // Test 2: Count query
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post'");
        error_log('[DB Trace Test] Test 2: Post count: ' . $count);
        
        // Test 3: Meta query
        $meta = $wpdb->get_results("SELECT meta_key, COUNT(*) as count FROM {$wpdb->postmeta} GROUP BY meta_key LIMIT 10");
        error_log('[DB Trace Test] Test 3: Found ' . count($meta) . ' meta keys');
        
        // Test 4: User query
        $users = $wpdb->get_results("SELECT ID, user_login FROM {$wpdb->users} LIMIT 3");
        error_log('[DB Trace Test] Test 4: Found ' . count($users) . ' users');
        
        // Test 5: Options query
        $options = $wpdb->get_results("SELECT option_name FROM {$wpdb->options} WHERE autoload = 'yes' LIMIT 10");
        error_log('[DB Trace Test] Test 5: Found ' . count($options) . ' autoload options');
        
        // Test 6: Complex JOIN query
        $complex = $wpdb->get_results("
            SELECT p.ID, p.post_title, u.user_login 
            FROM {$wpdb->posts} p 
            LEFT JOIN {$wpdb->users} u ON p.post_author = u.ID 
            WHERE p.post_status = 'publish' 
            LIMIT 5
        ");
        error_log('[DB Trace Test] Test 6: Complex query returned ' . count($complex) . ' results');
        
        // Test 7: Slow query simulation (be careful with this)
        $slow = $wpdb->get_results("
            SELECT p1.ID, p1.post_title,
                   (SELECT COUNT(*) FROM {$wpdb->posts} p2 WHERE p2.post_author = p1.post_author) as author_post_count
            FROM {$wpdb->posts} p1 
            WHERE p1.post_status = 'publish' 
            LIMIT 3
        ");
        error_log('[DB Trace Test] Test 7: Slow query simulation returned ' . count($slow) . ' results');
        
        error_log('[DB Trace Test] All database tests completed!');
        
        // Display results
        echo '<div style="position: fixed; top: 100px; right: 20px; background: #fff; padding: 20px; border: 2px solid #0073aa; z-index: 9999; max-width: 300px;">';
        echo '<h3>Database Trace Tests Completed!</h3>';
        echo '<p>Ran 7 different database queries. Check your:</p>';
        echo '<ul>';
        echo '<li>PHP error log for test output</li>';
        echo '<li>Zipkin UI at <a href="http://localhost:16686" target="_blank">localhost:16686</a></li>';
        echo '<li>WordPress admin for trace data</li>';
        echo '</ul>';
        echo '<button onclick="this.parentElement.style.display=\'none\'">Close</button>';
        echo '</div>';
    }
    
    public function add_admin_bar_button($wp_admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $wp_admin_bar->add_node([
            'id' => 'test-db-traces',
            'title' => '🔍 Test DB Traces',
            'href' => add_query_arg('test_db_traces', '1'),
            'meta' => [
                'title' => 'Run database tracing tests'
            ]
        ]);
    }
    
    public function add_test_button() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        ?>
        <script>
        function testDatabaseTraces() {
            // Add a visual indicator
            document.body.insertAdjacentHTML('beforeend', 
                '<div id="db-test-loading" style="position: fixed; top: 50px; right: 20px; background: #0073aa; color: white; padding: 10px; z-index: 9999; border-radius: 5px;">Running DB Tests...</div>'
            );
            
            // Reload the page with test parameter
            window.location.href = window.location.href + (window.location.href.indexOf('?') > -1 ? '&' : '?') + 'test_db_traces=1';
        }
        </script>
        
        <div style="position: fixed; bottom: 20px; right: 20px; z-index: 9999;">
            <button onclick="testDatabaseTraces()" 
                    style="background: #0073aa; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-size: 14px;">
                🔍 Test DB Traces
            </button>
        </div>
        <?php
    }
    
    public function run_test_queries() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        global $wpdb;
        
        $results = [];
        
        // Run a series of test queries
        $queries = [
            'Posts' => "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_status = 'publish' LIMIT 5",
            'Users' => "SELECT ID, user_login FROM {$wpdb->users} LIMIT 3",
            'Options' => "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'wpotel_%'",
            'Comments' => "SELECT comment_ID, comment_content FROM {$wpdb->comments} WHERE comment_approved = '1' LIMIT 3"
        ];
        
        foreach ($queries as $name => $query) {
            $start = microtime(true);
            $result = $wpdb->get_results($query);
            $duration = (microtime(true) - $start) * 1000;
            
            $results[$name] = [
                'count' => count($result),
                'duration' => round($duration, 2) . 'ms',
                'query' => $query
            ];
        }
        
        wp_send_json_success([
            'message' => 'Database trace tests completed',
            'results' => $results,
            'total_queries' => count($wpdb->queries ?? []),
            'trace_info' => 'Check your monitoring backend for traces'
        ]);
    }
}

// Initialize the test plugin
new DatabaseTraceTestPlugin();
<?php
/**
 * Database Tracer for WP OpenTelemetry
 * Tracks all database queries with plugin/theme identification
 */

namespace WPOtel;

class DatabaseTracer {
    
    private $core_plugin;
    private $config;
    private static $plugin_cache = [];
    private static $last_query_count = 0;
    private static $processed_queries = [];
    
    public function __construct($core_plugin) {
        $this->core_plugin = $core_plugin;
        $this->config = $core_plugin->get_config();
    }
    
    public function init() {
        if (!$this->config['trace_database']) {
            return;
        }
        
        // Enable query logging immediately
        if (!defined('SAVEQUERIES')) {
            define('SAVEQUERIES', true);
        }
        
        // Hook BEFORE shutdown to catch queries early
        add_action('wp_loaded', [$this, 'setup_query_tracking'], 1);
        add_action('shutdown', [$this, 'process_all_queries'], 1); // Early in shutdown
        
        // Process queries at multiple points
        add_action('wp_footer', [$this, 'process_all_queries'], 1);
        add_action('admin_footer', [$this, 'process_all_queries'], 1);
        
        if ($this->config['debug_mode']) {
            $this->core_plugin->log('Database tracer initialized');
        }
    }
    
    public function setup_query_tracking() {
        global $wpdb;
        
        if (!$wpdb) {
            return;
        }
        
        // Hook into wpdb query filter to catch queries as they happen
        add_filter('query', [$this, 'intercept_query'], 10);
        
        if ($this->config['debug_mode']) {
            $this->core_plugin->log('Query tracking setup with filter hook');
        }
    }
    
    public function intercept_query($query) {
        // Get current trace
        $current_trace = $this->core_plugin->get_current_trace();
        if (!$current_trace) {
            return $query; // No active trace
        }
        
        // Skip our own plugin's queries
        if (strpos($query, 'wpotel_') !== false) {
            return $query;
        }
        
        // Create span immediately when query is executed
        $start_time = microtime(true);
        
        // Get better backtrace since we're in the query execution
        $source = $this->get_query_source_deep();
        
        // Estimate execution time (we'll use a small default)
        $estimated_time = 1.0; // 1ms default
        
        $this->create_db_span_immediate($query, $estimated_time, $source);
        
        return $query;
    }
    
    public function process_all_queries() {
        global $wpdb;
        
        if (!$this->core_plugin->get_current_trace()) {
            return;
        }
        
        if (!isset($wpdb->queries) || empty($wpdb->queries)) {
            return;
        }
        
        $current_query_count = count($wpdb->queries);
        
        if ($current_query_count <= self::$last_query_count) {
            return;
        }
        
        // Process new queries with actual timing data
        $new_queries = array_slice($wpdb->queries, self::$last_query_count);
        
        if ($this->config['debug_mode']) {
            $this->core_plugin->log(sprintf(
                'Processing %d new queries with timing data',
                count($new_queries)
            ));
        }
        
        // Update spans with real timing data
        foreach ($new_queries as $index => $query_data) {
            $this->update_span_with_timing($query_data, self::$last_query_count + $index);
        }
        
        self::$last_query_count = $current_query_count;
    }
    
    private function create_db_span_immediate($query, $execution_time_ms, $source) {
        $current_trace = $this->core_plugin->get_current_trace();
        if (!$current_trace) {
            return;
        }
        
        $span_id = $this->core_plugin->generate_id(16);
        
        // Create the span data
        $span_data = [
            'traceID' => $current_trace['traceID'],
            'spanID' => $span_id,
            'parentSpanID' => $current_trace['spans'][0]['spanID'],
            'operationName' => 'mysql.query',
            'startTime' => microtime(true) * 1000000,
            'duration' => $execution_time_ms * 1000, // Convert to microseconds
            'tags' => [
                ['key' => 'db.statement', 'value' => substr($query, 0, 100)],
                ['key' => 'db.duration_ms', 'value' => round($execution_time_ms, 2)],
                ['key' => 'db.type', 'value' => 'mysql'],
                ['key' => 'db.operation', 'value' => $this->get_sql_operation($query)],
                
                // Plugin/Theme identification
                ['key' => 'source.plugin', 'value' => $source['plugin']],
                ['key' => 'source.theme', 'value' => $source['theme']],
                ['key' => 'source.file', 'value' => $source['file']],
                ['key' => 'source.function', 'value' => $source['function']],
                ['key' => 'source.line', 'value' => $source['line']],
                ['key' => 'source.hook', 'value' => $source['hook']],
            ]
        ];
        
        // Add the span to the current trace
        $success = $this->core_plugin->add_span_to_current_trace($span_data);
        
        // Store for later timing update
        self::$processed_queries[$span_id] = [
            'query' => $query,
            'span_data' => $span_data
        ];
        
        if ($this->config['debug_mode']) {
            $this->core_plugin->log(sprintf(
                'DB Span created: %s (%.2fms) - Plugin: %s, Function: %s',
                substr($query, 0, 30),
                $execution_time_ms,
                $source['plugin'],
                $source['function']
            ));
        }
    }
    
    private function update_span_with_timing($query_data, $query_index) {
        if (!is_array($query_data) || count($query_data) < 2) {
            return;
        }
        
        $sql = $query_data[0];
        $actual_time = $query_data[1] * 1000; // Convert to milliseconds
        
        // Find matching span by query content
        foreach (self::$processed_queries as $span_id => $stored_data) {
            if ($stored_data['query'] === $sql) {
                // Update the span in the current trace with actual timing
                $current_trace = $this->core_plugin->get_current_trace();
                if ($current_trace && isset($current_trace['spans'])) {
                    foreach ($current_trace['spans'] as &$span) {
                        if ($span['spanID'] === $span_id) {
                            $span['duration'] = $actual_time * 1000; // Convert to microseconds
                            // Update the duration tag too
                            foreach ($span['tags'] as &$tag) {
                                if ($tag['key'] === 'db.duration_ms') {
                                    $tag['value'] = round($actual_time, 2);
                                    break;
                                }
                            }
                            break;
                        }
                    }
                }
                
                // Remove from processed list
                unset(self::$processed_queries[$span_id]);
                break;
            }
        }
    }
    
    private function get_sql_operation($query) {
        $query = trim(strtoupper($query));
        if (strpos($query, 'SELECT') === 0) return 'SELECT';
        if (strpos($query, 'INSERT') === 0) return 'INSERT';
        if (strpos($query, 'UPDATE') === 0) return 'UPDATE';
        if (strpos($query, 'DELETE') === 0) return 'DELETE';
        if (strpos($query, 'CREATE') === 0) return 'CREATE';
        if (strpos($query, 'ALTER') === 0) return 'ALTER';
        if (strpos($query, 'DROP') === 0) return 'DROP';
        return 'UNKNOWN';
    }
    
    private function get_query_source_deep() {
        $source = [
            'plugin' => 'wordpress-core',
            'theme' => 'none', 
            'file' => 'wp-core',
            'function' => 'unknown',
            'line' => 0,
            'hook' => $this->get_current_hook()
        ];
        
        // Get a MUCH deeper backtrace to find the real caller
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50); // Go deeper!
        
        // WordPress core files to skip (these are just the intermediaries)
        $wp_core_patterns = [
            'wp-db.php', 'class-wpdb.php', 'class-wp-query.php', 'class-wp-hook.php',
            'plugin.php', 'class-wp-', 'wp-includes/', 'wp-admin/', 'taxonomy.php',
            'post.php', 'meta.php', 'option.php', 'comment.php', 'update.php',
            'general-template.php', 'blocks.php', 'block-template', 'capabilities.php',
            'wp-otel-metrics' // Skip our own plugin
        ];
        
        $found_sources = [];
        
        // Analyze the ENTIRE backtrace to find ALL non-core files
        foreach ($backtrace as $i => $trace) {
            if (!isset($trace['file'])) continue;
            
            $file = $trace['file'];
            $is_wp_core = false;
            
            // Skip WordPress core files
            foreach ($wp_core_patterns as $pattern) {
                if (strpos($file, $pattern) !== false) {
                    $is_wp_core = true;
                    break;
                }
            }
            
            if ($is_wp_core) continue;
            
            // Found a non-core file! Check what type it is
            $file_source = $this->identify_file_source($file, $trace);
            if ($file_source['plugin'] !== 'unknown' || $file_source['theme'] !== 'none') {
                $found_sources[] = $file_source;
            }
        }
        
        // Use the first non-core source we found
        if (!empty($found_sources)) {
            $source = $found_sources[0];
        } else {
            // If we didn't find any non-core files, try to identify from the hook
            $hook = $this->get_current_hook();
            if ($hook && $hook !== 'unknown') {
                $source['function'] = $hook;
                
                // Try to identify plugin from hook name
                if (strpos($hook, '_') !== false) {
                    $hook_parts = explode('_', $hook);
                    $potential_plugin = $hook_parts[0];
                    
                    // Common plugin prefixes
                    $known_plugins = [
                        'wc' => 'WooCommerce',
                        'yoast' => 'Yoast SEO', 
                        'jetpack' => 'Jetpack',
                        'elementor' => 'Elementor',
                        'wpcf7' => 'Contact Form 7',
                        'acf' => 'Advanced Custom Fields'
                    ];
                    
                    if (isset($known_plugins[$potential_plugin])) {
                        $source['plugin'] = $known_plugins[$potential_plugin];
                    }
                }
            }
        }
        
        if ($this->config['debug_mode']) {
            $this->core_plugin->log(sprintf(
                'Source identified: Plugin=%s, Theme=%s, Function=%s, Hook=%s',
                $source['plugin'],
                $source['theme'], 
                $source['function'],
                $source['hook']
            ));
        }
        
        return $source;
    }
    
    private function identify_file_source($file, $trace) {
        $source = [
            'plugin' => 'unknown',
            'theme' => 'none',
            'file' => basename($file),
            'function' => $trace['function'] ?? 'unknown',
            'line' => $trace['line'] ?? 0,
            'hook' => $this->get_current_hook()
        ];
        
        // Check if it's a plugin
        if (strpos($file, WP_PLUGIN_DIR) !== false) {
            $plugin_path = str_replace(WP_PLUGIN_DIR . '/', '', $file);
            $plugin_folder = explode('/', $plugin_path)[0];
            $source['plugin'] = $this->get_plugin_name($plugin_folder);
            return $source;
        }
        
        // Check if it's a theme
        if ((defined('STYLESHEETPATH') && strpos($file, STYLESHEETPATH) !== false) ||
            (defined('TEMPLATEPATH') && strpos($file, TEMPLATEPATH) !== false) ||
            (strpos($file, '/themes/') !== false)) {
            
            $source['theme'] = get_template();
            $source['plugin'] = 'theme';
            return $source;
        }
        
        // Check if it's a Must-Use plugin
        if (defined('WPMU_PLUGIN_DIR') && strpos($file, WPMU_PLUGIN_DIR) !== false) {
            $source['plugin'] = 'mu-plugin';
            return $source;
        }
        
        return $source;
    }
    
    private function get_plugin_name($plugin_folder) {
        if (isset(self::$plugin_cache[$plugin_folder])) {
            return self::$plugin_cache[$plugin_folder];
        }
        
        // Try to get the actual plugin name
        $plugin_file = WP_PLUGIN_DIR . '/' . $plugin_folder . '/' . $plugin_folder . '.php';
        if (!file_exists($plugin_file)) {
            // Look for main plugin file
            $files = glob(WP_PLUGIN_DIR . '/' . $plugin_folder . '/*.php');
            if ($files) {
                foreach ($files as $file) {
                    $content = file_get_contents($file, false, null, 0, 8192);
                    if (strpos($content, 'Plugin Name:') !== false) {
                        $plugin_file = $file;
                        break;
                    }
                }
            }
        }
        
        if (file_exists($plugin_file)) {
            $plugin_data = get_file_data($plugin_file, ['Name' => 'Plugin Name']);
            $plugin_name = $plugin_data['Name'] ?: $plugin_folder;
        } else {
            $plugin_name = $plugin_folder;
        }
        
        self::$plugin_cache[$plugin_folder] = $plugin_name;
        return $plugin_name;
    }
    
    private function get_current_hook() {
        global $wp_current_filter;
        return end($wp_current_filter) ?: 'unknown';
    }
}
<?php
// WordPress Site Health API Features

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Log debug messages to WordPress error log
 */
function wp_site_health_feature_api_debug_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG === true) {
        error_log('WP Site Health Feature API: ' . $message);
    }
}

/**
 * Register WordPress Site Health features with the Feature API.
 */
function wp_feature_api_wp_site_health_register_features() {
    wp_site_health_feature_api_debug_log('Registering WordPress Site Health features');
    
    // --- Feature 1: Get Page Cache Status (Resource) ---
    wp_register_feature(
        array(
            'id'          => 'wp/site-health/get-page-cache-status',
            'name'        => __( 'Get Page Cache Status', 'wp-react-agent' ),
            'description' => __( 'Checks if site has page cache enabled and returns cache status details.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_RESOURCE,
            'categories'  => array( 'wp', 'site-health', 'cache' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(),
            ),
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'callback'    => function( $request ) {
                // Ensure the Site Health class is available
                if (!class_exists('WP_Site_Health')) {
                    require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
                }
                
                // Get the Site Health instance
                $site_health = WP_Site_Health::get_instance();
                
                // Get page cache details (using reflection to access private method)
                try {
                    $reflection = new ReflectionMethod($site_health, 'get_page_cache_detail');
                    $reflection->setAccessible(true);
                    $page_cache_detail = $reflection->invoke($site_health);
                    
                    if (is_wp_error($page_cache_detail)) {
                        return new WP_Error(
                            'page_cache_error',
                            $page_cache_detail->get_error_message(),
                            array('status' => 500)
                        );
                    }
                    
                    return array(
                        'status' => $page_cache_detail['status'],
                        'advanced_cache_present' => $page_cache_detail['advanced_cache_present'],
                        'headers' => $page_cache_detail['headers'],
                        'response_time' => $page_cache_detail['response_time'],
                    );
                } catch (Exception $e) {
                    // Fallback approach if reflection fails
                    // Create a custom implementation by checking for page caching
                    
                    // Check if advanced-cache.php is present and WP_CACHE is defined
                    $advanced_cache_present = (
                        file_exists(WP_CONTENT_DIR . '/advanced-cache.php')
                        &&
                        (defined('WP_CACHE') && WP_CACHE)
                    );
                    
                    return array(
                        'status' => $advanced_cache_present ? 'good' : 'recommended',
                        'advanced_cache_present' => $advanced_cache_present,
                        'headers' => array(),
                        'response_time' => 0,
                        'note' => __('Limited information is available due to API restrictions.', 'wp-react-agent'),
                    );
                }
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'status' => array('type' => 'string'),
                    'advanced_cache_present' => array('type' => 'boolean'),
                    'headers' => array('type' => 'array'),
                    'response_time' => array('type' => 'number'),
                ),
                'required' => array('status', 'advanced_cache_present'),
            ),
        )
    );

    // --- Feature 2: Check Object Cache Status (Resource) ---
    wp_register_feature(
        array(
            'id'          => 'wp/site-health/check-object-cache',
            'name'        => __( 'Check Object Cache Status', 'wp-react-agent' ),
            'description' => __( 'Checks if a persistent object cache is being used and if it should be recommended.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_RESOURCE,
            'categories'  => array( 'wp', 'site-health', 'object-cache' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(),
            ),
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'callback'    => function( $request ) {
                // Ensure the Site Health class is available
                if (!class_exists('WP_Site_Health')) {
                    require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
                }
                
                // Get the Site Health instance
                $site_health = WP_Site_Health::get_instance();
                
                // Check if persistent object cache is being used
                $using_object_cache = wp_using_ext_object_cache();
                
                // Get available object cache services
                $available_services = array();
                try {
                    $reflection = new ReflectionMethod($site_health, 'available_object_cache_services');
                    $reflection->setAccessible(true);
                    $available_services = $reflection->invoke($site_health);
                } catch (Exception $e) {
                    // Fallback to checking common extensions
                    $extensions = array(
                        'APCu'      => extension_loaded('apcu'),
                        'Redis'     => extension_loaded('redis'),
                        'Relay'     => extension_loaded('relay'),
                        'Memcache'  => extension_loaded('memcache'),
                        'Memcached' => extension_loaded('memcached'),
                    );
                    $available_services = array_keys(array_filter($extensions));
                }
                
                // Check if persistent object cache should be suggested
                $should_suggest = false;
                try {
                    $reflection = new ReflectionMethod($site_health, 'should_suggest_persistent_object_cache');
                    $reflection->setAccessible(true);
                    $should_suggest = $reflection->invoke($site_health);
                } catch (Exception $e) {
                    // Fallback to a simpler check - suggest for multisite
                    $should_suggest = is_multisite();
                }
                
                return array(
                    'using_persistent_object_cache' => $using_object_cache,
                    'should_use_persistent_object_cache' => $should_suggest,
                    'available_services' => $available_services,
                    'is_multisite' => is_multisite(),
                );
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'using_persistent_object_cache' => array('type' => 'boolean'),
                    'should_use_persistent_object_cache' => array('type' => 'boolean'),
                    'available_services' => array('type' => 'array'),
                    'is_multisite' => array('type' => 'boolean'),
                ),
                'required' => array('using_persistent_object_cache', 'should_use_persistent_object_cache'),
            ),
        )
    );

    // --- Feature 3: Run Site Health Status Tests (Resource) ---
    wp_register_feature(
        array(
            'id'          => 'wp/site-health/run-tests',
            'name'        => __( 'Run Site Health Tests', 'wp-react-agent' ),
            'description' => __( 'Runs selected site health tests and returns the results.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_RESOURCE,
            'categories'  => array( 'wp', 'site-health', 'tests' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'tests' => array(
                        'type' => 'array',
                        'description' => __( 'List of specific tests to run. Leave empty to run all tests.', 'wp-react-agent' ),
                        'items' => array(
                            'type' => 'string',
                            'enum' => array(
                                'authorization_header',
                                'background_updates',
                                'loopback_requests',
                                'http_requests',
                                'dotorg_communication',
                                'file_uploads',
                                'constant_autoload_options',
                                'update_available',
                            ),
                        ),
                    ),
                ),
            ),
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'callback'    => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $requested_tests = isset($context['tests']) ? (array) $context['tests'] : array();
                
                // Ensure the Site Health class is available
                if (!class_exists('WP_Site_Health')) {
                    require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
                }
                
                // Get the Site Health instance
                $site_health = WP_Site_Health::get_instance();
                
                // Available test methods
                $available_tests = array(
                    'authorization_header' => 'get_test_authorization_header',
                    'background_updates' => 'get_test_background_updates',
                    'loopback_requests' => 'can_perform_loopback',
                    'http_requests' => 'get_test_http_requests',
                    'dotorg_communication' => 'get_test_dotorg_communication',
                    'file_uploads' => 'get_test_file_uploads',
                    'constant_autoload_options' => 'get_test_autoloaded_options',
                    'update_available' => 'get_test_available_updates_disk_space',
                );
                
                // Filter requested tests to valid ones
                if (empty($requested_tests)) {
                    $tests_to_run = array_keys($available_tests);
                } else {
                    $tests_to_run = array_intersect($requested_tests, array_keys($available_tests));
                }
                
                $results = array();
                
                // Run each requested test
                foreach ($tests_to_run as $test_key) {
                    $method = $available_tests[$test_key];
                    
                    try {
                        if (method_exists($site_health, $method)) {
                            $reflection = new ReflectionMethod($site_health, $method);
                            $reflection->setAccessible(true);
                            $test_result = $reflection->invoke($site_health);
                            
                            // Handle WP_Error
                            if (is_wp_error($test_result)) {
                                $results[$test_key] = array(
                                    'status' => 'error',
                                    'message' => $test_result->get_error_message(),
                                );
                            } else {
                                $results[$test_key] = $test_result;
                            }
                        } else {
                            $results[$test_key] = array(
                                'status' => 'error',
                                'message' => __('Test method not found', 'wp-react-agent'),
                            );
                        }
                    } catch (Exception $e) {
                        $results[$test_key] = array(
                            'status' => 'error',
                            'message' => $e->getMessage(),
                        );
                    }
                }
                
                return array(
                    'results' => $results,
                    'tests_run' => count($results),
                );
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'results' => array('type' => 'object'),
                    'tests_run' => array('type' => 'integer'),
                ),
                'required' => array('results', 'tests_run'),
            ),
        )
    );

    // --- Feature 4: Get Autoloaded Options Size (Resource) ---
    wp_register_feature(
        array(
            'id'          => 'wp/site-health/get-autoloaded-options-size',
            'name'        => __( 'Get Autoloaded Options Size', 'wp-react-agent' ),
            'description' => __( 'Retrieves the size of autoloaded options in the WordPress database.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_RESOURCE,
            'categories'  => array( 'wp', 'site-health', 'options' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(),
            ),
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'callback'    => function( $request ) {
                // Ensure the Site Health class is available
                if (!class_exists('WP_Site_Health')) {
                    require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
                }
                
                // Get the Site Health instance
                $site_health = WP_Site_Health::get_instance();
                
                // Get autoloaded options size
                try {
                    $reflection = new ReflectionMethod($site_health, 'get_autoloaded_options_size');
                    $reflection->setAccessible(true);
                    $autoloaded_size = $reflection->invoke($site_health);
                    
                    global $wpdb;
                    $alloptions = wp_load_alloptions();
                    
                    return array(
                        'size_bytes' => $autoloaded_size,
                        'size_kb' => round($autoloaded_size / 1024, 2),
                        'count' => count($alloptions),
                        'threshold_good' => 900 * 1024, // 900KB threshold
                    );
                } catch (Exception $e) {
                    // Fallback implementation
                    global $wpdb;
                    
                    $alloptions = wp_load_alloptions();
                    $size = strlen(serialize($alloptions));
                    
                    return array(
                        'size_bytes' => $size,
                        'size_kb' => round($size / 1024, 2),
                        'count' => count($alloptions),
                        'threshold_good' => 900 * 1024, // 900KB threshold
                        'note' => __('Size calculated directly from wp_load_alloptions() result.', 'wp-react-agent'),
                    );
                }
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'size_bytes' => array('type' => 'integer'),
                    'size_kb' => array('type' => 'number'),
                    'count' => array('type' => 'integer'),
                    'threshold_good' => array('type' => 'integer'),
                ),
                'required' => array('size_bytes', 'size_kb', 'count'),
            ),
        )
    );

    // --- Feature 5: Get Response Time Threshold (Resource) ---
    wp_register_feature(
        array(
            'id'          => 'wp/site-health/get-response-time-threshold',
            'name'        => __( 'Get Response Time Threshold', 'wp-react-agent' ),
            'description' => __( 'Gets the threshold below which a response time is considered good.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_RESOURCE,
            'categories'  => array( 'wp', 'site-health', 'performance' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(),
            ),
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
            'callback'    => function( $request ) {
                // Ensure the Site Health class is available
                if (!class_exists('WP_Site_Health')) {
                    require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
                }
                
                // Get the Site Health instance
                $site_health = WP_Site_Health::get_instance();
                
                // Get good response time threshold
                try {
                    $reflection = new ReflectionMethod($site_health, 'get_good_response_time_threshold');
                    $reflection->setAccessible(true);
                    $threshold = $reflection->invoke($site_health);
                    
                    return array(
                        'threshold_ms' => $threshold,
                        'threshold_seconds' => $threshold / 1000,
                    );
                } catch (Exception $e) {
                    // Fallback to the default value of 600ms
                    $default_threshold = 600; // ms
                    
                    // Check if the filter is applied
                    $threshold = apply_filters('site_status_good_response_time_threshold', $default_threshold);
                    
                    return array(
                        'threshold_ms' => $threshold,
                        'threshold_seconds' => $threshold / 1000,
                        'note' => __('Using standard threshold value.', 'wp-react-agent'),
                    );
                }
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'threshold_ms' => array('type' => 'integer'),
                    'threshold_seconds' => array('type' => 'number'),
                ),
                'required' => array('threshold_ms', 'threshold_seconds'),
            ),
        )
    );
}

// Register with the centralized feature loader
if (function_exists('wp_react_agent_register_feature_set')) {
    wp_react_agent_register_feature_set(
        'wp-site-health',                            // Unique ID
        'WordPress Site Health Features',            // Label
        'wp_feature_api_wp_site_health_register_features', // Callback function
        array(
            'wp_register_feature',                   // Require Feature API
            'WP_Feature',                            // Require Feature API classes
            'wp_using_ext_object_cache',             // Require WordPress core functions
        )
    );
    wp_site_health_feature_api_debug_log('WordPress Site Health Features registered with loader');
} 
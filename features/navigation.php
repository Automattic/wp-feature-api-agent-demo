<?php
// File: wp-react-agent/features/navigation.php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Log debug messages to WordPress error log
 */
function navigation_feature_api_debug_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG === true) {
        error_log('Navigation Feature API: ' . $message);
    }
}

/**
 * Register Navigation features with the Feature API.
 */
function wp_feature_api_navigation_register_features() {
    // Dependency checks are now handled by the centralized loader
    navigation_feature_api_debug_log('Registering Navigation features');
    
    // --- Feature 1: Navigate To (Tool) ---
    wp_register_feature(
        array(
            'id'          => 'wp/navigate-to',
            'name'        => __( 'Navigate to URL or Admin Page', 'wp-react-agent' ),
            'description' => __( 'Provides a URL to navigate to a specific WordPress admin page or external URL.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_TOOL,
            'categories'  => array( 'wp', 'navigation', 'url' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'page' => array(
                        'type' => 'string',
                        'description' => __( 'Admin page to navigate to (e.g., "edit.php" for Posts, "upload.php" for Media).', 'wp-react-agent' ),
                    ),
                    'url' => array(
                        'type' => 'string',
                        'description' => __( 'Full URL to navigate to (internal or external).', 'wp-react-agent' ),
                    ),
                    'params' => array(
                        'type' => 'object',
                        'description' => __( 'URL parameters to append to the admin page URL.', 'wp-react-agent' ),
                    ),
                ),
            ),
            'permission_callback' => function() {
                return current_user_can( 'read' ); // Basic permission for navigation
            },
            'callback'    => function( $request ) {
                // IMPORTANT: This feature only generates navigation URLs, it does not actually
                // perform navigation in the browser. The agent will return the URL which can
                // then be shared with the user, who can click it to navigate manually.
                // This is intentional for security reasons and to maintain user control over navigation.
                
                $context = $request->get_param('context') ?? array();
                $page = isset( $context['page'] ) ? sanitize_text_field( $context['page'] ) : '';
                $url = isset( $context['url'] ) ? esc_url_raw( $context['url'] ) : '';
                $params = isset( $context['params'] ) && is_array( $context['params'] ) ? $context['params'] : array();

                if ( empty( $page ) && empty( $url ) ) {
                    return new WP_Error( 
                        'missing_navigation_target', 
                        __( 'Either "page" or "url" parameter must be provided.', 'wp-react-agent' ), 
                        array( 'status' => 400 ) 
                    );
                }

                // Validate admin page parameter before proceeding
                $admin_pages = array(
                    'index.php' => __('Dashboard', 'wp-react-agent'),
                    'edit.php' => __('Posts', 'wp-react-agent'),
                    'post-new.php' => __('Add New Post', 'wp-react-agent'),
                    'upload.php' => __('Media Library', 'wp-react-agent'),
                    'edit.php?post_type=page' => __('Pages', 'wp-react-agent'),
                    'post-new.php?post_type=page' => __('Add New Page', 'wp-react-agent'),
                    'edit-comments.php' => __('Comments', 'wp-react-agent'),
                    'themes.php' => __('Appearance', 'wp-react-agent'),
                    'widgets.php' => __('Widgets', 'wp-react-agent'),
                    'nav-menus.php' => __('Menus', 'wp-react-agent'),
                    'plugins.php' => __('Plugins', 'wp-react-agent'),
                    'users.php' => __('Users', 'wp-react-agent'),
                    'user-new.php' => __('Add New User', 'wp-react-agent'),
                    'profile.php' => __('Your Profile', 'wp-react-agent'),
                    'tools.php' => __('Tools', 'wp-react-agent'),
                    'options-general.php' => __('Settings', 'wp-react-agent'),
                    'customize.php' => __('Customize', 'wp-react-agent'),
                );

                // Generate the URL based on input
                $navigation_url = '';
                $page_title = '';

                if ( !empty( $url ) ) {
                    // Use provided URL directly
                    $navigation_url = $url;
                    $page_title = __('External URL', 'wp-react-agent');
                } else if ( !empty( $page ) ) {
                    // Handle admin page navigation
                    if ( strpos( $page, 'http' ) === 0 ) {
                        // Page is actually a full URL
                        $navigation_url = esc_url_raw( $page );
                        $page_title = __('Custom URL', 'wp-react-agent');
                    } else {
                        // It's an admin page reference
                        $admin_url = admin_url( $page );
                        
                        // Add any parameters if provided
                        if ( !empty( $params ) ) {
                            // Handle the case where page already has parameters
                            $separator = ( strpos( $admin_url, '?' ) !== false ) ? '&' : '?';
                            $param_str = http_build_query( $params );
                            $admin_url .= $separator . $param_str;
                        }
                        
                        $navigation_url = $admin_url;
                        $page_title = isset( $admin_pages[$page] ) ? $admin_pages[$page] : __('Admin Page', 'wp-react-agent');
                    }
                }

                return array(
                    'success' => true,
                    'url' => $navigation_url,
                    'title' => $page_title,
                    'message' => sprintf( 
                        __( 'Navigation link to %s is ready.', 'wp-react-agent' ), 
                        $page_title 
                    ),
                );
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'success' => array( 'type' => 'boolean' ),
                    'url' => array( 'type' => 'string' ),
                    'title' => array( 'type' => 'string' ),
                    'message' => array( 'type' => 'string' ),
                ),
                'required' => array('success', 'url', 'message'),
            ),
        )
    );

    // --- Feature 2: Get Current Admin Page Information (Resource) ---
    wp_register_feature(
        array(
            'id'          => 'wp/get-current-screen',
            'name'        => __( 'Get Current Admin Screen', 'wp-react-agent' ),
            'description' => __( 'Retrieves information about the current WordPress admin screen.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_RESOURCE,
            'categories'  => array( 'wp', 'navigation', 'screen' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(),
                'description' => 'No input arguments needed.',
            ),
            'permission_callback' => function() {
                return is_admin() && current_user_can( 'read' );
            },
            'callback'    => function( $request ) {
                // Get the current screen if available
                $screen = function_exists('get_current_screen') ? get_current_screen() : null;
                
                if ( ! $screen ) {
                    return new WP_Error( 
                        'screen_not_available', 
                        __( 'Current screen information is not available.', 'wp-react-agent' ), 
                        array( 'status' => 404 ) 
                    );
                }

                // Build page information
                global $pagenow;
                $query_string = isset( $_SERVER['QUERY_STRING'] ) ? $_SERVER['QUERY_STRING'] : '';
                
                // Basic URL information
                $protocol = is_ssl() ? 'https://' : 'http://';
                $current_url = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

                return array(
                    'screen_id' => $screen->id ?? '',
                    'base' => $screen->base ?? '',
                    'post_type' => $screen->post_type ?? '',
                    'taxonomy' => $screen->taxonomy ?? '',
                    'pagenow' => $pagenow ?? '',
                    'query_string' => $query_string,
                    'url' => $current_url,
                    'is_admin' => is_admin(),
                );
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'screen_id' => array( 'type' => 'string' ),
                    'base' => array( 'type' => 'string' ),
                    'post_type' => array( 'type' => 'string' ),
                    'taxonomy' => array( 'type' => 'string' ),
                    'pagenow' => array( 'type' => 'string' ),
                    'query_string' => array( 'type' => 'string' ),
                    'url' => array( 'type' => 'string' ),
                    'is_admin' => array( 'type' => 'boolean' ),
                ),
                'required' => array('is_admin'),
            ),
        )
    );
}

// Register with the centralized feature loader
if (function_exists('wp_react_agent_register_feature_set')) {
    wp_react_agent_register_feature_set(
        'wp-navigation',                              // Unique ID
        'WordPress Navigation Features',              // Label
        'wp_feature_api_navigation_register_features', // Callback function
        array(
            'wp_register_feature',                     // Require Feature API
            'WP_Feature',                              // Require Feature API classes
            'admin_url',                               // Require WordPress admin functions
        )
    );
    navigation_feature_api_debug_log('Navigation Features registered with loader');
} else {
    navigation_feature_api_debug_log('Centralized loader not available, Navigation features will not be registered');
}

// Remove the old hooks approach
if (has_action('init', 'wp_feature_api_navigation_register_features')) {
    remove_action('init', 'wp_feature_api_navigation_register_features', 99);
}

// Remove debug message that gets printed on every inclusion
if (isset($navigation_features_file_loaded)) {
    $navigation_features_file_loaded = true;
}

<?php
// WordPress Users API Features

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Log debug messages to WordPress error log
 */
function wp_users_feature_api_debug_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG === true) {
        error_log('WP Users Feature API: ' . $message);
    }
}

/**
 * Register WordPress Users features with the Feature API.
 */
function wp_feature_api_wp_users_register_features() {
    wp_users_feature_api_debug_log('Registering WordPress Users features');
    
    // --- Feature 1: Get Current User (Resource) ---
    wp_register_feature(
        array(
            'id'          => 'wp/get-current-user',
            'name'        => __( 'Get Current User', 'wp-react-agent' ),
            'description' => __( 'Retrieves information about the currently logged-in user.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_RESOURCE,
            'categories'  => array( 'wp', 'users', 'current' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'include_private' => array(
                        'type' => 'boolean',
                        'description' => __( 'Whether to include private user information like email.', 'wp-react-agent' ),
                    ),
                ),
            ),
            'permission_callback' => function() {
                // Require user to be logged in
                return is_user_logged_in();
            },
            'callback'    => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $include_private = isset( $context['include_private'] ) ? (bool) $context['include_private'] : false;
                
                // Get current user
                $current_user = wp_get_current_user();
                
                if (!$current_user || $current_user->ID === 0) {
                    return new WP_Error('not_logged_in', __('No user is currently logged in.', 'wp-react-agent'), array('status' => 401));
                }
                
                $user_data = array(
                    'id' => $current_user->ID,
                    'login' => $current_user->user_login,
                    'nicename' => $current_user->user_nicename,
                    'display_name' => $current_user->display_name,
                    'url' => $current_user->user_url,
                    'registered' => $current_user->user_registered,
                    'roles' => $current_user->roles,
                    'capabilities' => array_keys(array_filter($current_user->allcaps)),
                );
                
                // Include private data if requested and user has permission
                if ($include_private && current_user_can('edit_users')) {
                    $user_data['email'] = $current_user->user_email;
                    $user_data['first_name'] = $current_user->first_name;
                    $user_data['last_name'] = $current_user->last_name;
                }
                
                return array(
                    'user' => $user_data,
                );
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'user' => array(
                        'type' => 'object',
                        'properties' => array(
                            'id' => array('type' => 'integer'),
                            'login' => array('type' => 'string'),
                            'email' => array('type' => 'string'),
                            'roles' => array('type' => 'array'),
                        ),
                    ),
                ),
                'required' => array('user'),
            ),
        )
    );
    
    // --- Feature 2: Get User (Resource) ---
    wp_register_feature(
        array(
            'id'          => 'wp/get-user',
            'name'        => __( 'Get User', 'wp-react-agent' ),
            'description' => __( 'Retrieves information about a specific WordPress user.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_RESOURCE,
            'categories'  => array( 'wp', 'users', 'get' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'user_id' => array(
                        'type' => 'integer',
                        'description' => __( 'ID of the user to retrieve.', 'wp-react-agent' ),
                        'required' => true,
                    ),
                    'include_private' => array(
                        'type' => 'boolean',
                        'description' => __( 'Whether to include private user information like email.', 'wp-react-agent' ),
                    ),
                ),
                'required' => array('user_id'),
            ),
            'permission_callback' => function() {
                // Require read capability at minimum
                return current_user_can('read');
            },
            'callback'    => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $user_id = isset( $context['user_id'] ) ? intval( $context['user_id'] ) : 0;
                $include_private = isset( $context['include_private'] ) ? (bool) $context['include_private'] : false;
                
                if (empty($user_id)) {
                    return new WP_Error('missing_user_id', __('User ID is required.', 'wp-react-agent'), array('status' => 400));
                }
                
                // Get user
                $user = get_user_by('id', $user_id);
                
                if (!$user) {
                    return new WP_Error('user_not_found', __('User not found.', 'wp-react-agent'), array('status' => 404));
                }
                
                $user_data = array(
                    'id' => $user->ID,
                    'login' => $user->user_login,
                    'nicename' => $user->user_nicename,
                    'display_name' => $user->display_name,
                    'url' => $user->user_url,
                    'registered' => $user->user_registered,
                    'roles' => $user->roles,
                );
                
                // Include private data if requested and user has permission
                if ($include_private && current_user_can('edit_users')) {
                    $user_data['email'] = $user->user_email;
                    $user_data['first_name'] = $user->first_name;
                    $user_data['last_name'] = $user->last_name;
                }
                
                return array(
                    'user' => $user_data,
                );
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'user' => array(
                        'type' => 'object',
                        'properties' => array(
                            'id' => array('type' => 'integer'),
                            'login' => array('type' => 'string'),
                            'email' => array('type' => 'string'),
                            'roles' => array('type' => 'array'),
                        ),
                    ),
                ),
                'required' => array('user'),
            ),
        )
    );
}

// Register with the centralized feature loader
if (function_exists('wp_react_agent_register_feature_set')) {
    wp_react_agent_register_feature_set(
        'wp-users',                                // Unique ID
        'WordPress Users Features',                // Label
        'wp_feature_api_wp_users_register_features', // Callback function
        array(
            'wp_register_feature',                 // Require Feature API
            'WP_Feature',                          // Require Feature API classes
            'get_user_by',                         // Require WordPress core functions
            'is_user_logged_in',
        )
    );
    wp_users_feature_api_debug_log('WordPress Users Features registered with loader');
} 
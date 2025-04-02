<?php
// File: wp-react-agent/features/wordpress-options.php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Log debug messages to WordPress error log
 */
function wp_options_feature_api_debug_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG === true) {
        error_log('WP Options Feature API: ' . $message);
    }
}

/**
 * Register WordPress Options features with the Feature API.
 */
function wp_feature_api_wp_options_register_features() {
    // Dependency checks are now handled by the centralized loader
    wp_options_feature_api_debug_log('Registering WordPress Options features');
    
    // --- Feature 1: Get Option (Resource) ---
    wp_register_feature(
        array(
            'id'          => 'wp/get-option',
            'name'        => __( 'Get WordPress Option', 'wp-react-agent' ),
            'description' => __( 'Retrieves an option value from the WordPress database.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_RESOURCE,
            'categories'  => array( 'wp', 'options', 'get' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'option_name' => array(
                        'type' => 'string',
                        'description' => __( 'Name of the option to retrieve.', 'wp-react-agent' ),
                        'required' => true,
                    ),
                    'default' => array(
                        'type' => 'string',
                        'description' => __( 'Default value to return if the option does not exist.', 'wp-react-agent' ),
                    ),
                ),
                'required' => array( 'option_name' ),
            ),
            'permission_callback' => function() {
                // Allow users with edit_posts capability (authors and above) to read options
                // This is less restrictive than manage_options (admin only)
                return current_user_can( 'edit_posts' );
            },
            'callback'    => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $option_name = isset( $context['option_name'] ) ? sanitize_key( $context['option_name'] ) : '';
                $default = isset( $context['default'] ) ? $context['default'] : false;

                if ( empty( $option_name ) ) {
                    return new WP_Error( 'missing_option_name', __( 'Option name is required.', 'wp-react-agent' ), array( 'status' => 400 ) );
                }

                // Get the option
                $option_value = get_option( $option_name, $default );

                return array(
                    'option_name' => $option_name,
                    'value' => $option_value,
                );
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'option_name' => array( 'type' => 'string' ),
                    'value' => array( 'type' => 'mixed', 'description' => 'The option value.' ),
                ),
                'required' => array('option_name', 'value'),
            ),
        )
    );

    // --- Feature 2: Update Option (Tool) ---
    wp_register_feature(
        array(
            'id'          => 'wp/update-option',
            'name'        => __( 'Update WordPress Option', 'wp-react-agent' ),
            'description' => __( 'Updates or adds an option to the WordPress database.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_TOOL,
            'categories'  => array( 'wp', 'options', 'update' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'option_name' => array(
                        'type' => 'string',
                        'description' => __( 'Name of the option to update.', 'wp-react-agent' ),
                        'required' => true,
                    ),
                    'option_value' => array(
                        'type' => 'mixed',
                        'description' => __( 'Value to store for the option.', 'wp-react-agent' ),
                        'required' => true,
                    ),
                    'autoload' => array(
                        'type' => 'boolean',
                        'description' => __( 'Whether to load the option when WordPress starts up.', 'wp-react-agent' ),
                    ),
                ),
                'required' => array( 'option_name', 'option_value' ),
            ),
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'callback'    => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $option_name = isset( $context['option_name'] ) ? sanitize_key( $context['option_name'] ) : '';
                $option_value = $context['option_value'] ?? null;
                $autoload = isset( $context['autoload'] ) ? (bool) $context['autoload'] : null;

                if ( empty( $option_name ) ) {
                    return new WP_Error( 'missing_option_name', __( 'Option name is required.', 'wp-react-agent' ), array( 'status' => 400 ) );
                }

                if ( $option_value === null ) {
                    return new WP_Error( 'missing_option_value', __( 'Option value is required.', 'wp-react-agent' ), array( 'status' => 400 ) );
                }

                // Update the option
                $result = ($autoload !== null) 
                    ? update_option( $option_name, $option_value, $autoload )
                    : update_option( $option_name, $option_value );

                return array(
                    'success' => (bool) $result,
                    'option_name' => $option_name,
                    'message' => $result 
                        ? __( 'Option updated successfully.', 'wp-react-agent' )
                        : __( 'Option value did not change or update failed.', 'wp-react-agent' ),
                );
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'success' => array( 'type' => 'boolean' ),
                    'option_name' => array( 'type' => 'string' ),
                    'message' => array( 'type' => 'string' ),
                ),
                'required' => array('success', 'option_name', 'message'),
            ),
        )
    );

    // --- Feature 3: Delete Option (Tool) ---
    wp_register_feature(
        array(
            'id'          => 'wp/delete-option',
            'name'        => __( 'Delete WordPress Option', 'wp-react-agent' ),
            'description' => __( 'Removes an option from the WordPress database.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_TOOL,
            'categories'  => array( 'wp', 'options', 'delete' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'option_name' => array(
                        'type' => 'string',
                        'description' => __( 'Name of the option to delete.', 'wp-react-agent' ),
                        'required' => true,
                    ),
                ),
                'required' => array( 'option_name' ),
            ),
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'callback'    => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $option_name = isset( $context['option_name'] ) ? sanitize_key( $context['option_name'] ) : '';

                if ( empty( $option_name ) ) {
                    return new WP_Error( 'missing_option_name', __( 'Option name is required.', 'wp-react-agent' ), array( 'status' => 400 ) );
                }

                // Delete the option
                $result = delete_option( $option_name );

                return array(
                    'success' => (bool) $result,
                    'option_name' => $option_name,
                    'message' => $result 
                        ? __( 'Option deleted successfully.', 'wp-react-agent' ) 
                        : __( 'Option not found or delete failed.', 'wp-react-agent' ),
                );
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'success' => array( 'type' => 'boolean' ),
                    'option_name' => array( 'type' => 'string' ),
                    'message' => array( 'type' => 'string' ),
                ),
                'required' => array('success', 'option_name', 'message'),
            ),
        )
    );
}

// Register with the centralized feature loader
if (function_exists('wp_react_agent_register_feature_set')) {
    wp_react_agent_register_feature_set(
        'wp-options',                              // Unique ID
        'WordPress Options Features',              // Label
        'wp_feature_api_wp_options_register_features', // Callback function
        array(
            'wp_register_feature',                 // Require Feature API
            'WP_Feature',                          // Require Feature API classes
            'get_option',                          // Require WordPress core functions
        )
    );
    wp_options_feature_api_debug_log('WordPress Options Features registered with loader');
} else {
    wp_options_feature_api_debug_log('Centralized loader not available, WordPress Options features will not be registered');
}

// Remove the old hooks approach
if (has_action('init', 'wp_feature_api_wp_options_register_features')) {
    remove_action('init', 'wp_feature_api_wp_options_register_features', 99);
}

// Remove debug message that gets printed on every inclusion
if (isset($wp_options_features_file_loaded)) {
    $wp_options_features_file_loaded = true;
}

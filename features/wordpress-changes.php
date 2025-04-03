<?php
/**
 * Feature Set: WordPress Plugin Changes Viewer
 *
 * Provides features for viewing the changes.json file of the WP ReAct Agent plugin.
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Log debug messages to WordPress error log
 */
function wp_changes_feature_api_debug_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG === true) {
        error_log('WP Changes Feature API: ' . $message);
    }
}

/**
 * Initialization function for the WordPress Changes feature set.
 */
function wp_react_agent_init_wordpress_changes() {
    // Dependency check before registering
    if (!function_exists('wp_register_feature') || !class_exists('WP_Feature')) {
        wp_changes_feature_api_debug_log("WordPress Changes feature requires Feature API, but it's not available.");
        return; // Feature API is required
    }

    wp_changes_feature_api_debug_log('Registering WordPress Changes features');
    
    // Register the feature with the proper syntax
    wp_register_feature(
        array(
            'id'          => 'wp-react-agent/view-plugin-changes',
            'name'        => __('View WP ReAct Agent Plugin Changes', 'wp-react-agent'),
            'description' => __('Retrieves and displays the content of the changes.json file for the WP ReAct Agent plugin.', 'wp-react-agent'),
            'type'        => WP_Feature::TYPE_RESOURCE,
            'categories'  => array('wp', 'plugin', 'changes'),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'version' => array(
                        'type' => 'string',
                        'description' => __('Optional version to filter changes by', 'wp-react-agent'),
                    ),
                    'show_all' => array(
                        'type' => 'boolean',
                        'description' => __('Set to true to show all versions instead of just the latest', 'wp-react-agent'),
                    ),
                    'highlight_only' => array(
                        'type' => 'boolean',
                        'description' => __('Set to true to show only highlighted changes', 'wp-react-agent'),
                    ),
                ),
            ),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
            'callback'    => 'wp_react_agent_action_view_plugin_changes',
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'source' => array('type' => 'string'),
                    'data' => array('type' => 'object'),
                    'filtered' => array('type' => 'boolean'),
                    'filter_type' => array('type' => 'string'),
                ),
                'required' => array('source', 'data', 'filtered'),
            ),
        )
    );
}

/**
 * Action callback for the 'view-plugin-changes' feature.
 *
 * Reads and returns the parsed content of changes.json.
 *
 * @param WP_REST_Request $request The request object.
 * @return array|WP_Error The parsed changes data or an error object.
 */
function wp_react_agent_action_view_plugin_changes($request) {
    $context = $request->get_param('context') ?? array();
    $version_filter = isset($context['version']) ? sanitize_text_field($context['version']) : null;
    $show_all = isset($context['show_all']) ? filter_var($context['show_all'], FILTER_VALIDATE_BOOLEAN) : false;
    $highlight_only = isset($context['highlight_only']) ? filter_var($context['highlight_only'], FILTER_VALIDATE_BOOLEAN) : false;
    
    $changes_file_path = defined('WP_REACT_AGENT_PATH') ? 
        WP_REACT_AGENT_PATH . 'changes.json' : 
        plugin_dir_path(dirname(__FILE__)) . 'changes.json';

    if (!file_exists($changes_file_path)) {
        return new WP_Error(
            'changes_file_not_found',
            __('The changes.json file for the WP ReAct Agent plugin could not be found.', 'wp-react-agent'),
            array('status' => 404, 'path' => $changes_file_path)
        );
    }

    $json_content = file_get_contents($changes_file_path);
    if ($json_content === false) {
        return new WP_Error(
            'changes_file_read_error',
            __('Could not read the changes.json file.', 'wp-react-agent'),
            array('status' => 500, 'path' => $changes_file_path)
        );
    }

    $changes_data = json_decode($json_content, true); // Decode as associative array

    if (json_last_error() !== JSON_ERROR_NONE) {
        return new WP_Error(
            'changes_file_json_error',
            __('Error parsing the changes.json file: ', 'wp-react-agent') . json_last_error_msg(),
            array('status' => 500, 'path' => $changes_file_path, 'error_code' => json_last_error())
        );
    }
    
    $filter_type = 'none';
    $filtered = false;
    
    // Clone the original data before filtering
    $original_data = $changes_data;
    
    // Apply filters in this order: version, show_all, highlight_only
    
    // Filter by version if requested
    if ($version_filter !== null && isset($changes_data['versions'])) {
        $filtered_versions = array_filter($changes_data['versions'], function($version_data) use ($version_filter) {
            return $version_data['version'] === $version_filter;
        });
        
        if (!empty($filtered_versions)) {
            $changes_data['versions'] = array_values($filtered_versions); // Reset array keys
            $filtered = true;
            $filter_type = 'version';
        }
    }
    // If no version filter or no results, and not showing all, default to the latest version
    elseif (!$show_all && isset($changes_data['versions']) && !empty($changes_data['versions'])) {
        // Sort versions by date (newest first)
        usort($changes_data['versions'], function($a, $b) {
            return strtotime($b['release_date']) - strtotime($a['release_date']);
        });
        
        // Keep only the most recent version
        $changes_data['versions'] = array(reset($changes_data['versions']));
        $filtered = true;
        $filter_type = 'latest';
    }
    
    // Filter by highlight flag if requested (works with any of the above filters)
    if ($highlight_only && isset($changes_data['versions'])) {
        foreach ($changes_data['versions'] as &$version) {
            if (isset($version['changes'])) {
                $highlighted_changes = array_filter($version['changes'], function($change) {
                    return isset($change['highlight']) && $change['highlight'] === true;
                });
                
                $version['changes'] = array_values($highlighted_changes); // Reset array keys
            }
        }
        $filtered = true;
        $filter_type = $filter_type === 'none' ? 'highlight' : $filter_type . '+highlight';
    }

    // Add a top-level note about the source and filtering
    $response = array(
        'source' => 'WP ReAct Agent changes.json',
        'data' => $changes_data,
        'filtered' => $filtered,
        'filter_type' => $filter_type,
        'original_version_count' => isset($original_data['versions']) ? count($original_data['versions']) : 0,
        'filtered_version_count' => isset($changes_data['versions']) ? count($changes_data['versions']) : 0
    );

    return $response;
}

// Register this feature set with the main plugin loader
if (function_exists('wp_react_agent_register_feature_set')) {
    wp_react_agent_register_feature_set(
        'wp-react-agent-changes',
        __('WordPress Changes Viewer', 'wp-react-agent'),
        'wp_react_agent_init_wordpress_changes',
        array(
            'wp_register_feature',     // Require Feature API
            'WP_Feature',              // Require Feature API classes
        )
    );
    wp_changes_feature_api_debug_log('WordPress Changes Viewer registered with loader');
} else {
    wp_changes_feature_api_debug_log('Centralized loader not available, WordPress Changes features will not be registered');
} 
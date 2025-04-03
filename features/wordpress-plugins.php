<?php
// WordPress Plugins API Features

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Log debug messages to WordPress error log
 */
function wp_plugins_feature_api_debug_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG === true) {
        error_log('WP Plugins Feature API: ' . $message);
    }
}

/**
 * Register WordPress Plugins features with the Feature API.
 */
function wp_feature_api_wp_plugins_register_features() {
    wp_plugins_feature_api_debug_log('Registering WordPress Plugins features');
    
    // --- Feature 1: List Plugins (Resource) ---
    wp_register_feature(
        array(
            'id'          => 'wp/list-plugins',
            'name'        => __( 'List WordPress Plugins', 'wp-react-agent' ),
            'description' => __( 'Retrieves a list of plugins on the WordPress site with filtering options.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_RESOURCE,
            'categories'  => array( 'wp', 'plugins', 'list' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'status' => array(
                        'type' => 'string',
                        'description' => __( 'Filter plugins by status: "active", "inactive", or "all"', 'wp-react-agent' ),
                        'enum' => array('active', 'inactive', 'all'),
                    ),
                    'search' => array(
                        'type' => 'string',
                        'description' => __( 'Search term to filter plugins by name or description', 'wp-react-agent' ),
                    ),
                    'include_details' => array(
                        'type' => 'boolean',
                        'description' => __( 'Whether to include detailed plugin information.', 'wp-react-agent' ),
                    ),
                ),
            ),
            'permission_callback' => function() {
                // Require manage_options since plugin information is sensitive
                return current_user_can( 'manage_options' );
            },
            'callback'    => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $status = isset($context['status']) ? sanitize_text_field($context['status']) : 'active';
                $search = isset($context['search']) ? sanitize_text_field($context['search']) : '';
                $include_details = isset($context['include_details']) ? (bool) $context['include_details'] : false;
                
                if (!function_exists('get_plugins')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                
                // Get all plugins
                $all_plugins = get_plugins();
                $active_plugins = get_option('active_plugins', array());
                $result = array();
                
                foreach ($all_plugins as $plugin_path => $plugin_data) {
                    $is_active = in_array($plugin_path, $active_plugins);
                    
                    // Skip if we're filtering by status and it doesn't match
                    if (($status === 'active' && !$is_active) || ($status === 'inactive' && $is_active)) {
                        continue;
                    }
                    
                    // Skip if we're searching and it doesn't match
                    if (!empty($search)) {
                        $search_haystack = strtolower($plugin_data['Name'] . ' ' . $plugin_data['Description']);
                        if (strpos($search_haystack, strtolower($search)) === false) {
                            continue;
                        }
                    }
                    
                    if ($include_details) {
                        $result[] = array(
                            'path' => $plugin_path,
                            'status' => $is_active ? 'active' : 'inactive',
                            'name' => $plugin_data['Name'],
                            'version' => $plugin_data['Version'],
                            'description' => $plugin_data['Description'],
                            'author' => $plugin_data['Author'],
                            'text_domain' => $plugin_data['TextDomain'],
                        );
                    } else {
                        $result[] = array(
                            'path' => $plugin_path,
                            'status' => $is_active ? 'active' : 'inactive',
                            'name' => $plugin_data['Name'],
                        );
                    }
                }
                
                return array(
                    'plugins' => $result,
                    'count' => count($result),
                    'status' => $status,
                );
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'plugins' => array( 'type' => 'array', 'items' => array('type' => 'object') ),
                    'count' => array( 'type' => 'integer' ),
                    'status' => array( 'type' => 'string' ),
                ),
                'required' => array('plugins', 'count', 'status'),
            ),
        )
    );
    
    // --- Feature 2: Search Plugins (Resource) ---
    wp_register_feature(
        array(
            'id'          => 'wp/search-plugins',
            'name'        => __( 'Search WordPress Plugins', 'wp-react-agent' ),
            'description' => __( 'Searches for plugins in the WordPress.org plugin directory.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_RESOURCE,
            'categories'  => array( 'wp', 'plugins', 'search' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'search' => array(
                        'type' => 'string',
                        'description' => __( 'Search term for plugins.', 'wp-react-agent' ),
                        'required' => true,
                    ),
                    'page' => array(
                        'type' => 'integer',
                        'description' => __( 'Page number for results pagination.', 'wp-react-agent' ),
                    ),
                    'per_page' => array(
                        'type' => 'integer',
                        'description' => __( 'Number of plugins per page.', 'wp-react-agent' ),
                    ),
                ),
                'required' => array('search'),
            ),
            'permission_callback' => function() {
                return current_user_can( 'install_plugins' );
            },
            'callback'    => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $search = isset($context['search']) ? sanitize_text_field($context['search']) : '';
                $page = isset($context['page']) ? intval($context['page']) : 1;
                $per_page = isset($context['per_page']) ? intval($context['per_page']) : 10;
                
                if (empty($search)) {
                    return new WP_Error('missing_search', __('Search term is required.', 'wp-react-agent'), array('status' => 400));
                }
                
                if (!function_exists('plugins_api')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
                }
                
                $args = array(
                    'search' => $search,
                    'page' => $page,
                    'per_page' => $per_page,
                    'fields' => array(
                        'last_updated' => true,
                        'icons' => true,
                        'active_installs' => true,
                    ),
                );
                
                $api = plugins_api('query_plugins', $args);
                
                if (is_wp_error($api)) {
                    return new WP_Error(
                        'plugin_api_error',
                        sprintf(__('WordPress plugin API error: %s', 'wp-react-agent'), $api->get_error_message()),
                        array('status' => 500)
                    );
                }
                
                return array(
                    'plugins' => $api->plugins,
                    'total' => $api->info['results'],
                    'pages' => $api->info['pages'],
                );
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'plugins' => array('type' => 'array'),
                    'total' => array('type' => 'integer'),
                    'pages' => array('type' => 'integer'),
                ),
                'required' => array('plugins', 'total', 'pages'),
            ),
        )
    );
    
    // --- Feature 3: Install Plugin (Tool) ---
    wp_register_feature(
        array(
            'id'          => 'wp/install-plugin',
            'name'        => __( 'Install WordPress Plugin', 'wp-react-agent' ),
            'description' => __( 'Installs a plugin from the WordPress.org plugin directory.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_TOOL,
            'categories'  => array( 'wp', 'plugins', 'install' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'slug' => array(
                        'type' => 'string',
                        'description' => __( 'Plugin slug to install.', 'wp-react-agent' ),
                        'required' => true,
                    ),
                ),
                'required' => array('slug'),
            ),
            'permission_callback' => function() {
                return current_user_can( 'install_plugins' );
            },
            'callback'    => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $slug = isset($context['slug']) ? sanitize_key($context['slug']) : '';
                
                if (empty($slug)) {
                    return new WP_Error('missing_slug', __('Plugin slug is required.', 'wp-react-agent'), array('status' => 400));
                }
                
                // Check if plugin is already installed
                if (!function_exists('get_plugins')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                
                $all_plugins = get_plugins();
                foreach ($all_plugins as $file => $plugin) {
                    if (strpos($file, $slug . '/') === 0 || $file === $slug . '.php') {
                        return array(
                            'success' => false,
                            'message' => __('Plugin is already installed.', 'wp-react-agent'),
                            'plugin' => $plugin,
                            'slug' => $slug,
                        );
                    }
                }
                
                // Install the plugin
                if (!function_exists('plugins_api')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
                }
                
                if (!class_exists('WP_Upgrader')) {
                    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                }
                
                if (!class_exists('Plugin_Upgrader')) {
                    require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
                }
                
                if (!class_exists('WP_Upgrader_Skin')) {
                    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
                }
                
                // Quiet skin that doesn't output anything
                class Quiet_Plugin_Installer_Skin extends WP_Upgrader_Skin {
                    public function feedback($string, ...$args) {}
                    public function header() {}
                    public function footer() {}
                    public function error($errors) {}
                }
                
                // Get plugin information
                $api = plugins_api('plugin_information', array(
                    'slug' => $slug,
                    'fields' => array(
                        'short_description' => false,
                        'sections' => false,
                        'requires' => false,
                        'rating' => false,
                        'ratings' => false,
                        'downloaded' => false,
                        'last_updated' => false,
                        'added' => false,
                        'tags' => false,
                        'compatibility' => false,
                        'homepage' => false,
                        'donate_link' => false,
                    ),
                ));
                
                if (is_wp_error($api)) {
                    return new WP_Error(
                        'plugin_api_error',
                        sprintf(__('WordPress plugin API error: %s', 'wp-react-agent'), $api->get_error_message()),
                        array('status' => 500)
                    );
                }
                
                // Install the plugin
                $upgrader = new Plugin_Upgrader(new Quiet_Plugin_Installer_Skin());
                $result = $upgrader->install($api->download_link);
                
                if (is_wp_error($result)) {
                    return new WP_Error(
                        'plugin_install_error',
                        sprintf(__('Plugin installation error: %s', 'wp-react-agent'), $result->get_error_message()),
                        array('status' => 500)
                    );
                }
                
                if (is_null($result) || $result === false) {
                    return array(
                        'success' => false,
                        'message' => __('Plugin installation failed for an unknown reason.', 'wp-react-agent'),
                        'slug' => $slug,
                    );
                }
                
                return array(
                    'success' => true,
                    'message' => __('Plugin installed successfully.', 'wp-react-agent'),
                    'slug' => $slug,
                    'plugin_info' => $api,
                );
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'success' => array('type' => 'boolean'),
                    'message' => array('type' => 'string'),
                    'slug' => array('type' => 'string'),
                ),
                'required' => array('success', 'message', 'slug'),
            ),
        )
    );
    
    // --- Feature 4: Activate Plugin (Tool) ---
    wp_register_feature(
        array(
            'id'          => 'wp/activate-plugin',
            'name'        => __( 'Activate WordPress Plugin', 'wp-react-agent' ),
            'description' => __( 'Activates an installed WordPress plugin.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_TOOL,
            'categories'  => array( 'wp', 'plugins', 'activate' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'plugin_file' => array(
                        'type' => 'string',
                        'description' => __( 'Plugin file path relative to plugins directory (e.g. plugin-name/plugin-name.php).', 'wp-react-agent' ),
                        'required' => true,
                    ),
                ),
                'required' => array('plugin_file'),
            ),
            'permission_callback' => function() {
                return current_user_can( 'activate_plugins' );
            },
            'callback'    => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $plugin_file = isset($context['plugin_file']) ? sanitize_text_field($context['plugin_file']) : '';
                
                if (empty($plugin_file)) {
                    return new WP_Error('missing_plugin_file', __('Plugin file is required.', 'wp-react-agent'), array('status' => 400));
                }
                
                if (!function_exists('activate_plugin')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                
                // Check if plugin exists
                if (!file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
                    return new WP_Error(
                        'plugin_not_found',
                        __('Plugin file not found.', 'wp-react-agent'),
                        array('status' => 404)
                    );
                }
                
                // Check if plugin is already active
                if (is_plugin_active($plugin_file)) {
                    return array(
                        'success' => false,
                        'message' => __('Plugin is already active.', 'wp-react-agent'),
                        'plugin_file' => $plugin_file,
                    );
                }
                
                // Activate the plugin
                $result = activate_plugin($plugin_file);
                
                if (is_wp_error($result)) {
                    return new WP_Error(
                        'plugin_activation_error',
                        sprintf(__('Plugin activation error: %s', 'wp-react-agent'), $result->get_error_message()),
                        array('status' => 500)
                    );
                }
                
                return array(
                    'success' => true,
                    'message' => __('Plugin activated successfully.', 'wp-react-agent'),
                    'plugin_file' => $plugin_file,
                );
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'success' => array('type' => 'boolean'),
                    'message' => array('type' => 'string'),
                    'plugin_file' => array('type' => 'string'),
                ),
                'required' => array('success', 'message', 'plugin_file'),
            ),
        )
    );
    
    // --- Feature 5: Deactivate Plugin (Tool) ---
    wp_register_feature(
        array(
            'id'          => 'wp/deactivate-plugin',
            'name'        => __( 'Deactivate WordPress Plugin', 'wp-react-agent' ),
            'description' => __( 'Deactivates an active WordPress plugin.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_TOOL,
            'categories'  => array( 'wp', 'plugins', 'deactivate' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'plugin_file' => array(
                        'type' => 'string',
                        'description' => __( 'Plugin file path relative to plugins directory (e.g. plugin-name/plugin-name.php).', 'wp-react-agent' ),
                        'required' => true,
                    ),
                ),
                'required' => array('plugin_file'),
            ),
            'permission_callback' => function() {
                return current_user_can( 'activate_plugins' );
            },
            'callback'    => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $plugin_file = isset($context['plugin_file']) ? sanitize_text_field($context['plugin_file']) : '';
                
                if (empty($plugin_file)) {
                    return new WP_Error('missing_plugin_file', __('Plugin file is required.', 'wp-react-agent'), array('status' => 400));
                }
                
                if (!function_exists('deactivate_plugins')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                
                // Check if plugin exists
                if (!file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
                    return new WP_Error(
                        'plugin_not_found',
                        __('Plugin file not found.', 'wp-react-agent'),
                        array('status' => 404)
                    );
                }
                
                // Check if plugin is inactive
                if (!is_plugin_active($plugin_file)) {
                    return array(
                        'success' => false,
                        'message' => __('Plugin is already inactive.', 'wp-react-agent'),
                        'plugin_file' => $plugin_file,
                    );
                }
                
                // Deactivate the plugin
                deactivate_plugins($plugin_file);
                
                // Verify deactivation
                if (is_plugin_active($plugin_file)) {
                    return array(
                        'success' => false,
                        'message' => __('Failed to deactivate plugin.', 'wp-react-agent'),
                        'plugin_file' => $plugin_file,
                    );
                }
                
                return array(
                    'success' => true,
                    'message' => __('Plugin deactivated successfully.', 'wp-react-agent'),
                    'plugin_file' => $plugin_file,
                );
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'success' => array('type' => 'boolean'),
                    'message' => array('type' => 'string'),
                    'plugin_file' => array('type' => 'string'),
                ),
                'required' => array('success', 'message', 'plugin_file'),
            ),
        )
    );
}

// Register with the centralized feature loader
if (function_exists('wp_react_agent_register_feature_set')) {
    wp_react_agent_register_feature_set(
        'wp-plugins',                               // Unique ID
        'WordPress Plugins Features',               // Label
        'wp_feature_api_wp_plugins_register_features', // Callback function
        array(
            'wp_register_feature',                  // Require Feature API
            'WP_Feature',                           // Require Feature API classes
            'get_plugins',                          // Require WordPress core functions
        )
    );
    wp_plugins_feature_api_debug_log('WordPress Plugins Features registered with loader');
} 
<?php
/*
Plugin Name: WP ReAct Agent (using AI Services)
Description: A modular ReAct Agent framework using WordPress Feature API and the AI Services plugin. Easily extend with new features.
Version: 0.3.0
Author: James LePage
Author URI: https://j.cv
Requires PHP: 8.1
Requires Plugins: wp-feature-api, ai-services, contact-form-7
License: GPL-2.0-or-later
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'WP_REACT_AGENT_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_REACT_AGENT_URL', plugin_dir_url( __FILE__ ) );

/**
 * Global array to store feature registrations
 */
global $wp_react_agent_feature_set;
$wp_react_agent_feature_set = array();

/**
 * Register a feature module with the centralized loader
 * 
 * @param string   $id            Unique identifier for the feature set
 * @param string   $label         Human-readable name for the feature set
 * @param callable $init_callback Function to call to register features
 * @param array    $dependencies  Array of dependency checks (functions/classes that must exist)
 */
function wp_react_agent_register_feature_set($id, $label, $init_callback, $dependencies = array()) {
    global $wp_react_agent_feature_set;
    
    // Store the feature set configuration
    $wp_react_agent_feature_set[$id] = array(
        'label' => $label,
        'init_callback' => $init_callback,
        'dependencies' => $dependencies,
        'loaded' => false
    );
}

/**
 * Debug log function
 */
function wp_react_agent_debug_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG === true) {
        error_log('WP ReAct Agent: ' . $message);
    }
}

/**
 * Central loader function that runs all registered feature sets
 */
function wp_react_agent_load_all_features() {
    global $wp_react_agent_feature_set;
    
    // Core dependencies for the agent itself
    $core_deps_met = true;
    $missing_core_deps = [];
    if (!function_exists('ai_services')) {
        $core_deps_met = false;
        $missing_core_deps[] = 'AI Services';
    }
    if (!function_exists('wp_register_feature') || !class_exists('WP_Feature')) {
        $core_deps_met = false;
        $missing_core_deps[] = 'Feature API';
    }
    
    if (!$core_deps_met) {
        wp_react_agent_debug_log("WP ReAct Agent core disabled. Missing dependencies: " . implode(', ', $missing_core_deps));
        return; // Don't load agent core or features if core deps are missing
    }
    
    // Load agent-core.php only if core dependencies are met
    static $agent_core_loaded = false;
    if (!$agent_core_loaded) {
        require_once WP_REACT_AGENT_PATH . 'agent-core.php';
        $agent_core_loaded = true;
        wp_react_agent_debug_log("Agent core loaded.");
    }

    // Now load feature sets
    foreach ($wp_react_agent_feature_set as $id => &$feature_set) {
        // Always recheck dependencies - don't rely on 'loaded' flag for dependencies
        // This ensures if a plugin gets deactivated, we detect it
        $dependencies_met = true;
        $missing_deps = array();
        
        foreach ($feature_set['dependencies'] as $dep) {
            if (is_string($dep)) {
                // Check if it's a function or class
                if (function_exists($dep) || class_exists($dep)) {
                    continue;
                }
                $dependencies_met = false;
                $missing_deps[] = $dep;
            } elseif (is_callable($dep)) {
                // Custom dependency check
                if ($dep()) {
                    continue;
                }
                $dependencies_met = false;
                $missing_deps[] = 'Custom dependency';
            }
        }
        
        if (!$dependencies_met) {
            // Set loaded to false if dependencies are not met (handles plugin deactivation case)
            if ($feature_set['loaded']) {
                wp_react_agent_debug_log("Feature set '{$feature_set['label']}' was loaded but now has missing dependencies: " . implode(', ', $missing_deps));
                $feature_set['loaded'] = false;
            }
            continue;
        }
        
        // Skip if already loaded
        if ($feature_set['loaded']) {
            continue;
        }
        
        // Load the feature set
        try {
            call_user_func($feature_set['init_callback']);
            $feature_set['loaded'] = true;
            wp_react_agent_debug_log("Feature set '{$feature_set['label']}' loaded successfully");
        } catch (\Exception $e) {
            wp_react_agent_debug_log("Error loading feature set '{$feature_set['label']}': " . $e->getMessage());
        }
    }
}

// --- Automatically Include Feature Registrations ---
$features_dir = WP_REACT_AGENT_PATH . 'features';
if ( is_dir( $features_dir ) ) {
    $feature_files = glob( $features_dir . '/*.php' );
    if ( $feature_files ) {
        foreach ( $feature_files as $feature_file ) {
            require_once $feature_file;
        }
    }
}

// Hook the feature loader to run after most plugins have loaded
add_action('init', 'wp_react_agent_load_all_features', 999);

// --- AJAX Handler ---
add_action( 'wp_ajax_react_agent_run', 'handle_react_agent_run' ); // Defined in agent-core.php

/**
 * Enqueue script for browser console interaction.
 */
function wp_react_agent_enqueue_scripts() {
    if ( ! is_admin() ) { // Only load in admin for simplicity
        return;
    }

    $asset_file = WP_REACT_AGENT_PATH . 'assets/js/wp-react-agent.asset.php';
    $dependencies = array('wp-polyfill'); // Add wp-polyfill for broader browser support if needed
    $version = '0.3.0'; // Updated version

    if ( file_exists( $asset_file ) ) {
        $asset = include $asset_file;
        $dependencies = array_merge($dependencies, $asset['dependencies'] ?? []);
        $version = $asset['version'] ?? $version;
    }

    wp_enqueue_script(
        'wp-react-agent-console',
        WP_REACT_AGENT_URL . 'assets/js/wp-react-agent.js',
        $dependencies,
        $version,
        true // Load in footer
    );

    // Pass AJAX URL and Nonce to the script
    wp_localize_script( 'wp-react-agent-console', 'wpReactAgentData', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'react_agent_run_nonce' ),
    ) );
}
add_action( 'admin_enqueue_scripts', 'wp_react_agent_enqueue_scripts' );

/**
 * Check for dependencies on activation.
 */
function wp_react_agent_activation_check() {
    $feature_api_active = function_exists( 'wp_register_feature' );
    $ai_services_active = function_exists( 'ai_services' );
    // $cf7_active = class_exists( 'WPCF7_ContactForm' ); // Removed CF7 specific check

    if ( ! $feature_api_active || ! $ai_services_active ) { // Removed CF7 check
        deactivate_plugins( plugin_basename( __FILE__ ) );
        $missing = array();
        if (! $feature_api_active) $missing[] = '"WordPress Feature API"';
        if (! $ai_services_active) $missing[] = '"AI Services"';
        // if (! $cf7_active) $missing[] = '"Contact Form 7"'; // Removed CF7 message
        wp_die( 'WP ReAct Agent requires the following core plugin(s) to be active: ' . implode(' and ', $missing) . '. Please activate them first.' );
    }
}
register_activation_hook( __FILE__, 'wp_react_agent_activation_check' );
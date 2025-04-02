<?php

/*
Plugin Name: WP ReAct Agent (using AI Services)
Description: A ReAct Agent using WordPress Feature API and the AI Services plugin.
Version: 0.2.0
Author: James LePage
Author URI: https://j.cv
Requires Plugins: contact-form-7, ai-services, wp-features-api
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'WP_REACT_AGENT_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_REACT_AGENT_URL', plugin_dir_url( __FILE__ ) );

// --- Include Core Agent Logic ---
require_once WP_REACT_AGENT_PATH . 'agent-core.php';

// --- Include Contact Form 7 Feature Registrations ---
require_once WP_REACT_AGENT_PATH . 'cf7-features.php';

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
    $version = '0.2.0';

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
    $cf7_active = class_exists( 'WPCF7_ContactForm' );

    if ( ! $feature_api_active || ! $ai_services_active || ! $cf7_active ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        $missing = array();
        if (! $feature_api_active) $missing[] = '"WordPress Feature API"';
        if (! $ai_services_active) $missing[] = '"AI Services"';
        if (! $cf7_active) $missing[] = '"Contact Form 7"';
        wp_die( 'WP ReAct Agent requires the following plugin(s) to be active: ' . implode(' and ', $missing) . '. Please activate them first.' );
    }
}
register_activation_hook( __FILE__, 'wp_react_agent_activation_check' );
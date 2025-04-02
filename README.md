# WP ReAct Agent

A modular ReAct (Reasoning + Acting) Agent framework for WordPress, powered by Feature API and AI Services plugin.

## Description

This plugin creates an AI agent that can perform actions within WordPress by leveraging the WordPress Feature API. It uses a ReAct (Reasoning + Acting) loop to enable AI models to reason about tasks, take actions, and observe the results.

## Requirements

- WordPress 6.4+
- [WordPress Feature API](https://github.com/Automattic/wp-feature-api/) plugin
- [AI Services](https://wordpress.org/plugins/ai-services/) plugin

## Features

The agent can interact with various WordPress features through a modular, extensible architecture:

### Core Features
- **WordPress Options**
  - Get option values
  - Update option values
  - Delete options

- **Navigation**
  - Navigate to admin pages or URLs
  - Get current screen information

### Plugin-Specific Features
- **Contact Form 7**
  - List forms
  - Get form details
  - Create basic forms
  - Generate forms using AI
  - Delete forms
  - Duplicate forms

## Extending with New Features

You can easily add new features by creating PHP files in the `features/` directory.

### How to Add a New Feature Set

1. Create a new PHP file in the `features/` directory (e.g., `features/your-plugin-name.php`)
2. Register your features using WordPress Feature API
3. The plugin will automatically load your feature file

### Feature File Template

```php
<?php
// File: wp-react-agent/features/your-plugin-name.php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Register Your Plugin features with the Feature API.
 */
function wp_feature_api_your_plugin_register_features() {
    // Check if Dependencies are Active
    if ( ! function_exists( 'wp_register_feature' ) || ! class_exists( 'WP_Feature' ) ) {
        error_log('Feature API - Your Plugin: Missing Feature API. Features not registered.');
        return;
    }
    
    // Add your plugin-specific dependency checks
    if ( ! class_exists( 'Your_Plugin_Class' ) ) {
        error_log('Feature API - Your Plugin: Missing Your Plugin. Features not registered.');
        return;
    }

    // Register your features
    wp_register_feature(
        array(
            'id'          => 'your-plugin/feature-name',
            'name'        => __( 'Your Feature Name', 'wp-react-agent' ),
            'description' => __( 'Description of what your feature does.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_RESOURCE, // or TYPE_TOOL
            'categories'  => array( 'your-plugin', 'category' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    // Define your input parameters
                ),
            ),
            'permission_callback' => function() {
                return current_user_can( 'your_capability' );
            },
            'callback'    => function( $request ) {
                $context = $request->get_param('context') ?? array();
                // Your feature implementation
                
                return array(
                    // Your response
                );
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    // Define your output structure
                ),
            ),
        )
    );
}

// Hook the registration function into WordPress initialization
add_action( 'init', 'wp_feature_api_your_plugin_register_features', 20 );
```

## Usage

Once activated, the plugin loads an agent in the admin console that can be used in the browser console:

```javascript
// In browser console on WordPress admin pages
wpReactAgent.run("Get a list of all Contact Form 7 forms");

// Examples with WordPress Options
wpReactAgent.run("Get the site title option");
wpReactAgent.run("Update the posts_per_page option to 15");

// Example with Navigation
wpReactAgent.run("Take me to the Media Library");
```

## Developer Notes

- All features are modularly organized in the `features/` directory
- The core agent logic is in `agent-core.php`
- Use sanitization and capability checks in all new features
- Test thoroughly with the AI Services plugin's configured AI model

## License

GPL-2.0-or-later

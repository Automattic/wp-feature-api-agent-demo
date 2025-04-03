# WP ReAct Agent

A modular [ReAct (Reasoning + Acting)](https://arxiv.org/abs/2210.03629) Agent framework for WordPress, powered by Feature API and AI Services plugin.

> **Note:** This is a Proof of Concept implementation with several limitations. The current version does not maintain conversation history, user inputs during task execution, or multi-step interactions.


## Description

This plugin creates an AI agent that can perform actions within WordPress by leveraging the WordPress Feature API. It uses a ReAct (Reasoning + Acting) loop to enable AI models to reason about tasks, take actions, and observe the results.

## Requirements

- WordPress 6.4+
- [WordPress Feature API](https://github.com/Automattic/wp-feature-api/) plugin
- [AI Services](https://wordpress.org/plugins/ai-services/) plugin

## Features

The agent can interact with various WordPress features through a modular, extensible architecture:

### Core WordPress Features

- **WordPress Options**
  - Get option values
  - Update option values
  - Delete options

- **WordPress Plugins**
  - List installed plugins
  - Search WordPress.org plugin directory
  - Install plugins
  - Activate/deactivate plugins

- **WordPress Users**
  - Get current user information
  - Get specific user details

- **WordPress Media**
  - List media library items
  - Get media item details
  - Upload media
  - Delete media items

- **WordPress Site Health**
  - Check page cache status
  - Check object cache status
  - Run site health tests
  - Get autoloaded options size
  - Get response time thresholds

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
3. Register your feature set with the centralized loader
4. The plugin will automatically load your feature file

### Feature File Template

```php
<?php
// File: wp-react-agent/features/your-plugin-name.php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Log debug messages to WordPress error log
 */
function your_plugin_feature_api_debug_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG === true) {
        error_log('Your Plugin Feature API: ' . $message);
    }
}

/**
 * Register Your Plugin features with the Feature API.
 */
function wp_feature_api_your_plugin_register_features() {
    your_plugin_feature_api_debug_log('Registering Your Plugin features');
    
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

// Register with the centralized feature loader
if (function_exists('wp_react_agent_register_feature_set')) {
    wp_react_agent_register_feature_set(
        'your-plugin',                              // Unique ID
        'Your Plugin Features',                     // Label
        'wp_feature_api_your_plugin_register_features', // Callback function
        array(
            'wp_register_feature',                  // Require Feature API
            'WP_Feature',                           // Require Feature API classes
            'Your_Plugin_Class',                    // Your plugin-specific dependencies
        )
    );
    your_plugin_feature_api_debug_log('Your Plugin Features registered with loader');
}
```

## Usage

Once activated, the plugin loads an agent in the admin console that can be used in the browser console:

```javascript
// In browser console on WordPress admin pages
wpReactAgent.run("Get a list of all Contact Form 7 forms");

// Examples with WordPress Options
wpReactAgent.run("Get the site title option");
wpReactAgent.run("Update the posts_per_page option to 15");

// Examples with WordPress Plugins
wpReactAgent.run("List all active plugins");
wpReactAgent.run("Search for a SEO plugin");

// Examples with WordPress Media
wpReactAgent.run("Show me the 10 most recent media items");

// Examples with WordPress Site Health
wpReactAgent.run("Check if my site is using page caching");
wpReactAgent.run("What's the size of autoloaded options in my database?");

// Example with Navigation
wpReactAgent.run("Take me to the Media Library");

// Example creating a complex form
wpReactAgent.run("Make me a new form, named after my site title, and related to it. It should have a ton of fields and send to the current user's email.");
```

## Developer Notes

- All features are modularly organized in the `features/` directory
- Each feature file registers with the centralized loader in `plugin.php`
- The core agent logic is in `agent-core.php`
- Use sanitization and capability checks in all new features
- Test thoroughly with the AI Services plugin's configured AI model

## License

GPL-2.0-or-later

<?php
// File: wp-react-agent/features/external-apis.php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Log debug messages to WordPress error log
 */
function external_apis_feature_debug_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG === true) {
        error_log('External APIs Feature: ' . $message);
    }
}

/**
 * Register External APIs features with the Feature API.
 */
function wp_feature_api_external_apis_register_features() {
    external_apis_feature_debug_log('Registering External APIs features');
    
    // --- Feature 1: Search Art Institute of Chicago Artworks (Resource) ---
    wp_register_feature(
        array(
            'id'          => 'external/artic-search-artworks',
            'name'        => __( 'Search Art Institute of Chicago Artworks', 'wp-react-agent' ),
            'description' => __( 'Search for artworks in the Art Institute of Chicago API.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_RESOURCE,
            'categories'  => array( 'external', 'art', 'search' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'query' => array(
                        'type' => 'string',
                        'description' => __( 'Search query term.', 'wp-react-agent' ),
                        'required' => true,
                    ),
                    'page' => array(
                        'type' => 'integer',
                        'description' => __( 'Page number of results.', 'wp-react-agent' ),
                    ),
                    'limit' => array(
                        'type' => 'integer',
                        'description' => __( 'Number of results per page (max 100).', 'wp-react-agent' ),
                    ),
                ),
                'required' => array( 'query' ),
            ),
            'permission_callback' => function() {
                // Allow any logged in user to access this feature
                return is_user_logged_in();
            },
            'callback'    => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $query = isset( $context['query'] ) ? sanitize_text_field( $context['query'] ) : '';
                $page = isset( $context['page'] ) ? intval( $context['page'] ) : 1;
                $limit = isset( $context['limit'] ) ? intval( $context['limit'] ) : 10;
                
                // Validate input
                if ( empty( $query ) ) {
                    return new WP_Error( 'missing_query', __( 'Search query is required.', 'wp-react-agent' ), array( 'status' => 400 ) );
                }
                
                // Limit the maximum results per page
                if ( $limit > 100 ) {
                    $limit = 100;
                }
                
                // Calculate API offset based on page and limit
                $offset = ($page - 1) * $limit;
                
                // Build the API URL
                $api_url = add_query_arg(
                    array(
                        'q' => $query,
                        'limit' => $limit,
                        'page' => $page,
                    ),
                    'https://api.artic.edu/api/v1/artworks/search'
                );
                
                // Make the API request
                $response = wp_remote_get( $api_url );
                
                // Check for errors
                if ( is_wp_error( $response ) ) {
                    return new WP_Error(
                        'api_error',
                        __( 'Error connecting to Art Institute of Chicago API: ', 'wp-react-agent' ) . $response->get_error_message(),
                        array( 'status' => 500 )
                    );
                }
                
                // Check response code
                $response_code = wp_remote_retrieve_response_code( $response );
                if ( $response_code !== 200 ) {
                    return new WP_Error(
                        'api_error',
                        __( 'Art Institute of Chicago API returned an error: ', 'wp-react-agent' ) . $response_code,
                        array( 'status' => $response_code )
                    );
                }
                
                // Parse the response
                $body = wp_remote_retrieve_body( $response );
                $data = json_decode( $body, true );
                
                if ( json_last_error() !== JSON_ERROR_NONE ) {
                    return new WP_Error(
                        'api_error',
                        __( 'Error parsing API response: ', 'wp-react-agent' ) . json_last_error_msg(),
                        array( 'status' => 500 )
                    );
                }
                
                // Format the response
                $artworks = array();
                if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
                    foreach ( $data['data'] as $artwork ) {
                        $artworks[] = array(
                            'id' => $artwork['id'] ?? 0,
                            'title' => $artwork['title'] ?? '',
                            'thumbnail' => $artwork['thumbnail']['alt_text'] ?? '',
                            'api_link' => $artwork['api_link'] ?? '',
                        );
                    }
                }
                
                return array(
                    'query' => $query,
                    'page' => $page,
                    'limit' => $limit,
                    'total_results' => $data['pagination']['total'] ?? 0,
                    'total_pages' => $data['pagination']['total_pages'] ?? 0,
                    'artworks' => $artworks,
                );
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'query' => array( 'type' => 'string' ),
                    'page' => array( 'type' => 'integer' ),
                    'limit' => array( 'type' => 'integer' ),
                    'total_results' => array( 'type' => 'integer' ),
                    'total_pages' => array( 'type' => 'integer' ),
                    'artworks' => array(
                        'type' => 'array',
                        'items' => array(
                            'type' => 'object',
                            'properties' => array(
                                'id' => array( 'type' => 'integer' ),
                                'title' => array( 'type' => 'string' ),
                                'thumbnail' => array( 'type' => 'string' ),
                                'api_link' => array( 'type' => 'string' ),
                            ),
                        ),
                    ),
                ),
                'required' => array('query', 'artworks'),
            ),
        )
    );
    
    // --- Feature 2: Get Art Institute of Chicago Artwork Details (Resource) ---
    wp_register_feature(
        array(
            'id'          => 'external/artic-get-artwork',
            'name'        => __( 'Get Art Institute of Chicago Artwork Details', 'wp-react-agent' ),
            'description' => __( 'Retrieves detailed information about a specific artwork from the Art Institute of Chicago.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_RESOURCE,
            'categories'  => array( 'external', 'art', 'details' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'artwork_id' => array(
                        'type' => 'integer',
                        'description' => __( 'ID of the artwork to retrieve.', 'wp-react-agent' ),
                        'required' => true,
                    ),
                ),
                'required' => array( 'artwork_id' ),
            ),
            'permission_callback' => function() {
                // Allow any logged in user to access this feature
                return is_user_logged_in();
            },
            'callback'    => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $artwork_id = isset( $context['artwork_id'] ) ? intval( $context['artwork_id'] ) : 0;
                
                // Validate input
                if ( empty( $artwork_id ) ) {
                    return new WP_Error( 'missing_artwork_id', __( 'Artwork ID is required.', 'wp-react-agent' ), array( 'status' => 400 ) );
                }
                
                // Build the API URL
                $api_url = "https://api.artic.edu/api/v1/artworks/{$artwork_id}";
                
                // Make the API request
                $response = wp_remote_get( $api_url );
                
                // Check for errors
                if ( is_wp_error( $response ) ) {
                    return new WP_Error(
                        'api_error',
                        __( 'Error connecting to Art Institute of Chicago API: ', 'wp-react-agent' ) . $response->get_error_message(),
                        array( 'status' => 500 )
                    );
                }
                
                // Check response code
                $response_code = wp_remote_retrieve_response_code( $response );
                if ( $response_code !== 200 ) {
                    return new WP_Error(
                        'api_error',
                        __( 'Art Institute of Chicago API returned an error: ', 'wp-react-agent' ) . $response_code,
                        array( 'status' => $response_code )
                    );
                }
                
                // Parse the response
                $body = wp_remote_retrieve_body( $response );
                $data = json_decode( $body, true );
                
                if ( json_last_error() !== JSON_ERROR_NONE ) {
                    return new WP_Error(
                        'api_error',
                        __( 'Error parsing API response: ', 'wp-react-agent' ) . json_last_error_msg(),
                        array( 'status' => 500 )
                    );
                }
                
                // Format the response
                $artwork = array();
                if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
                    $artwork_data = $data['data'];
                    $artwork = array(
                        'id' => $artwork_data['id'] ?? 0,
                        'title' => $artwork_data['title'] ?? '',
                        'artist_title' => $artwork_data['artist_title'] ?? '',
                        'date_display' => $artwork_data['date_display'] ?? '',
                        'medium_display' => $artwork_data['medium_display'] ?? '',
                        'dimensions' => $artwork_data['dimensions'] ?? '',
                        'description' => $artwork_data['description'] ?? '',
                        'image_id' => $artwork_data['image_id'] ?? '',
                        'publication_history' => $artwork_data['publication_history'] ?? '',
                        'exhibition_history' => $artwork_data['exhibition_history'] ?? '',
                        'provenance_text' => $artwork_data['provenance_text'] ?? '',
                    );
                    
                    // Add image URL if image_id is present
                    if (!empty($artwork['image_id']) && isset($data['config']['iiif_url'])) {
                        $artwork['image_url'] = $data['config']['iiif_url'] . '/' . $artwork['image_id'] . '/full/843,/0/default.jpg';
                    }
                }
                
                return array(
                    'artwork' => $artwork,
                );
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'artwork' => array(
                        'type' => 'object',
                        'properties' => array(
                            'id' => array( 'type' => 'integer' ),
                            'title' => array( 'type' => 'string' ),
                            'artist_title' => array( 'type' => 'string' ),
                            'date_display' => array( 'type' => 'string' ),
                            'medium_display' => array( 'type' => 'string' ),
                            'dimensions' => array( 'type' => 'string' ),
                            'description' => array( 'type' => 'string' ),
                            'image_id' => array( 'type' => 'string' ),
                            'image_url' => array( 'type' => 'string' ),
                        ),
                    ),
                ),
                'required' => array('artwork'),
            ),
        )
    );
    
    // --- Feature 3: Get Weather by City (Resource) ---
    wp_register_feature(
        array(
            'id'          => 'external/openweather-current',
            'name'        => __( 'Get Current Weather', 'wp-react-agent' ),
            'description' => __( 'Retrieves current weather information for a specified city using OpenWeatherMap API.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_RESOURCE,
            'categories'  => array( 'external', 'weather', 'current' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'city' => array(
                        'type' => 'string',
                        'description' => __( 'City name to get weather for.', 'wp-react-agent' ),
                        'required' => true,
                    ),
                    'country_code' => array(
                        'type' => 'string',
                        'description' => __( 'Two-letter country code (ISO 3166).', 'wp-react-agent' ),
                    ),
                    'units' => array(
                        'type' => 'string',
                        'description' => __( 'Units of measurement. "metric" for Celsius, "imperial" for Fahrenheit.', 'wp-react-agent' ),
                        'enum' => array('standard', 'metric', 'imperial'),
                    ),
                ),
                'required' => array( 'city' ),
            ),
            'permission_callback' => function() {
                // Allow any logged in user to access this feature
                return is_user_logged_in();
            },
            'callback'    => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $city = isset( $context['city'] ) ? sanitize_text_field( $context['city'] ) : '';
                $country_code = isset( $context['country_code'] ) ? sanitize_text_field( $context['country_code'] ) : '';
                $units = isset( $context['units'] ) ? sanitize_text_field( $context['units'] ) : 'metric';
                
                // Validate input
                if ( empty( $city ) ) {
                    return new WP_Error( 'missing_city', __( 'City name is required.', 'wp-react-agent' ), array( 'status' => 400 ) );
                }
                
                // Get API key from options
                $api_key = get_option( 'openweather_api_key', '' );
                
                if ( empty( $api_key ) ) {
                    return new WP_Error( 
                        'missing_api_key', 
                        __( 'OpenWeatherMap API key is not configured. Please set it in the WordPress options.', 'wp-react-agent' ), 
                        array( 'status' => 500 ) 
                    );
                }
                
                // Build location query
                $location_query = $city;
                if ( !empty( $country_code ) ) {
                    $location_query .= ',' . $country_code;
                }
                
                // Build the API URL
                $api_url = add_query_arg(
                    array(
                        'q' => $location_query,
                        'units' => $units,
                        'appid' => $api_key,
                    ),
                    'https://api.openweathermap.org/data/2.5/weather'
                );
                
                // Make the API request
                $response = wp_remote_get( $api_url );
                
                // Check for errors
                if ( is_wp_error( $response ) ) {
                    return new WP_Error(
                        'api_error',
                        __( 'Error connecting to OpenWeatherMap API: ', 'wp-react-agent' ) . $response->get_error_message(),
                        array( 'status' => 500 )
                    );
                }
                
                // Check response code
                $response_code = wp_remote_retrieve_response_code( $response );
                if ( $response_code !== 200 ) {
                    return new WP_Error(
                        'api_error',
                        __( 'OpenWeatherMap API returned an error: ', 'wp-react-agent' ) . $response_code,
                        array( 'status' => $response_code )
                    );
                }
                
                // Parse the response
                $body = wp_remote_retrieve_body( $response );
                $data = json_decode( $body, true );
                
                if ( json_last_error() !== JSON_ERROR_NONE ) {
                    return new WP_Error(
                        'api_error',
                        __( 'Error parsing API response: ', 'wp-react-agent' ) . json_last_error_msg(),
                        array( 'status' => 500 )
                    );
                }
                
                // Format the response
                $weather = array(
                    'city' => $data['name'] ?? $city,
                    'country' => $data['sys']['country'] ?? $country_code,
                    'temperature' => $data['main']['temp'] ?? null,
                    'feels_like' => $data['main']['feels_like'] ?? null,
                    'humidity' => $data['main']['humidity'] ?? null,
                    'pressure' => $data['main']['pressure'] ?? null,
                    'weather_condition' => $data['weather'][0]['main'] ?? null,
                    'weather_description' => $data['weather'][0]['description'] ?? null,
                    'weather_icon' => isset($data['weather'][0]['icon']) ? 'https://openweathermap.org/img/wn/' . $data['weather'][0]['icon'] . '@2x.png' : null,
                    'wind_speed' => $data['wind']['speed'] ?? null,
                    'wind_direction' => $data['wind']['deg'] ?? null,
                    'clouds' => $data['clouds']['all'] ?? null,
                    'timestamp' => $data['dt'] ?? null,
                    'units' => $units,
                );
                
                return array(
                    'weather' => $weather,
                );
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'weather' => array(
                        'type' => 'object',
                        'properties' => array(
                            'city' => array( 'type' => 'string' ),
                            'country' => array( 'type' => 'string' ),
                            'temperature' => array( 'type' => 'number' ),
                            'feels_like' => array( 'type' => 'number' ),
                            'humidity' => array( 'type' => 'integer' ),
                            'pressure' => array( 'type' => 'integer' ),
                            'weather_condition' => array( 'type' => 'string' ),
                            'weather_description' => array( 'type' => 'string' ),
                            'weather_icon' => array( 'type' => 'string' ),
                            'wind_speed' => array( 'type' => 'number' ),
                            'wind_direction' => array( 'type' => 'integer' ),
                            'clouds' => array( 'type' => 'integer' ),
                            'timestamp' => array( 'type' => 'integer' ),
                            'units' => array( 'type' => 'string' ),
                        ),
                    ),
                ),
                'required' => array('weather'),
            ),
        )
    );
}

// Register with the centralized feature loader instead of the old hook
if (function_exists('wp_react_agent_register_feature_set')) {
    wp_react_agent_register_feature_set(
        'external-apis',                                // Unique ID
        'External APIs Features',                       // Label
        'wp_feature_api_external_apis_register_features', // Callback function
        array(
            'wp_register_feature',                      // Require Feature API
            'WP_Feature',                               // Require Feature API classes
            'wp_remote_get',                            // Require WordPress core HTTP functions
            'is_user_logged_in',
        )
    );
    external_apis_feature_debug_log('External APIs Features registered with loader');
} else {
     external_apis_feature_debug_log('Centralized loader not available, External API features will not be registered');
}

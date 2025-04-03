<?php
// WordPress Media API Features

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Log debug messages to WordPress error log
 */
function wp_media_feature_api_debug_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG === true) {
        error_log('WP Media Feature API: ' . $message);
    }
}

/**
 * Register WordPress Media features with the Feature API.
 */
function wp_feature_api_wp_media_register_features() {
    wp_media_feature_api_debug_log('Registering WordPress Media features');
    
    // --- Feature 1: List Media (Resource) ---
    wp_register_feature(
        array(
            'id'          => 'wp/list-media',
            'name'        => __( 'List Media Library Items', 'wp-react-agent' ),
            'description' => __( 'Retrieves a list of media items from the WordPress media library.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_RESOURCE,
            'categories'  => array( 'wp', 'media', 'list' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'per_page' => array(
                        'type' => 'integer',
                        'description' => __( 'Number of media items to retrieve per page.', 'wp-react-agent' ),
                    ),
                    'page' => array(
                        'type' => 'integer',
                        'description' => __( 'Page number for pagination.', 'wp-react-agent' ),
                    ),
                    'search' => array(
                        'type' => 'string',
                        'description' => __( 'Search term to filter media items.', 'wp-react-agent' ),
                    ),
                    'mime_type' => array(
                        'type' => 'string',
                        'description' => __( 'Filter by mime type (e.g., "image", "application/pdf").', 'wp-react-agent' ),
                    ),
                ),
            ),
            'permission_callback' => function() {
                return current_user_can( 'upload_files' );
            },
            'callback'    => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $per_page = isset($context['per_page']) ? intval($context['per_page']) : 20;
                $page = isset($context['page']) ? intval($context['page']) : 1;
                $search = isset($context['search']) ? sanitize_text_field($context['search']) : '';
                $mime_type = isset($context['mime_type']) ? sanitize_text_field($context['mime_type']) : '';
                
                $args = array(
                    'post_type'      => 'attachment',
                    'post_status'    => 'inherit',
                    'posts_per_page' => $per_page,
                    'paged'          => $page,
                );
                
                if (!empty($search)) {
                    $args['s'] = $search;
                }
                
                if (!empty($mime_type)) {
                    $args['post_mime_type'] = $mime_type;
                }
                
                $query = new WP_Query($args);
                $media_items = array();
                
                foreach ($query->posts as $post) {
                    $attachment_url = wp_get_attachment_url($post->ID);
                    $attachment_metadata = wp_get_attachment_metadata($post->ID);
                    $media_type = get_post_mime_type($post->ID);
                    
                    $media_items[] = array(
                        'id' => $post->ID,
                        'title' => $post->post_title,
                        'url' => $attachment_url,
                        'alt' => get_post_meta($post->ID, '_wp_attachment_image_alt', true),
                        'caption' => $post->post_excerpt,
                        'description' => $post->post_content,
                        'mime_type' => $media_type,
                        'date_created' => $post->post_date,
                        'metadata' => $attachment_metadata,
                    );
                }
                
                return array(
                    'media' => $media_items,
                    'total' => $query->found_posts,
                    'pages' => ceil($query->found_posts / $per_page),
                );
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'media' => array('type' => 'array'),
                    'total' => array('type' => 'integer'),
                    'pages' => array('type' => 'integer'),
                ),
                'required' => array('media', 'total', 'pages'),
            ),
        )
    );
    
    // --- Feature 2: Get Media (Resource) ---
    wp_register_feature(
        array(
            'id'          => 'wp/get-media',
            'name'        => __( 'Get Media Item', 'wp-react-agent' ),
            'description' => __( 'Retrieves details for a specific media item.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_RESOURCE,
            'categories'  => array( 'wp', 'media', 'get' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'media_id' => array(
                        'type' => 'integer',
                        'description' => __( 'ID of the media item to retrieve.', 'wp-react-agent' ),
                        'required' => true,
                    ),
                ),
                'required' => array('media_id'),
            ),
            'permission_callback' => function() {
                return current_user_can( 'upload_files' );
            },
            'callback'    => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $media_id = isset($context['media_id']) ? intval($context['media_id']) : 0;
                
                if (empty($media_id)) {
                    return new WP_Error('missing_media_id', __('Media ID is required.', 'wp-react-agent'), array('status' => 400));
                }
                
                $post = get_post($media_id);
                
                if (!$post || $post->post_type !== 'attachment') {
                    return new WP_Error('media_not_found', __('Media item not found.', 'wp-react-agent'), array('status' => 404));
                }
                
                $attachment_url = wp_get_attachment_url($post->ID);
                $attachment_metadata = wp_get_attachment_metadata($post->ID);
                $media_type = get_post_mime_type($post->ID);
                $sizes = array();
                
                if (strpos($media_type, 'image/') === 0 && isset($attachment_metadata['sizes'])) {
                    foreach ($attachment_metadata['sizes'] as $size_name => $size_data) {
                        $sizes[$size_name] = array(
                            'url' => wp_get_attachment_image_url($post->ID, $size_name),
                            'width' => $size_data['width'],
                            'height' => $size_data['height'],
                        );
                    }
                }
                
                return array(
                    'media' => array(
                        'id' => $post->ID,
                        'title' => $post->post_title,
                        'url' => $attachment_url,
                        'alt' => get_post_meta($post->ID, '_wp_attachment_image_alt', true),
                        'caption' => $post->post_excerpt,
                        'description' => $post->post_content,
                        'mime_type' => $media_type,
                        'date_created' => $post->post_date,
                        'metadata' => $attachment_metadata,
                        'sizes' => $sizes,
                    ),
                );
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'media' => array(
                        'type' => 'object',
                        'properties' => array(
                            'id' => array('type' => 'integer'),
                            'title' => array('type' => 'string'),
                            'url' => array('type' => 'string'),
                        ),
                    ),
                ),
                'required' => array('media'),
            ),
        )
    );
    
    // --- Feature 3: Upload Media (Tool) ---
    wp_register_feature(
        array(
            'id'          => 'wp/upload-media',
            'name'        => __( 'Upload Media', 'wp-react-agent' ),
            'description' => __( 'Uploads a file to the WordPress media library.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_TOOL,
            'categories'  => array( 'wp', 'media', 'upload' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'file_data' => array(
                        'type' => 'string',
                        'description' => __( 'Base64-encoded file data.', 'wp-react-agent' ),
                        'required' => true,
                    ),
                    'file_name' => array(
                        'type' => 'string',
                        'description' => __( 'Name of the file being uploaded.', 'wp-react-agent' ),
                        'required' => true,
                    ),
                    'title' => array(
                        'type' => 'string',
                        'description' => __( 'Title for the media item.', 'wp-react-agent' ),
                    ),
                    'caption' => array(
                        'type' => 'string',
                        'description' => __( 'Caption for the media item.', 'wp-react-agent' ),
                    ),
                    'alt_text' => array(
                        'type' => 'string',
                        'description' => __( 'Alternative text for the media item (images).', 'wp-react-agent' ),
                    ),
                ),
                'required' => array('file_data', 'file_name'),
            ),
            'permission_callback' => function() {
                return current_user_can( 'upload_files' );
            },
            'callback'    => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $file_data = isset($context['file_data']) ? $context['file_data'] : '';
                $file_name = isset($context['file_name']) ? sanitize_file_name($context['file_name']) : '';
                $title = isset($context['title']) ? sanitize_text_field($context['title']) : '';
                $caption = isset($context['caption']) ? sanitize_text_field($context['caption']) : '';
                $alt_text = isset($context['alt_text']) ? sanitize_text_field($context['alt_text']) : '';
                
                if (empty($file_data) || empty($file_name)) {
                    return new WP_Error('missing_file_data', __('File data and file name are required.', 'wp-react-agent'), array('status' => 400));
                }
                
                // Ensure we have the required files for media upload
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
                
                // Decode base64 data
                $decoded_data = base64_decode($file_data);
                if ($decoded_data === false) {
                    return new WP_Error('invalid_file_data', __('Invalid file data. Expected base64 encoded string.', 'wp-react-agent'), array('status' => 400));
                }
                
                // Create temporary file
                $temp_file = wp_tempnam($file_name);
                if (!$temp_file) {
                    return new WP_Error('temp_file_error', __('Could not create temporary file.', 'wp-react-agent'), array('status' => 500));
                }
                
                // Write data to temporary file
                if (file_put_contents($temp_file, $decoded_data) === false) {
                    return new WP_Error('file_write_error', __('Could not write to temporary file.', 'wp-react-agent'), array('status' => 500));
                }
                
                // Create file data array for wp_handle_sideload
                $file = array(
                    'name'     => $file_name,
                    'type'     => mime_content_type($temp_file),
                    'tmp_name' => $temp_file,
                    'error'    => 0,
                    'size'     => filesize($temp_file),
                );
                
                // Move temporary file to uploads directory
                $sideload = wp_handle_sideload($file, array('test_form' => false));
                
                if (!empty($sideload['error'])) {
                    @unlink($temp_file);
                    return new WP_Error('upload_error', $sideload['error'], array('status' => 500));
                }
                
                // Create attachment post for the media item
                $attachment = array(
                    'post_mime_type' => $sideload['type'],
                    'post_title'     => !empty($title) ? $title : pathinfo($file_name, PATHINFO_FILENAME),
                    'post_content'   => '',
                    'post_excerpt'   => $caption,
                    'post_status'    => 'inherit',
                );
                
                $attachment_id = wp_insert_attachment($attachment, $sideload['file']);
                
                if (is_wp_error($attachment_id)) {
                    @unlink($sideload['file']);
                    return $attachment_id;
                }
                
                // Generate attachment metadata
                $attachment_data = wp_generate_attachment_metadata($attachment_id, $sideload['file']);
                wp_update_attachment_metadata($attachment_id, $attachment_data);
                
                // Set alt text if provided
                if (!empty($alt_text)) {
                    update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
                }
                
                return array(
                    'success' => true,
                    'media_id' => $attachment_id,
                    'url' => wp_get_attachment_url($attachment_id),
                    'title' => get_the_title($attachment_id),
                    'mime_type' => get_post_mime_type($attachment_id),
                );
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'success' => array('type' => 'boolean'),
                    'media_id' => array('type' => 'integer'),
                    'url' => array('type' => 'string'),
                    'title' => array('type' => 'string'),
                    'mime_type' => array('type' => 'string'),
                ),
                'required' => array('success', 'media_id', 'url'),
            ),
        )
    );
    
    // --- Feature 4: Delete Media (Tool) ---
    wp_register_feature(
        array(
            'id'          => 'wp/delete-media',
            'name'        => __( 'Delete Media Item', 'wp-react-agent' ),
            'description' => __( 'Deletes a media item from the WordPress media library.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_TOOL,
            'categories'  => array( 'wp', 'media', 'delete' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'media_id' => array(
                        'type' => 'integer',
                        'description' => __( 'ID of the media item to delete.', 'wp-react-agent' ),
                        'required' => true,
                    ),
                    'force_delete' => array(
                        'type' => 'boolean',
                        'description' => __( 'Whether to bypass trash and force deletion.', 'wp-react-agent' ),
                    ),
                ),
                'required' => array('media_id'),
            ),
            'permission_callback' => function() {
                return current_user_can( 'delete_posts' ) && current_user_can( 'upload_files' );
            },
            'callback'    => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $media_id = isset($context['media_id']) ? intval($context['media_id']) : 0;
                $force_delete = isset($context['force_delete']) ? (bool) $context['force_delete'] : false;
                
                if (empty($media_id)) {
                    return new WP_Error('missing_media_id', __('Media ID is required.', 'wp-react-agent'), array('status' => 400));
                }
                
                $post = get_post($media_id);
                
                if (!$post || $post->post_type !== 'attachment') {
                    return new WP_Error('media_not_found', __('Media item not found.', 'wp-react-agent'), array('status' => 404));
                }
                
                // Store information before deletion
                $title = get_the_title($media_id);
                
                // Delete the attachment
                $result = wp_delete_attachment($media_id, $force_delete);
                
                if (!$result) {
                    return new WP_Error('delete_failed', __('Failed to delete media item.', 'wp-react-agent'), array('status' => 500));
                }
                
                return array(
                    'success' => true,
                    'media_id' => $media_id,
                    'title' => $title,
                    'message' => __('Media item deleted successfully.', 'wp-react-agent'),
                );
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'success' => array('type' => 'boolean'),
                    'media_id' => array('type' => 'integer'),
                    'title' => array('type' => 'string'),
                    'message' => array('type' => 'string'),
                ),
                'required' => array('success', 'media_id', 'message'),
            ),
        )
    );
    
    // --- Feature 5: Add Media From URL (Tool) --- NEW ---
    wp_register_feature(
        array(
            'id'          => 'wp/add-media-from-url',
            'name'        => __( 'Add Media From URL', 'wp-react-agent' ),
            'description' => __( 'Downloads an image from a URL and adds it to the media library, if it doesn\'t already exist.', 'wp-react-agent' ),
            'type'        => WP_Feature::TYPE_TOOL,
            'categories'  => array( 'wp', 'media', 'add', 'url' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'image_url' => array(
                        'type' => 'string',
                        'format' => 'uri',
                        'description' => __( 'URL of the image to download.', 'wp-react-agent' ),
                        'required' => true,
                    ),
                    'title' => array(
                        'type' => 'string',
                        'description' => __( 'Optional title for the media item.', 'wp-react-agent' ),
                    ),
                    'caption' => array(
                        'type' => 'string',
                        'description' => __( 'Optional caption for the media item.', 'wp-react-agent' ),
                    ),
                    'alt_text' => array(
                        'type' => 'string',
                        'description' => __( 'Optional alternative text for the media item.', 'wp-react-agent' ),
                    ),
                ),
                'required' => array('image_url'),
            ),
            'permission_callback' => function() {
                return current_user_can( 'upload_files' );
            },
            'callback'    => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $image_url = isset($context['image_url']) ? esc_url_raw(trim($context['image_url'])) : '';
                $title = isset($context['title']) ? sanitize_text_field($context['title']) : '';
                $caption = isset($context['caption']) ? sanitize_text_field($context['caption']) : '';
                $alt_text = isset($context['alt_text']) ? sanitize_text_field($context['alt_text']) : '';
                
                if ( empty($image_url) || !filter_var($image_url, FILTER_VALIDATE_URL) ) {
                    return new WP_Error('invalid_image_url', __('A valid image URL is required.', 'wp-react-agent'), array('status' => 400));
                }
                
                // Ensure we have the required files
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';

                // Check if image from this URL already exists
                $existing_query = new WP_Query( array(
                    'post_type' => 'attachment',
                    'post_status' => 'inherit',
                    'meta_key' => '_wp_attachment_source_url',
                    'meta_value' => $image_url,
                    'posts_per_page' => 1,
                ) );

                if ( $existing_query->have_posts() ) {
                    $existing_attachment_id = $existing_query->posts[0]->ID;
                    wp_media_feature_api_debug_log("Existing attachment found for URL {$image_url}: ID {$existing_attachment_id}");
                    return array(
                        'success' => true,
                        'media_id' => $existing_attachment_id,
                        'url' => wp_get_attachment_url($existing_attachment_id),
                        'title' => get_the_title($existing_attachment_id),
                        'mime_type' => get_post_mime_type($existing_attachment_id),
                        'message' => __('Media item already exists in library.', 'wp-react-agent'),
                        'existed' => true, // Flag to indicate it wasn't newly added
                    );
                }

                // Download image from URL to temporary file
                $temp_file = download_url($image_url);
                
                if ( is_wp_error($temp_file) ) {
                    @unlink($temp_file); // Necessary cleanup
                    return new WP_Error('download_failed', __('Failed to download image from URL: ', 'wp-react-agent') . $temp_file->get_error_message(), array('status' => 500));
                }
                
                // Get filename from URL
                $file_name = basename(parse_url($image_url, PHP_URL_PATH));
                if (empty($file_name)) {
                     $file_name = 'downloaded_image_' . time(); // Fallback filename
                }
                
                // Prepare file data array for media_handle_sideload
                $file_array = array(
                    'name'     => sanitize_file_name($file_name),
                    'type'     => mime_content_type($temp_file), // Get mime type from temp file
                    'tmp_name' => $temp_file,
                    'error'    => 0,
                    'size'     => filesize($temp_file),
                );
                
                // Sideload the image (adds to media library)
                // The second argument (0) means the image is not attached to any post
                $attachment_id = media_handle_sideload($file_array, 0, $title ?: null);
                
                // Check for errors during sideloading
                if ( is_wp_error($attachment_id) ) {
                    @unlink($temp_file); // Necessary cleanup
                    return new WP_Error('sideload_failed', __('Failed to add image to media library: ', 'wp-react-agent') . $attachment_id->get_error_message(), array('status' => 500));
                }

                wp_media_feature_api_debug_log("Media item added from URL {$image_url}: ID {$attachment_id}");

                // Store the original URL as meta data
                update_post_meta($attachment_id, '_wp_attachment_source_url', $image_url);

                // Update post data if caption or title were provided
                 $update_data = array();
                 if (!empty($title)) $update_data['post_title'] = $title;
                 if (!empty($caption)) $update_data['post_excerpt'] = $caption;
                 if (!empty($update_data)) {
                    $update_data['ID'] = $attachment_id;
                    wp_update_post($update_data);
                 }
                
                // Update alt text if provided
                if (!empty($alt_text)) {
                    update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
                }
                
                // Return details of the new attachment
                return array(
                    'success' => true,
                    'media_id' => $attachment_id,
                    'url' => wp_get_attachment_url($attachment_id),
                    'title' => get_the_title($attachment_id),
                    'mime_type' => get_post_mime_type($attachment_id),
                    'message' => __('Media item added successfully from URL.', 'wp-react-agent'),
                    'existed' => false, // Flag to indicate it was newly added
                );
            },
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'success' => array('type' => 'boolean'),
                    'media_id' => array('type' => 'integer'),
                    'url' => array('type' => 'string'),
                    'title' => array('type' => 'string'),
                    'mime_type' => array('type' => 'string'),
                    'message' => array('type' => 'string'),
                    'existed' => array('type' => 'boolean', 'description' => 'True if the media item already existed, false if newly added.'),
                ),
                'required' => array('success', 'media_id', 'url', 'message', 'existed'),
            ),
        )
    );
}

// Register with the centralized feature loader
if (function_exists('wp_react_agent_register_feature_set')) {
    wp_react_agent_register_feature_set(
        'wp-media',                                 // Unique ID
        'WordPress Media Features',                 // Label
        'wp_feature_api_wp_media_register_features', // Callback function
        array(
            'wp_register_feature',                  // Require Feature API
            'WP_Feature',                           // Require Feature API classes
            'wp_get_attachment_url',                // Require WordPress core functions
            'media_handle_sideload',                // Needed for adding from URL
            'download_url',                         // Needed for adding from URL
            'WP_Query',                             // Needed for checking existing URL
        )
    );
    wp_media_feature_api_debug_log('WordPress Media Features registered with loader');
} 
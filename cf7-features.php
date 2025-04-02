<?php
// --- Code Snippet: Feature API - Contact Form 7 Bridge (Expanded) ---

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Register Contact Form 7 features with the Feature API.
 *
 * This function runs on the 'init' hook to ensure both CF7 and Feature API
 * functions and classes are available.
 */
function wp_feature_api_cf7_register_features() {
	// --- Check if Dependencies are Active ---
	if ( ! function_exists( 'wp_register_feature' ) || ! class_exists( 'WP_Feature' ) || ! class_exists( 'WPCF7_ContactForm' ) ) {
        // Log error or add a transient admin notice if needed, as direct notices might not fire here
		error_log('Feature API - CF7 Bridge: Missing required plugin (Feature API or Contact Form 7).');
		return;
	}

	// === RESOURCES (Getting Data) ===

	// --- Feature 1: List Contact Forms (Resource) ---
	wp_register_feature(
		array(
			'id'          => 'cf7/list-forms',
			'name'        => __( 'List Contact Form 7 Forms', 'wp-feature-api-cf7-bridge' ),
			'description' => __( 'Retrieves a list of available Contact Form 7 forms with their IDs and titles.', 'wp-feature-api-cf7-bridge' ),
			'type'        => WP_Feature::TYPE_RESOURCE,
			'categories'  => array( 'cf7', 'forms', 'list' ),
			'permission_callback' => function() {
				return current_user_can( 'wpcf7_read_contact_forms' );
			},
			'callback'    => function( $request ) {
				$forms = WPCF7_ContactForm::find( array( 'posts_per_page' => -1 ) );
				$output = array();

				foreach ( $forms as $form ) {
					$output[] = array(
						'id'    => $form->id(),
						'title' => $form->title(),
						'slug'  => $form->name(),
					);
				}
				return $output;
			},
            'output_schema' => array(
                'type' => 'array',
                'items' => array(
                    'type' => 'object',
                    'properties' => array(
                        'id'    => array( 'type' => 'integer', 'description' => 'Form ID' ),
                        'title' => array( 'type' => 'string', 'description' => 'Form Title' ),
                        'slug'  => array( 'type' => 'string', 'description' => 'Form Slug' ),
                    ),
                    'required' => array('id', 'title', 'slug'),
                ),
                'description' => 'A list of Contact Form 7 forms.',
            ),
		)
	);

	// --- Feature 2: Get Specific Contact Form Details (Resource) ---
	wp_register_feature(
		array(
			'id'          => 'cf7/get-form',
			'name'        => __( 'Get Contact Form 7 Details', 'wp-feature-api-cf7-bridge' ),
			'description' => __( 'Retrieves the configuration details for a specific Contact Form 7 form by its ID.', 'wp-feature-api-cf7-bridge' ),
			'type'        => WP_Feature::TYPE_RESOURCE,
			'categories'  => array( 'cf7', 'forms', 'details', 'get' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'form_id' => array(
                        'type' => 'integer',
                        'description' => __( 'The ID of the Contact Form 7 form.', 'wp-feature-api-cf7-bridge' ),
                        'required' => true,
                    ),
                ),
                'required' => array( 'form_id' ),
            ),
			'permission_callback' => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $form_id = isset( $context['form_id'] ) ? (int) $context['form_id'] : 0;
                if ( ! $form_id ) return new WP_Error('missing_form_id', __('Form ID is required.', 'wp-feature-api-cf7-bridge'), array('status' => 400));
				// Use specific capability check if possible, otherwise broader edit cap might be needed for details
				return current_user_can( 'wpcf7_read_contact_form', $form_id ) || current_user_can( 'wpcf7_edit_contact_form', $form_id );
			},
			'callback'    => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $form_id = isset( $context['form_id'] ) ? (int) $context['form_id'] : 0;

                if ( ! $form_id ) { // Already checked in permission, but good practice
                    return new WP_Error( 'missing_form_id', __( 'Form ID is required.', 'wp-feature-api-cf7-bridge' ), array( 'status' => 400 ) );
                }

				$contact_form = wpcf7_contact_form( $form_id );

				if ( ! $contact_form ) {
					return new WP_Error( 'cf7_not_found', __( 'Contact form not found.', 'wp-feature-api-cf7-bridge' ), array( 'status' => 404 ) );
				}

                $properties = $contact_form->get_properties();

				return array(
                    'id' => $contact_form->id(),
                    'title' => $contact_form->title(),
                    'locale' => $contact_form->locale(),
                    'form_content' => $properties['form'] ?? '',
                    'mail_settings' => $properties['mail'] ?? array(),
                    'mail_2_settings' => $properties['mail_2'] ?? array(),
                    'messages_settings' => $properties['messages'] ?? array(),
                    'additional_settings_content' => $properties['additional_settings'] ?? '',
                    // Consider adding Flamingo/Sendinblue/etc. properties if needed
                );
			},
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'id' => array( 'type' => 'integer' ),
                    'title' => array( 'type' => 'string' ),
                    'locale' => array( 'type' => 'string' ),
                    'form_content' => array( 'type' => 'string', 'description' => 'Form template content (shortcodes).' ),
                    'mail_settings' => array( 'type' => 'object', 'description' => 'Primary mail settings.' ),
                    'mail_2_settings' => array( 'type' => 'object', 'description' => 'Secondary mail (Mail 2) settings.' ),
                    'messages_settings' => array( 'type' => 'object', 'description' => 'Form feedback messages.' ),
                    'additional_settings_content' => array( 'type' => 'string', 'description' => 'Additional settings content.' ),
                ),
                 'required' => array('id', 'title'), // ID and Title should always exist
            ),
		)
	);


	// === TOOLS (Performing Actions) ===

	// --- Feature 3: Create Contact Form (Tool) ---
	wp_register_feature(
		array(
			'id'          => 'cf7/create-form',
			'name'        => __( 'Create Contact Form 7', 'wp-feature-api-cf7-bridge' ),
			'description' => __( 'Creates a new Contact Form 7 form, optionally with a title and locale.', 'wp-feature-api-cf7-bridge' ),
			'type'        => WP_Feature::TYPE_TOOL,
			'categories'  => array( 'cf7', 'forms', 'create' ),
			'input_schema' => array(
				'type' => 'object',
				'properties' => array(
					'title' => array(
						'type' => 'string',
						'description' => __( 'The title for the new form.', 'wp-feature-api-cf7-bridge' ),
						'required' => true,
					),
					'locale' => array(
						'type' => 'string',
						'description' => __( 'Optional locale code (e.g., "ja", "es") for the default template.', 'wp-feature-api-cf7-bridge' ),
					),
				),
				'required' => array( 'title' ),
			),
			'permission_callback' => function() {
				return current_user_can( 'wpcf7_edit_contact_forms' ); // Capability to create new forms
			},
			'callback'    => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $title = trim( $context['title'] ?? '' );
                $locale = trim( $context['locale'] ?? null ); // null to let CF7 decide default

                if ( empty( $title ) ) {
                    return new WP_Error( 'missing_title', __( 'Form title cannot be empty.', 'wp-feature-api-cf7-bridge' ), array( 'status' => 400 ) );
                }

				$new_form = WPCF7_ContactForm::get_template( array(
                    'title' => $title,
                    'locale' => $locale,
                ) );

				$result = $new_form->save(); // Returns post ID or 0/false

                if ( ! $result ) {
                     return new WP_Error( 'cf7_create_failed', __( 'Failed to create contact form.', 'wp-feature-api-cf7-bridge' ), array( 'status' => 500 ) );
                }

				return array(
                    'success' => true,
                    'form_id' => $new_form->id(), // ID is set after successful save
                    'title'   => $new_form->title(),
                    'message' => __( 'Contact form created successfully.', 'wp-feature-api-cf7-bridge' )
                );
			},
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'success' => array( 'type' => 'boolean' ),
                    'form_id' => array( 'type' => 'integer' ),
                    'title'   => array( 'type' => 'string' ),
                    'message' => array( 'type' => 'string' ),
                ),
                'required' => array('success', 'form_id', 'message'),
            ),
		)
	);


    // --- Feature 4: Update Contact Form (Tool - Enhanced) ---
	wp_register_feature(
		array(
			'id'          => 'cf7/update-form',
			'name'        => __( 'Update Contact Form 7', 'wp-feature-api-cf7-bridge' ),
			'description' => __( 'Updates the configuration (title, form content, mail settings, etc.) for a specific Contact Form 7 form.', 'wp-feature-api-cf7-bridge' ),
			'type'        => WP_Feature::TYPE_TOOL,
			'categories'  => array( 'cf7', 'forms', 'update', 'edit' ),
			'input_schema' => array(
				'type' => 'object',
				'properties' => array(
					'form_id' => array(
						'type' => 'integer',
						'description' => __( 'The ID of the Contact Form 7 form to update.', 'wp-feature-api-cf7-bridge' ),
						'required' => true,
					),
                    'title' => array(
                        'type' => 'string',
                        'description' => __( 'Optional. New title for the form.', 'wp-feature-api-cf7-bridge' ),
                    ),
                    'locale' => array(
                        'type' => 'string',
                        'description' => __( 'Optional. New locale for the form.', 'wp-feature-api-cf7-bridge' ),
                    ),
                    'form_content' => array(
                        'type' => 'string',
                        'description' => __( 'Optional. New form template content (shortcodes).', 'wp-feature-api-cf7-bridge' ),
                    ),
                    'mail_settings' => array(
                        'type' => 'object',
                        'description' => __( 'Optional. New primary mail settings object.', 'wp-feature-api-cf7-bridge' ),
                        // Further define properties like subject, recipient, body if needed for clarity
                    ),
                    'mail_2_settings' => array(
                        'type' => 'object',
                        'description' => __( 'Optional. New secondary mail (Mail 2) settings object.', 'wp-feature-api-cf7-bridge' ),
                    ),
                    'messages_settings' => array(
                        'type' => 'object',
                        'description' => __( 'Optional. New form feedback messages object.', 'wp-feature-api-cf7-bridge' ),
                    ),
                    'additional_settings_content' => array(
                        'type' => 'string',
                        'description' => __( 'Optional. New additional settings content.', 'wp-feature-api-cf7-bridge' ),
                    ),
				),
				'required' => array( 'form_id' ), // Only form_id is strictly required to identify the form
			),
			'permission_callback' => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $form_id = isset( $context['form_id'] ) ? (int) $context['form_id'] : 0;
                if ( ! $form_id ) return new WP_Error('missing_form_id', __('Form ID is required.', 'wp-feature-api-cf7-bridge'), array('status' => 400));
				return current_user_can( 'wpcf7_edit_contact_form', $form_id );
			},
			'callback'    => function( $request ) {
                $context = $request->get_param( 'context' );
                $form_id = isset( $context['form_id'] ) ? (int) $context['form_id'] : 0;

                if ( ! $form_id ) { // Already checked, but good practice
                    return new WP_Error( 'missing_form_id', __( 'Form ID is required.', 'wp-feature-api-cf7-bridge' ), array( 'status' => 400 ) );
                }

                $contact_form = wpcf7_contact_form( $form_id );

				if ( ! $contact_form ) {
					return new WP_Error( 'cf7_not_found', __( 'Contact form not found.', 'wp-feature-api-cf7-bridge' ), array( 'status' => 404 ) );
				}

                // Prepare data for wpcf7_save_contact_form, only including fields present in context
                $save_data = array( 'id' => $form_id );

                if ( isset( $context['title'] ) ) $save_data['title'] = $context['title'];
                if ( isset( $context['locale'] ) ) $save_data['locale'] = $context['locale'];
                if ( isset( $context['form_content'] ) ) $save_data['form'] = $context['form_content'];
                if ( isset( $context['mail_settings'] ) ) $save_data['mail'] = $context['mail_settings'];
                if ( isset( $context['mail_2_settings'] ) ) $save_data['mail_2'] = $context['mail_2_settings'];
                if ( isset( $context['messages_settings'] ) ) $save_data['messages'] = $context['messages_settings'];
                if ( isset( $context['additional_settings_content'] ) ) $save_data['additional_settings'] = $context['additional_settings_content'];

                // Use context 'save' to trigger postmeta updates etc.
                $updated_form = wpcf7_save_contact_form( $save_data, 'save' );

                if ( ! $updated_form ) {
                     return new WP_Error( 'cf7_update_failed', __( 'Failed to update contact form.', 'wp-feature-api-cf7-bridge' ), array( 'status' => 500 ) );
                }

				return array(
                    'success' => true,
                    'form_id' => $updated_form->id(),
                    'message' => __( 'Contact form updated successfully.', 'wp-feature-api-cf7-bridge' )
                );
			},
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'success' => array( 'type' => 'boolean' ),
                    'form_id' => array( 'type' => 'integer' ),
                    'message' => array( 'type' => 'string' ),
                ),
                'required' => array('success', 'form_id', 'message'),
            ),
		)
	);

	// --- Feature 5: Delete Contact Form (Tool) ---
	wp_register_feature(
		array(
			'id'          => 'cf7/delete-form',
			'name'        => __( 'Delete Contact Form 7', 'wp-feature-api-cf7-bridge' ),
			'description' => __( 'Deletes a specific Contact Form 7 form by its ID.', 'wp-feature-api-cf7-bridge' ),
			'type'        => WP_Feature::TYPE_TOOL,
			'categories'  => array( 'cf7', 'forms', 'delete' ),
			'input_schema' => array(
				'type' => 'object',
				'properties' => array(
					'form_id' => array(
						'type' => 'integer',
						'description' => __( 'The ID of the Contact Form 7 form to delete.', 'wp-feature-api-cf7-bridge' ),
						'required' => true,
					),
				),
				'required' => array( 'form_id' ),
			),
			'permission_callback' => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $form_id = isset( $context['form_id'] ) ? (int) $context['form_id'] : 0;
                if ( ! $form_id ) return new WP_Error('missing_form_id', __('Form ID is required.', 'wp-feature-api-cf7-bridge'), array('status' => 400));
				return current_user_can( 'wpcf7_delete_contact_form', $form_id );
			},
			'callback'    => function( $request ) {
                $context = $request->get_param('context');
                $form_id = (int) $context['form_id'];

				$contact_form = wpcf7_contact_form( $form_id );

				if ( ! $contact_form ) {
					return new WP_Error( 'cf7_not_found', __( 'Contact form not found.', 'wp-feature-api-cf7-bridge' ), array( 'status' => 404 ) );
				}

                $result = $contact_form->delete(); // Returns true on success, false on failure

                if ( ! $result ) {
                     return new WP_Error( 'cf7_delete_failed', __( 'Failed to delete contact form.', 'wp-feature-api-cf7-bridge' ), array( 'status' => 500 ) );
                }

				return array(
                    'success' => true,
                    'message' => __( 'Contact form deleted successfully.', 'wp-feature-api-cf7-bridge' )
                );
			},
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'success' => array( 'type' => 'boolean' ),
                    'message' => array( 'type' => 'string' ),
                ),
                'required' => array('success', 'message'),
            ),
		)
	);

	// --- Feature 6: Duplicate Contact Form (Tool) ---
	wp_register_feature(
		array(
			'id'          => 'cf7/duplicate-form',
			'name'        => __( 'Duplicate Contact Form 7', 'wp-feature-api-cf7-bridge' ),
			'description' => __( 'Creates a copy of an existing Contact Form 7 form.', 'wp-feature-api-cf7-bridge' ),
			'type'        => WP_Feature::TYPE_TOOL,
			'categories'  => array( 'cf7', 'forms', 'duplicate', 'copy' ),
			'input_schema' => array(
				'type' => 'object',
				'properties' => array(
					'source_form_id' => array(
						'type' => 'integer',
						'description' => __( 'The ID of the Contact Form 7 form to duplicate.', 'wp-feature-api-cf7-bridge' ),
						'required' => true,
					),
					'new_title' => array(
						'type' => 'string',
						'description' => __( 'Optional. Title for the new duplicated form. Defaults to "[Original Title]_copy".', 'wp-feature-api-cf7-bridge' ),
					),
				),
				'required' => array( 'source_form_id' ),
			),
			'permission_callback' => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $source_form_id = isset( $context['source_form_id'] ) ? (int) $context['source_form_id'] : 0;
                if ( ! $source_form_id ) return new WP_Error('missing_source_form_id', __('Source Form ID is required.', 'wp-feature-api-cf7-bridge'), array('status' => 400));

                // Need permission to read the source and create a new one
				return current_user_can( 'wpcf7_edit_contact_form', $source_form_id ) && current_user_can( 'wpcf7_edit_contact_forms' );
			},
			'callback'    => function( $request ) {
                $context = $request->get_param('context');
                $source_form_id = (int) $context['source_form_id'];
                $new_title = isset( $context['new_title'] ) ? trim( $context['new_title'] ) : null;

				$source_contact_form = wpcf7_contact_form( $source_form_id );

				if ( ! $source_contact_form ) {
					return new WP_Error( 'cf7_source_not_found', __( 'Source contact form not found.', 'wp-feature-api-cf7-bridge' ), array( 'status' => 404 ) );
				}

                $new_form = $source_contact_form->copy();

                if ( ! empty( $new_title ) ) {
                    $new_form->set_title( $new_title );
                }
                // CF7's copy() method already appends '_copy' if no new title is set

                $result = $new_form->save(); // Returns post ID or 0/false

                if ( ! $result ) {
                     return new WP_Error( 'cf7_duplicate_failed', __( 'Failed to duplicate contact form.', 'wp-feature-api-cf7-bridge' ), array( 'status' => 500 ) );
                }

				return array(
                    'success' => true,
                    'new_form_id' => $new_form->id(),
                    'new_title' => $new_form->title(),
                    'message' => __( 'Contact form duplicated successfully.', 'wp-feature-api-cf7-bridge' )
                );
			},
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'success' => array( 'type' => 'boolean' ),
                    'new_form_id' => array( 'type' => 'integer', 'description' => 'ID of the newly created form.' ),
                    'new_title' => array( 'type' => 'string', 'description' => 'Title of the newly created form.' ),
                    'message' => array( 'type' => 'string' ),
                ),
                'required' => array('success', 'new_form_id', 'new_title', 'message'),
            ),
		)
	);

} // end function wp_feature_api_cf7_register_features

// Hook the registration function into WordPress initialization
add_action( 'init', 'wp_feature_api_cf7_register_features', 20 ); // Run after CF7 and Feature API likely init 
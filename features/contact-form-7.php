<?php
// File: wp-react-agent/features/contact-form-7.php

use Felix_Arntz\AI_Services\Services\API\Enums\AI_Capability;
use Felix_Arntz\AI_Services\Services\API\Enums\Content_Role;
use Felix_Arntz\AI_Services\Services\API\Helpers;
use Felix_Arntz\AI_Services\Services\AI_Service_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Log debug messages to WordPress error log
 */
function cf7_feature_api_debug_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG === true) {
        error_log('CF7 Feature API: ' . $message);
    }
}

/**
 * Register Contact Form 7 features with the Feature API.
 */
function wp_feature_api_cf7_register_features() {
	// These checks are now handled by the centralized loader
	cf7_feature_api_debug_log('Registering CF7 features');

	// === RESOURCES (Getting Data) ===

	// --- Feature 1: List Contact Forms (Resource) ---
	wp_register_feature(
		array(
			'id'          => 'cf7/list-forms',
			'name'        => __( 'List Contact Form 7 Forms', 'wp-feature-api-cf7-bridge' ),
			'description' => __( 'Retrieves a list of available Contact Form 7 forms with their IDs and titles.', 'wp-feature-api-cf7-bridge' ),
			'type'        => WP_Feature::TYPE_RESOURCE,
			'categories'  => array( 'cf7', 'forms', 'list' ),
			'input_schema' => array( // Add schema for clarity, even if empty
                'type' => 'object',
                'properties' => array(),
                'description' => 'No input arguments needed.',
            ),
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
			'description' => __( 'Retrieves the configuration details (form template, mail settings, etc.) for a specific Contact Form 7 form by its ID.', 'wp-feature-api-cf7-bridge' ),
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
				return current_user_can( 'wpcf7_read_contact_form', $form_id ) || current_user_can( 'wpcf7_edit_contact_form', $form_id );
			},
			'callback'    => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $form_id = isset( $context['form_id'] ) ? (int) $context['form_id'] : 0;

                if ( ! $form_id ) {
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
                 'required' => array('id', 'title'),
            ),
		)
	);


	// === TOOLS (Performing Actions) ===

	// --- Feature 3: Create Basic Contact Form (Tool) ---
	wp_register_feature(
		array(
			'id'          => 'cf7/create-basic-form', // Renamed for clarity
			'name'        => __( 'Create Basic Contact Form 7', 'wp-feature-api-cf7-bridge' ),
			'description' => __( 'Creates a new Contact Form 7 form using the default template, optionally with a title and locale.', 'wp-feature-api-cf7-bridge' ),
			'type'        => WP_Feature::TYPE_TOOL,
			'categories'  => array( 'cf7', 'forms', 'create', 'basic' ),
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
				return current_user_can( 'wpcf7_edit_contact_forms' );
			},
			'callback'    => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $title = trim( $context['title'] ?? '' );
                $locale = trim( $context['locale'] ?? null );

                if ( empty( $title ) ) {
                    return new WP_Error( 'missing_title', __( 'Form title cannot be empty.', 'wp-feature-api-cf7-bridge' ), array( 'status' => 400 ) );
                }

				$new_form = WPCF7_ContactForm::get_template( array(
                    'title' => $title,
                    'locale' => $locale,
                ) );

				$result = $new_form->save();

                if ( ! $result ) {
                     return new WP_Error( 'cf7_create_failed', __( 'Failed to create contact form.', 'wp-feature-api-cf7-bridge' ), array( 'status' => 500 ) );
                }

				return array(
                    'success' => true,
                    'form_id' => $new_form->id(),
                    'title'   => $new_form->title(),
                    'message' => __( 'Basic contact form created successfully using default template.', 'wp-feature-api-cf7-bridge' )
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

    // --- Feature 4: Generate Full Contact Form (Tool) --- NEW ---
	wp_register_feature(
		array(
			'id'          => 'cf7/generate-full-form',
			'name'        => __( 'Generate Full Contact Form 7', 'wp-feature-api-cf7-bridge' ),
			'description' => __( 'Generates and creates a new Contact Form 7 form (including form fields, mail settings, etc.) based on a provided description using AI.', 'wp-feature-api-cf7-bridge' ),
			'type'        => WP_Feature::TYPE_TOOL,
			'categories'  => array( 'cf7', 'forms', 'create', 'generate', 'ai' ),
			'input_schema' => array(
				'type' => 'object',
				'properties' => array(
					'description' => array(
						'type' => 'string',
						'description' => __( 'A detailed description of the form needed (e.g., "A simple contact form with name, email, subject, and message fields. Send email notifications to admin@example.com").', 'wp-feature-api-cf7-bridge' ),
						'required' => true,
					),
					'title' => array(
						'type' => 'string',
						'description' => __( 'Optional title for the new form. If omitted, AI will suggest one.', 'wp-feature-api-cf7-bridge' ),
					),
				),
				'required' => array( 'description' ),
			),
			'permission_callback' => function() {
                // Requires ability to create forms and potentially use AI services
				return current_user_can( 'wpcf7_edit_contact_forms' ) && function_exists('ai_services');
			},
			'callback'    => function( $request ) {
                $context = $request->get_param('context') ?? array();
                $description = trim( $context['description'] ?? '' );
                $title = trim( $context['title'] ?? '' );

                if ( empty( $description ) ) {
                    return new WP_Error( 'missing_description', __( 'Form description is required for generation.', 'wp-feature-api-cf7-bridge' ), array( 'status' => 400 ) );
                }

                // --- Call AI Service to Generate Configuration ---
                if ( ! function_exists( 'ai_services' ) || ! ai_services()->has_available_services() ) {
                    return new WP_Error( 'ai_service_unavailable', __( 'AI Services plugin not available or configured.', 'wp-feature-api-cf7-bridge' ), array( 'status' => 503 ) );
                }

                $ai_service = ai_services()->get_available_service();
                $model_args = array(
                    'feature'      => 'cf7-generate-full-form',
                    'capabilities' => array( AI_Capability::TEXT_GENERATION ),
                );
                $model = $ai_service->get_model( $model_args );

                // --- Construct AI Prompt ---
                $cf7_template_example = WPCF7_ContactFormTemplate::get_default('form');
                $cf7_mail_example = WPCF7_ContactFormTemplate::get_default('mail');
                // Note: Including full default messages might make the prompt too long.
                // Consider only asking for Form and Mail sections initially.

                $ai_prompt = <<<PROMPT
Generate the complete configuration for a Contact Form 7 form based on the following description.
Output ONLY the configuration sections, each starting with a specific marker line (`### Form`, `### Mail`, `### Mail_2`, `### Messages`, `### Additional_Settings`).
Use standard Contact Form 7 shortcode syntax for the form content.
For Mail settings, include recipient, sender, subject, additional_headers, and body. Use placeholders like `[your-name]`, `[your-email]` appropriately based on the form fields generated. Ensure the 'From' address is valid (like `[your-name] <wordpress@your-site.com>`).

Description:
{$description}

Example Output Format:

### Form
<label> Your Name (required)
    [text* your-name] </label>

<label> Your Email (required)
    [email* your-email] </label>

<label> Subject
    [text your-subject] </label>

<label> Your Message
    [textarea your-message] </label>

[submit "Send"]

### Mail
Recipient: admin@example.com
Sender: "[your-name] <wordpress@example.com>"
Subject: "[_site_title] \"[your-subject]\""
Additional_headers: Reply-To: [your-email]
Body:
From: [your-name] <[your-email]>
Subject: [your-subject]

Message Body:
[your-message]

--
This e-mail was sent from a contact form on [_site_title] ([_site_url])

### Mail_2
active: false

### Messages
mail_sent_ok: "Thank you for your message."
# (Include other messages only if specifically requested in description)

### Additional_Settings
# (Include only if specifically requested)

---
Now, generate the configuration for the description provided above.
PROMPT;

                // --- Generate with AI Services ---
                try {
                    $candidates = $model->generate_text( $ai_prompt, array('temperature' => 0.2) ); // Slightly creative but mostly stable
                    $generated_config_text = extract_text_from_candidates($candidates); // Use helper

                    if (empty($generated_config_text)) {
                         return new WP_Error( 'ai_generation_failed', __( 'AI failed to generate form configuration.', 'wp-feature-api-cf7-bridge' ), array( 'status' => 500 ) );
                    }

                } catch (\Exception $e) {
                     return new WP_Error( 'ai_service_error', __( 'Error calling AI service: ', 'wp-feature-api-cf7-bridge' ) . $e->getMessage(), array( 'status' => 500 ) );
                }

                // --- Parse Generated Configuration ---
                $parsed_config = array(
                    'form' => '',
                    'mail' => WPCF7_ContactFormTemplate::get_default('mail'), // Start with defaults
                    'mail_2' => WPCF7_ContactFormTemplate::get_default('mail_2'),
                    'messages' => WPCF7_ContactFormTemplate::get_default('messages'),
                    'additional_settings' => ''
                );

                $sections = preg_split('/^### (Form|Mail|Mail_2|Messages|Additional_Settings)\s*$/m', $generated_config_text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

                for ($i = 0; $i < count($sections); $i += 2) {
                    if (isset($sections[$i+1])) {
                        $section_name = strtolower(trim($sections[$i]));
                        $section_content = trim($sections[$i+1]);

                        if ($section_name === 'form' || $section_name === 'additional_settings') {
                            $parsed_config[$section_name] = $section_content;
                        } elseif ($section_name === 'mail' || $section_name === 'mail_2') {
                            // Basic parsing for mail settings (more robust parsing needed for production)
                            $lines = explode("\n", $section_content);
                            $current_mail = array();
                            foreach ($lines as $line) {
                                if (preg_match('/^([a-zA-Z0-9_]+):(.*)$/', $line, $matches)) {
                                    $key = strtolower(trim($matches[1]));
                                    $val = trim($matches[2]);
                                     // Handle special cases like 'active' boolean for mail_2
                                    if ($key === 'active' && ($val === 'true' || $val === '1')) $val = true;
                                    if ($key === 'active' && ($val === 'false' || $val === '0')) $val = false;
                                     if ($key === 'use_html' || $key === 'exclude_blank') $val = (bool)$val;

                                    if ($key === 'body') { // Assume rest is body
                                        $current_mail[$key] = trim(substr($line, strpos($line, ':') + 1));
                                        // Append remaining lines to body
                                        $remaining_lines = array_slice($lines, array_search($line, $lines) + 1);
                                        $current_mail[$key] .= "\n" . implode("\n", $remaining_lines);
                                        break; // Stop processing lines for this section
                                    } else {
                                         $current_mail[$key] = $val;
                                    }

                                }
                            }
                             // Merge with defaults, overwriting with parsed values
                             $parsed_config[$section_name] = array_merge($parsed_config[$section_name], $current_mail);
                        } elseif ($section_name === 'messages') {
                            // Basic parsing for messages
                             $lines = explode("\n", $section_content);
                             foreach ($lines as $line) {
                                if (preg_match('/^([a-zA-Z0-9_]+):(.*)$/', $line, $matches)) {
                                     $key = strtolower(trim($matches[1]));
                                     $val = trim($matches[2]);
                                     if (isset($parsed_config['messages'][$key])) { // Only update existing message keys
                                         $parsed_config['messages'][$key] = $val;
                                     }
                                }
                             }
                        }
                    }
                }

                // --- Create and Save the Form ---
                $new_form = WPCF7_ContactForm::get_template( array(
                    'title' => $title ?: ('Generated Form - ' . substr($description, 0, 30) . '...') // Default title if none provided
                ) );

                // Apply generated properties
                $new_form->set_properties( $parsed_config );

                $result = $new_form->save();

                if ( ! $result ) {
                     return new WP_Error( 'cf7_create_generated_failed', __( 'Failed to create the generated contact form.', 'wp-feature-api-cf7-bridge' ), array( 'status' => 500 ) );
                }

				return array(
                    'success' => true,
                    'form_id' => $new_form->id(),
                    'title'   => $new_form->title(),
                    'generated_form_content' => $parsed_config['form'], // Return generated content for review
                    'message' => __( 'Contact form generated and created successfully.', 'wp-feature-api-cf7-bridge' )
                );
			},
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'success' => array( 'type' => 'boolean' ),
                    'form_id' => array( 'type' => 'integer' ),
                    'title'   => array( 'type' => 'string' ),
                     'generated_form_content' => array( 'type' => 'string', 'description' => 'The AI-generated form content for verification.' ),
                    'message' => array( 'type' => 'string' ),
                ),
                'required' => array('success', 'form_id', 'message'),
            ),
		)
	);


	// --- Feature 5: Delete Contact Form (Tool) --- (Same as before)
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
				if ( ! $contact_form ) return new WP_Error( 'cf7_not_found', __( 'Contact form not found.', 'wp-feature-api-cf7-bridge' ), array( 'status' => 404 ) );
                $result = $contact_form->delete();
                if ( ! $result ) return new WP_Error( 'cf7_delete_failed', __( 'Failed to delete contact form.', 'wp-feature-api-cf7-bridge' ), array( 'status' => 500 ) );
				return array('success' => true, 'message' => __( 'Contact form deleted successfully.', 'wp-feature-api-cf7-bridge' ));
			},
            'output_schema' => array(
                'type' => 'object', 'properties' => array( 'success' => array( 'type' => 'boolean' ), 'message' => array( 'type' => 'string' ),), 'required' => array('success', 'message'),
            ),
		)
	);

	// --- Feature 6: Duplicate Contact Form (Tool) --- (Same as before)
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
				return current_user_can( 'wpcf7_edit_contact_form', $source_form_id ) && current_user_can( 'wpcf7_edit_contact_forms' );
			},
			'callback'    => function( $request ) {
                $context = $request->get_param('context');
                $source_form_id = (int) $context['source_form_id'];
                $new_title = isset( $context['new_title'] ) ? trim( $context['new_title'] ) : null;
				$source_contact_form = wpcf7_contact_form( $source_form_id );
				if ( ! $source_contact_form ) return new WP_Error( 'cf7_source_not_found', __( 'Source contact form not found.', 'wp-feature-api-cf7-bridge' ), array( 'status' => 404 ) );
                $new_form = $source_contact_form->copy();
                if ( ! empty( $new_title ) ) $new_form->set_title( $new_title );
                $result = $new_form->save();
                if ( ! $result ) return new WP_Error( 'cf7_duplicate_failed', __( 'Failed to duplicate contact form.', 'wp-feature-api-cf7-bridge' ), array( 'status' => 500 ) );
				return array('success' => true, 'new_form_id' => $new_form->id(), 'new_title' => $new_form->title(), 'message' => __( 'Contact form duplicated successfully.', 'wp-feature-api-cf7-bridge' ));
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

// Register with the centralized feature loader
if (function_exists('wp_react_agent_register_feature_set')) {
    wp_react_agent_register_feature_set(
        'contact-form-7',                      // Unique ID
        'Contact Form 7 Features',             // Label
        'wp_feature_api_cf7_register_features', // Callback function
        array(
            'wp_register_feature',             // Require Feature API
            'WPCF7_ContactForm',               // Require CF7
            'wp_find_feature',                 // Require Feature API functions
            'WP_Feature'                       // Require Feature API classes
        )
    );
    cf7_feature_api_debug_log('Contact Form 7 Features registered with loader');
} else {
    cf7_feature_api_debug_log('Centralized loader not available, Contact Form 7 features will not be registered');
}

// Remove the old hooks approach
if (has_action('init', 'wp_feature_api_cf7_register_features')) {
    remove_action('init', 'wp_feature_api_cf7_register_features', 99);
}
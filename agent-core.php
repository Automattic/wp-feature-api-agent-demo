<?php

use Felix_Arntz\AI_Services\Services\API\Enums\AI_Capability;
use Felix_Arntz\AI_Services\Services\API\Enums\Content_Role;
use Felix_Arntz\AI_Services\Services\API\Helpers;
use Felix_Arntz\AI_Services\Services\API\Types\Content;
use Felix_Arntz\AI_Services\Services\API\Types\Parts;
use Felix_Arntz\AI_Services\Services\AI_Service_Exception; // Catch specific AI service errors

// Note: Text_Part might not be needed if using Helpers::get_text_from_contents directly
// use Felix_Arntz\AI_Services\Services\API\Types\Parts\Text_Part;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Default max iterations, can be overridden with filter
define('WP_REACT_AGENT_DEFAULT_MAX_ITERATIONS', 7);

// Check if debug log function exists, if not define it to avoid errors
if (!function_exists('wp_react_agent_debug_log')) {
    /**
     * Debug log function (fallback if not defined in plugin.php)
     */
    function wp_react_agent_debug_log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG === true) {
            error_log('WP ReAct Agent: ' . $message);
        }
    }
}

/**
 * Handles the AJAX request to run the ReAct agent.
 */
function handle_react_agent_run() {
    check_ajax_referer( 'react_agent_run_nonce', 'nonce' );

    // Allow filtering the required capability for using the ReAct agent
    $required_capability = apply_filters( 'wp_react_agent_required_capability', 'manage_options' );

    if ( ! current_user_can( $required_capability ) ) { // Configurable capability check
        wp_send_json_error( array('message' => 'Permission denied.'), 403 );
        return;
    }

    $query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';

    if ( empty( $query ) ) {
        wp_send_json_error( array('message' => 'Query cannot be empty.'), 400 );
        return;
    }

    // Check if AI Services plugin is available and configured
    if ( ! function_exists( 'ai_services' ) ) {
         wp_send_json_error( array('message' => 'AI Services plugin is not active.'), 500 );
         return;
    }
    if ( ! ai_services()->has_available_services() ) {
        wp_send_json_error( array('message' => 'No AI Service is configured or available. Please configure one in Settings > AI Services.'), 500 );
        return;
    }

    try {
        $ai_service = ai_services()->get_available_service();
        $result = run_react_loop( $ai_service, $query );
        wp_send_json_success( $result ); // Contains 'answer' and 'transcript'
    } catch ( AI_Service_Exception $e ) {
        error_log("WP ReAct Agent - AI Service Error: " . $e->getMessage());
        wp_send_json_error( array(
            'message' => 'An error occurred with the AI service: ' . $e->getMessage(),
            'code' => $e->getCode()
        ), 500 );
    } catch ( \Exception $e ) {
        error_log("WP ReAct Agent - General Error: " . $e->getMessage());
         wp_send_json_error( array('message' => 'An unexpected error occurred: ' . $e->getMessage()), 500 );
    }
}

/**
 * The core ReAct loop using AI Services plugin.
 *
 * @param object $ai_service AI Service instance (\Felix_Arntz\AI_Services\Services\AI_Service_Client or decorator).
 * @param string $query The initial user query.
 * @return array Result containing final answer and transcript.
 */
function run_react_loop( $ai_service, string $query ): array {
    // Create initial user content using the helper
    $user_content = create_text_content($query, Content_Role::USER);

    $context = [ $user_content ]; // Start context with user query as Content object
    $transcript = "User: " . $query . "\n\n";
    
    // Allow filtering the maximum number of iterations
    $max_iterations = apply_filters('wp_react_agent_max_iterations', WP_REACT_AGENT_DEFAULT_MAX_ITERATIONS);
    $iterations = 0;

    // Build the system prompt text first
    $system_prompt_text = build_system_prompt();

    // Get a model capable of text generation
    $model_args = array(
        'feature'      => 'wp-react-agent-loop', // Identifier for usage tracking
        'capabilities' => array( AI_Capability::TEXT_GENERATION ),
        'systemInstruction' => $system_prompt_text, // Pass system instruction to the model request
        // Consider adding model preference if AI Services supports it robustly:
        // 'model_id' => 'openai/gpt-4o-mini',
    );

    try {
        $model = $ai_service->get_model( $model_args );
    } catch ( AI_Service_Exception $e) {
        throw new \Exception("Could not get a suitable AI model: " . $e->getMessage());
    } catch ( \Exception $e ) {
         throw new \Exception("Could not get a suitable AI model (General Error): " . $e->getMessage());
    }


    while ($iterations < $max_iterations) {
        $iterations++;

        // Messages array now contains Content objects
        $messages_for_api = $context;

        try {
            // Generate text using AI Services
            $candidates = $model->generate_text( $messages_for_api, array('temperature' => 0.0) ); // Low temp for consistency

            $llm_response_content = extract_text_from_candidates($candidates); // Use helper to extract text

            if (empty($llm_response_content)) {
                throw new \Exception("LLM returned an empty or unparseable response.");
            }

            $transcript .= "LLM:\n" . $llm_response_content . "\n\n";

            list($thought, $action) = extract_thought_and_action($llm_response_content);

            if ( empty($action) ) { // If LLM provides no action, consider it finished or confused
                $final_answer = $thought ?: $llm_response_content ?: "I have completed the thought process but couldn't determine a final action or answer.";
                 return [
                    'answer' => $final_answer,
                    'transcript' => $transcript . "Agent: (No action provided, finishing)\n" . $final_answer
                 ];
            }

            // Add LLM's response (thought and action) to context as a Content object
            $context[] = create_text_content($llm_response_content, Content_Role::MODEL);

            // Check for finish action
            if (str_starts_with(strtolower($action), 'finish[')) {
                $final_answer = trim(substr($action, 7, -1));
                 return [
                    'answer' => $final_answer,
                    'transcript' => $transcript . "Agent: Finishing with answer.\n" . $final_answer
                 ];
            }

            // Execute Feature API Action
            $observation_result = execute_feature_api_action($action);

            $observation = '';
            if (is_wp_error($observation_result)) {
                $observation = sprintf(
                    "Error: %s (Code: %s)",
                    $observation_result->get_error_message(),
                    $observation_result->get_error_code() ?: 'unknown'
                );
            } else {
                $observation_str = wp_json_encode($observation_result); // Encode result for context
                if ($observation_str === false) { // JSON encode failed
                     $observation = 'Error: Could not encode observation result to JSON.';
                } elseif (strlen($observation_str) > 800) { // Limit length for context
                     $observation = substr($observation_str, 0, 800) . '... [Result Truncated]';
                } else {
                     $observation = $observation_str;
                }
            }

            $transcript .= "Observation: " . $observation . "\n\n";
            // Add observation to context as a Content object from the 'user' role (as per ReAct convention)
            $context[] = create_text_content("Observation: " . $observation, Content_Role::USER);

        } catch ( AI_Service_Exception $e ) { // Catch AI service specific errors during generation
             $error_message = "Error during AI Service call: " . $e->getMessage() . " (Code: " . $e->getCode() . ")";
             error_log("WP ReAct Agent Loop - AI Service Error: " . $error_message);
             return [
                'answer' => "An error occurred while communicating with the AI service. Please check logs.",
                'transcript' => $transcript . "System Error: " . $error_message
             ];
        } catch ( \Exception $e ) { // Catch other errors (action execution, parsing, JSON errors, etc.)
             $error_message = "Error during loop execution: " . $e->getMessage();
             error_log("WP ReAct Agent Loop - General Error: " . $error_message);
             return [
                'answer' => "An internal error occurred. Please check the logs.",
                'transcript' => $transcript . "System Error: " . $error_message
             ];
        }
    }

    // Max iterations reached
    return [
        'answer' => 'Maximum iterations reached. Could not complete the task.',
        'transcript' => $transcript . "Agent: Reached max iterations.\n"
    ];
}

/**
 * Safely creates Content objects in the format expected by AI Services.
 * Checks if the necessary classes exist.
 *
 * @param string $text The text content.
 * @param string $role The role (e.g., Content_Role::USER, Content_Role::MODEL).
 * @return Content|array A Content object or a basic array fallback.
 */
function create_text_content(string $text, string $role = Content_Role::USER) {
     if ( class_exists('\Felix_Arntz\AI_Services\Services\API\Types\Parts') && class_exists('\Felix_Arntz\AI_Services\Services\API\Types\Content') ) {
        $parts = new Parts();
        $parts->add_text_part($text); // This method should exist in Parts class
        return new Content($role, $parts);
     } else {
         // Fallback if classes are missing (shouldn't happen if AI Services is active)
         return ['role' => $role, 'parts' => [['text' => $text]]];
     }
}

/**
 * Safely extracts text from AI Services candidates response.
 * Uses the official Helpers if available.
 *
 * @param mixed $candidates The response from AI Services model->generate_text().
 * @return string The extracted text content, or an empty string on failure.
 */
function extract_text_from_candidates($candidates): string {
    if ( class_exists('Felix_Arntz\AI_Services\Services\API\Helpers') &&
         method_exists('Felix_Arntz\AI_Services\Services\API\Helpers', 'get_candidate_contents') &&
         method_exists('Felix_Arntz\AI_Services\Services\API\Helpers', 'get_text_from_contents') ) {
        try {
            $contents = Helpers::get_candidate_contents($candidates);
            if (!empty($contents) && is_array($contents)) {
                return Helpers::get_text_from_contents($contents);
            }
        } catch (\Throwable $e) {
            error_log('WP ReAct Agent - AI Services Helper extraction failed: ' . $e->getMessage());
        }
    }

    // Fallback if Helpers are unavailable or failed
    if (is_array($candidates) && !empty($candidates)) {
        $first_candidate = reset($candidates);
        if (is_object($first_candidate) && property_exists($first_candidate, 'content')) {
            $content_obj = $first_candidate->content;
            if ($content_obj instanceof Content && method_exists($content_obj, 'get_text')) {
                return $content_obj->get_text();
            } elseif (is_string($content_obj)) {
                 return $content_obj;
            }
        } elseif (is_string($first_candidate)) {
            return $first_candidate;
        }
    } elseif (is_string($candidates)) {
         return $candidates;
    }

    error_log('WP ReAct Agent - Could not extract text from AI candidates: ' . print_r($candidates, true));
    return ''; // Return empty string if extraction fails
}


/**
 * Builds the dynamic system prompt including available tools (features).
 * Includes Input Schema for better LLM understanding.
 *
 * @return string The system prompt.
 */
function build_system_prompt(): string {
    $features_description = "Available Tools (WordPress Features):\n";
    if ( function_exists( 'wp_get_features' ) ) {
        $features = wp_get_features();
        if (empty($features)) {
            $features_description .= "No features currently available.\n";
        } else {
            foreach ($features as $feature) {
                // Ensure it's the correct object and likely executable
                if ($feature instanceof WP_Feature && ($feature->get_callback() || $feature->has_rest_alias())) {
                     $features_description .= sprintf(
                        "- ID: %s\n  Name: %s\n  Description: %s\n",
                        esc_html($feature->get_id()),
                        esc_html($feature->get_name()),
                        esc_html($feature->get_description())
                    );
                    $input_schema = $feature->get_input_schema();
                    // Provide the schema as JSON for the LLM
                    if ($input_schema && is_array($input_schema)) {
                       $schema_json = wp_json_encode($input_schema, JSON_PRETTY_PRINT);
                       if ($schema_json === false) { $schema_json = '{}'; } // Handle potential encoding error
                       $features_description .= "  Input Schema (JSON): " . $schema_json . "\n";
                    } else {
                        $features_description .= "  Input Schema (JSON): {}\n"; // Indicate no specific args
                    }
                }
            }
        }
    } else {
        $features_description .= "Feature API not available.\n";
    }

    // Updated prompt for clarity on JSON arguments
    // TODO: dynamically replace the feature multi-shot example with relevant features based on the context of the user and previous messages.
    $prompt = <<<PROMPT
You are an assistant running within a WordPress environment. Your goal is to help the user by using available tools (WordPress features).
Follow the ReAct (Reasoning + Acting) process strictly:

Thought: Briefly explain your reasoning and plan for the *next single step*.
Action: Choose *one* available tool (feature) to execute. Format it *exactly* as: `feature_id JSON_Arguments`.
- `feature_id` is the ID listed for the tool (e.g., `wp/get-option`).
- `JSON_Arguments` is a *valid JSON object* containing the arguments required by the tool's Input Schema. Use `{}` if no arguments are needed by the schema. Pay close attention to required fields in the schema.
- Example (WP Options): `wp/get-option {"option_name": "blogname"}`
- Example (Navigation): `wp/navigate-to {"page": "edit.php"}`
- Example (CF7 Forms): `cf7/get-form {"form_id": 123}`
- Example (CF7 Generate): `cf7/generate-full-form {"title": "My New Form", "description": "A simple form with name, email, subject, message fields."}`
- If you have the final answer for the user, use the special action: `finish[Your final answer to the user.]`.

IMPORTANT FORMATTING RULES:
- DO NOT use code blocks with backticks (```) around your actions
- DO NOT prefix feature IDs with "tool-" - just use the exact ID as provided
- Write actions directly on a single line without any additional formatting
- CORRECT: wp/navigate-to {"page": "upload.php"}
- INCORRECT: ```json
  tool-wp/navigate-to {"page": "upload.php"}
  ```

You will receive an Observation: with the result of your action (often in JSON format, sometimes just a success/error message). Use this observation to refine your thought process for the next step.

Keep iterating Thought -> Action -> Observation until you have the final answer for the user, then use the `finish[answer]` action.
If a tool fails (returns an error in Observation), state that in your Thought and try a different approach or ask the user for clarification using `finish[Your question for the user.]`. Do not make up information or assume success if an error occurred.
If you need missing information, ask the user using `finish[Your question for the user.]`.

{$features_description}
Start your response *always* with "Thought:" followed by a newline, then "Action:" followed by a newline. Do not add any text before "Thought:".
PROMPT;

    return trim($prompt);
}

/**
 * Extracts Thought and Action from the LLM response.
 * Handles formatting issues like code blocks with backticks and tool- prefixes.
 *
 * @param string $response_content The raw content from the LLM.
 * @return array [string $thought, string $action]
 */
function extract_thought_and_action(string $response_content): array {
    $thought = '';
    $action = '';

    // Regex to capture thought and the rest of the string after Action:
    if (preg_match('/Thought:(.*?)(?:\n|^)Action:(.*)/si', $response_content, $matches)) {
        $thought = trim($matches[1]);
        $raw_action = trim($matches[2]);
        
        // Clean the action from code blocks and other formatting
        $action = clean_action_string($raw_action);
    } else {
        // Fallback if Action: is not on a new line or Thought is missing
        if (preg_match('/Action:(.*)/si', $response_content, $action_match)) {
            $raw_action = trim($action_match[1]);
            $action = clean_action_string($raw_action);
            
            // Try to get thought before Action:
            $thought_part = trim(substr($response_content, 0, strpos($response_content, 'Action:')));
            if (str_starts_with(strtolower($thought_part), 'thought:')) {
                $thought = trim(substr($thought_part, 8));
            }
        } elseif (str_starts_with(strtolower($response_content), 'thought:')) {
            // Only thought provided, no action
            $thought = trim(substr($response_content, 8));
            $action = ''; // Explicitly set action to empty
        }
    }

    return [$thought, $action];
}

/**
 * Cleans the action string by removing code blocks, backticks, and tool- prefixes.
 *
 * @param string $raw_action The raw action string from the LLM.
 * @return string The cleaned action string.
 */
function clean_action_string(string $raw_action): string {
    // Remove code block formatting (backticks with optional language)
    if (preg_match('/```(?:json|[a-z]*)?(.+?)```/s', $raw_action, $code_matches)) {
        $raw_action = trim($code_matches[1]);
    }
    
    // Remove any backticks that might still be present
    $raw_action = str_replace('`', '', $raw_action);
    
    // Remove any "tool-" prefix that might be added by the model
    $raw_action = preg_replace('/^tool-/', '', $raw_action);
    
    return trim($raw_action);
}

/**
 * Executes a feature API action string.
 * (Includes permission checks and dummy WP_REST_Request)
 *
 * @param string $action_string e.g., 'wp/get-option {"option_name": "blogname"}' or 'cf7/get-form {"form_id": 123}'
 * @return mixed|WP_Error Result of the feature execution or WP_Error on failure.
 */
function execute_feature_api_action(string $action_string) {
    // Clean the action string again as a safety measure
    $action_string = clean_action_string($action_string);
    
    $feature_id = '';
    $args_json_str = '{}'; // Default to empty JSON object

    // Match feature_id followed by optional whitespace and the JSON arguments
    if (preg_match('/^([a-z0-9\-\/]+)[\s]*(\{.*\})?$/i', trim($action_string), $matches)) {
        $feature_id = trim($matches[1]);
        if (isset($matches[2]) && trim($matches[2])) {
             $args_json_str = trim($matches[2]);
             // Validate JSON structure more strictly
             json_decode($args_json_str); // Attempt decode
             if (json_last_error() !== JSON_ERROR_NONE) {
                 return new WP_Error('invalid_json_format', 'Action arguments is not valid JSON. Received: ' . esc_html($args_json_str));
             }
        } else {
             $args_json_str = '{}'; // Ensure it's a valid empty JSON object if no args provided
        }
    } else if (preg_match('/^finish\[.*\]$/i', trim($action_string))) {
        return new WP_Error('finish_action_misrouted', 'The "finish" action should be handled by the main loop.');
    } else {
         return new WP_Error('invalid_action_format', 'Action format is invalid. Expected: feature_id {"key": "value"} or finish[Answer]. Received: ' . esc_html($action_string));
    }


    if ( ! function_exists( 'wp_find_feature' ) ) {
        return new WP_Error('feature_api_unavailable', 'Feature API function wp_find_feature is not available.');
    }

    // Enhanced feature lookup that tries multiple variations of the feature ID
    $feature = null;
    
    // First try the ID exactly as provided
    $feature = wp_find_feature($feature_id);
    
    // If not found, try with resource- prefix
    if (!$feature instanceof WP_Feature && !str_starts_with($feature_id, 'resource-')) {
        $resource_id = 'resource-' . $feature_id;
        $feature = wp_find_feature($resource_id);
        if ($feature instanceof WP_Feature) {
            wp_react_agent_debug_log("Feature found with resource- prefix: $resource_id");
        }
    }
    
    // If still not found, try with tool- prefix
    if (!$feature instanceof WP_Feature && !str_starts_with($feature_id, 'tool-')) {
        $tool_id = 'tool-' . $feature_id;
        $feature = wp_find_feature($tool_id);
        if ($feature instanceof WP_Feature) {
            wp_react_agent_debug_log("Feature found with tool- prefix: $tool_id");
        }
    }

    if ( ! $feature instanceof WP_Feature ) {
        // Try to get a list of available features for more helpful error message
        $available_features = array();
        if (function_exists('wp_get_features')) {
            $all_features = wp_get_features();
            foreach ($all_features as $f) {
                if ($f instanceof WP_Feature) {
                    $available_features[] = $f->get_id();
                }
            }
        }
        
        $error_message = sprintf('Feature "%s" not found or invalid.', esc_html($feature_id));
        if (!empty($available_features)) {
            $error_message .= ' Available features: ' . implode(', ', $available_features);
        }
        
        return new WP_Error('feature_not_found', $error_message, ['status' => 404]);
    }

    // Decode JSON arguments again, this time using the validated string
    $args = json_decode( $args_json_str, true );
    if ($args === null && json_last_error() !== JSON_ERROR_NONE) {
        // Should not happen due to earlier check, but good safety measure
        return new WP_Error('invalid_json_args_final', 'Failed to decode JSON arguments: ' . json_last_error_msg() . ' | Input: ' . esc_html($args_json_str));
    }

    // --- Permission Check ---
    $dummy_request_for_perms = new class($args) extends WP_REST_Request {
         private $dummy_params;
         public function __construct($params) { $this->dummy_params = ['context' => $params]; parent::__construct('GET', '/'); } // Method/Route mandatory
         public function get_param( $key ) { return $this->dummy_params[$key] ?? null; }
         public function get_params() { return $this->dummy_params; }
         public function get_route() { return '/dummy-feature-route'; } // Provide a dummy route
         public function get_method() { return 'POST'; } // Assume POST for tools, GET for resources - adjust if needed
    };

    $permission_callback = $feature->get_permission_callback();
    if ( is_callable( $permission_callback ) ) {
        try {
            $permission_result = call_user_func( $permission_callback, $dummy_request_for_perms );
            if ( is_wp_error( $permission_result ) ) { return $permission_result; }
            if ( true !== $permission_result ) { return new WP_Error('permission_denied', sprintf('Permission denied for feature "%s".', esc_html($feature_id)), array('status' => 403)); }
        } catch (\Throwable $e) {
            error_log("Error in permission callback for {$feature_id}: " . $e->getMessage());
            return new WP_Error('permission_callback_error', 'Error during permission check.', array('status' => 500));
        }
    } else {
         return new WP_Error('permission_undefined', sprintf('Permission check not defined for feature "%s". Access denied.', esc_html($feature_id)), array('status' => 500));
    }

    // --- Execute the feature ---
     $dummy_request_for_run = new class($args) extends WP_REST_Request {
         private $dummy_params;
         public function __construct($params) { $this->dummy_params = ['context' => $params]; parent::__construct('GET', '/'); } // Method/Route mandatory
         public function get_param( $key ) { return $this->dummy_params[$key] ?? null; }
         public function get_params() { return $this->dummy_params; }
         public function get_route() { return '/dummy-feature-route'; }
         public function get_method() { return 'POST'; } // Assume POST for tools
    };

    try {
        $result = $feature->run( $dummy_request_for_run );
        return $result; // Pass WP_Error through if returned by run()
    } catch ( \Exception $e ) {
        error_log("Error executing feature {$feature_id}: " . $e->getMessage());
        return new WP_Error('feature_execution_error', 'Error executing feature: ' . $e->getMessage());
    }
}
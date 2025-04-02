<?php

use Felix_Arntz\AI_Services\Services\API\Enums\AI_Capability;
use Felix_Arntz\AI_Services\Services\API\Enums\Content_Role;
use Felix_Arntz\AI_Services\Services\API\Helpers;
use Felix_Arntz\AI_Services\Services\API\Types\Content;
use Felix_Arntz\AI_Services\Services\API\Types\Parts;
use Felix_Arntz\AI_Services\Services\API\Types\Parts\Text_Part;
use Felix_Arntz\AI_Services\Services\AI_Service_Exception; // Catch specific AI service errors

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define('WP_REACT_AGENT_MAX_ITERATIONS', 7); // Increased slightly

/**
 * Handles the AJAX request to run the ReAct agent.
 */
function handle_react_agent_run() {
    check_ajax_referer( 'react_agent_run_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) { // Basic capability check
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
        // Get the default configured service, or specify one like 'openai', 'google' if needed
        $ai_service = ai_services()->get_available_service();
        $result = run_react_loop( $ai_service, $query );
        wp_send_json_success( $result );
    } catch ( AI_Service_Exception $e ) {
        error_log("WP ReAct Agent - AI Service Error: " . $e->getMessage());
        wp_send_json_error( array(
            'message' => 'An error occurred with the AI service: ' . $e->getMessage(),
            'code' => $e->getCode() // Include API error code if available
        ), 500 );
    } catch ( \Exception $e ) {
        error_log("WP ReAct Agent - General Error: " . $e->getMessage());
         wp_send_json_error( array('message' => 'An unexpected error occurred: ' . $e->getMessage()), 500 );
    }
}

/**
 * The core ReAct loop using AI Services plugin.
 *
 * @param object $ai_service AI Service instance (either AI_Service_Client or AI_Service_Decorator).
 * @param string $query The initial user query.
 * @return array Result containing final answer and transcript.
 */
function run_react_loop( $ai_service, string $query ): array {
    // Create a text content from the user's query
    $user_content = create_text_content($query, Content_Role::USER);
    
    $context = [
        $user_content
    ];
    $transcript = "User: " . $query . "\n\n";
    $iterations = 0;

    // Build the system prompt text first
    $system_prompt_text = build_system_prompt();

    // Get a model capable of text generation (like gpt-4o-mini)
    $model_args = array(
        'feature'      => 'wp-react-agent-loop',
        'capabilities' => array( AI_Capability::TEXT_GENERATION ),
        'systemInstruction' => $system_prompt_text, // Pass system instruction here
    );
    $model = $ai_service->get_model( $model_args );

    while ($iterations < WP_REACT_AGENT_MAX_ITERATIONS) {
        $iterations++;
        
        // The messages array now starts with the user's content
        $messages_for_api = $context;

        try {
            // Use AI Services to generate text
            $candidates = $model->generate_text( $messages_for_api, array('temperature' => 0.0) );
            
            // Try to safely extract text from AI Services response
            $llm_response_content = extract_text_from_candidates($candidates);

            if (empty($llm_response_content)) {
                throw new \Exception("LLM returned an empty response.");
            }

            $transcript .= "LLM:\n" . $llm_response_content . "\n\n";

            list($thought, $action) = extract_thought_and_action($llm_response_content);

            if ( empty($action) ) {
                $final_answer = $thought ?: $llm_response_content ?: "I'm sorry, I couldn't determine the next step or final answer.";
                 return [
                    'answer' => $final_answer,
                    'transcript' => $transcript . "Agent: (No action provided, finishing)\n" . $final_answer
                 ];
            }

            $context[] = create_text_content($llm_response_content, Content_Role::MODEL);

            if (str_starts_with(strtolower($action), 'finish[')) {
                $final_answer = trim(substr($action, 7, -1));
                 return [
                    'answer' => $final_answer,
                    'transcript' => $transcript . "Agent: Finishing with answer.\n" . $final_answer
                 ];
            }

            // Execute Feature API Action
            $observation_result = execute_feature_api_action($action); // Same function as before

            $observation = '';
            if (is_wp_error($observation_result)) {
                $observation = "Error: " . $observation_result->get_error_message();
                // Optionally add error code: . " (Code: " . $observation_result->get_error_code() . ")"
            } else {
                $observation_str = wp_json_encode($observation_result); // Keep it concise for the prompt
                if (strlen($observation_str) > 800) { // Shorter limit for prompt context
                     $observation = substr($observation_str, 0, 800) . '... [Truncated]';
                } else {
                    $observation = $observation_str;
                }
            }

            $transcript .= "Observation: " . $observation . "\n\n";
            $context[] = create_text_content("Observation: " . $observation, Content_Role::USER);

        } catch ( AI_Service_Exception $e ) { // Catch AI service specific errors
             $error_message = "Error during AI Service call: " . $e->getMessage() . " (Code: " . $e->getCode() . ")";
             error_log("WP ReAct Agent Loop - AI Service Error: " . $error_message);
             return [
                'answer' => "An error occurred while communicating with the AI service.",
                'transcript' => $transcript . "System Error: " . $error_message
             ];
        } catch ( \Exception $e ) { // Catch other errors (action execution, parsing, etc.)
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
 *
 * @param string $text The text content
 * @param string $role The role (user, model, system)
 * @return Content A Content object suitable for the AI Services plugin.
 */
function create_text_content(string $text, string $role = Content_Role::USER): Content {
    $parts = new Parts();
    $parts->add_text_part($text);
    return new Content($role, $parts);
}

/**
 * Safely extracts text from AI Services candidates response.
 *
 * @param mixed $candidates The response from AI Services
 * @return string The extracted text content
 */
function extract_text_from_candidates($candidates): string {
    // First try using Helpers if it's the right format
    try {
        if (class_exists('Felix_Arntz\AI_Services\Services\API\Helpers') && 
            method_exists('Felix_Arntz\AI_Services\Services\API\Helpers', 'get_candidate_contents')) {
            $contents = Helpers::get_candidate_contents($candidates);
            if (!empty($contents) && is_array($contents)) {
                return Helpers::get_text_from_contents($contents);
            }
        }
    } catch (\Throwable $e) {
        error_log('WP ReAct Agent - Helper extraction failed: ' . $e->getMessage());
        // Continue to fallback methods
    }
    
    // Fallback methods - try different common response patterns
    // For array of candidates
    if (is_array($candidates) && !empty($candidates)) {
        $first_candidate = reset($candidates);
        
        // Different object structures
        if (is_object($first_candidate)) {
            // Try different methods we might find
            if (method_exists($first_candidate, 'get_content')) {
                $content = $first_candidate->get_content();
                if ($content instanceof Content) {
                    // Content object has its own methods
                    if (method_exists($content, 'get_text') || method_exists($content, 'to_string')) {
                        return method_exists($content, 'get_text') ? $content->get_text() : $content->to_string();
                    }
                } elseif (is_string($content)) {
                    return $content;
                }
            }
            
            // Try direct content property
            if (property_exists($first_candidate, 'content')) {
                $content = $first_candidate->content;
                if (is_string($content)) {
                    return $content;
                } elseif (is_object($content) && method_exists($content, 'to_string')) {
                    return $content->to_string();
                }
            }
            
            // Try text property
            if (property_exists($first_candidate, 'text')) {
                return $first_candidate->text;
            }
            
            // Last resort - try json encode
            return json_encode($first_candidate);
        }
        
        // Check for array structure
        if (is_array($first_candidate)) {
            if (isset($first_candidate['content'])) {
                return is_string($first_candidate['content']) ? $first_candidate['content'] : json_encode($first_candidate['content']);
            } elseif (isset($first_candidate['text'])) {
                return $first_candidate['text'];
            }
        }
        
        // Simple string
        if (is_string($first_candidate)) {
            return $first_candidate;
        }
    }
    
    // If it's directly a string
    if (is_string($candidates)) {
        return $candidates;
    }
    
    // If everything else failed, try to serialize the object
    return is_object($candidates) || is_array($candidates) 
        ? json_encode($candidates) 
        : (string)$candidates;
}

/**
 * Builds the dynamic system prompt including available tools (features).
 * (Identical to previous version - depends on wp-feature-api)
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
                if ($feature instanceof WP_Feature && ($feature->get_callback() || $feature->has_rest_alias())) {
                     $features_description .= sprintf(
                        "- ID: %s\n  Name: %s\n  Description: %s\n",
                        $feature->get_id(),
                        $feature->get_name(),
                        $feature->get_description()
                    );
                    $input_schema = $feature->get_input_schema();
                    if ($input_schema) {
                       $features_description .= "  Input Schema (JSON): " . wp_json_encode($input_schema) . "\n";
                    } else {
                        $features_description .= "  Input Schema (JSON): {}\n"; // Indicate no specific args needed
                    }
                }
            }
        }
    } else {
        $features_description .= "Feature API not available.\n";
    }

    // Added more explicit instructions on argument format
    $prompt = <<<PROMPT
You are an assistant running within a WordPress environment. Your goal is to help the user by using available tools (WordPress features).
Follow the ReAct (Reasoning + Acting) process strictly:

Thought: Briefly explain your reasoning and plan for the next step. Focus on one step at a time.

Action: Choose one available tool (feature) to execute. Format it exactly as: feature_id JSON_Arguments.

feature_id is the ID listed for the tool.

JSON_Arguments is a valid JSON object representing the arguments required by the tool's Input Schema. Use {} if no arguments are needed.

Example 1 (no args): cf7/list-forms {}

Example 2 (with args): cf7/get-form {"form_id": 123}

If you have the final answer for the user, use the special action: finish[Your final answer to the user.].

You will receive an Observation: with the result of your action. Use this observation to refine your thought process for the next step.

Keep iterating Thought -> Action -> Observation until you have the final answer for the user, then use the finish[answer] action.
If a tool fails or doesn't provide the needed info, state that in your Thought and choose a different action or use finish. Do not make up information.
If you need information not present in your context or available tools, ask the user for clarification using finish[Your question for the user.].

{$features_description}
Start your response always with "Thought:" followed by a newline, then "Action:" followed by a newline. Do not add any text before "Thought:".
PROMPT;

    return trim($prompt);
}

/**
 * Extracts Thought and Action from the LLM response.
 * (Identical to previous version)
 *
 * @param string $response_content The raw content from the LLM.
 * @return array [string $thought, string $action]
 */
function extract_thought_and_action(string $response_content): array {
     $thought = '';
    $action = '';

    if (preg_match('/Thought:(.*?)(?:Action:|$)/si', $response_content, $matches)) {
        $thought = trim($matches[1]);
    }

    if (preg_match('/Action:(.*)/si', $response_content, $matches)) {
        $action = trim($matches[1]);
    }

    // Basic fallback
    if (empty($thought) && empty($action)) {
        $lines = explode("\n", $response_content, 3);
        if (str_starts_with(strtolower($lines[0]), 'thought:')) {
            $thought = trim(substr($lines[0], 8));
            if (isset($lines[1]) && str_starts_with(strtolower($lines[1]), 'action:')) {
                $action = trim(substr($lines[1], 7));
            }
        } elseif (str_starts_with(strtolower($lines[0]), 'action:')) {
             $action = trim(substr($lines[0], 7));
        }
    }

    return [$thought, $action];
}

/**
 * Executes a feature API action string.
 * (Identical to previous version - depends on wp-feature-api)
 *
 * @param string $action_string e.g., 'cf7/get-form {"form_id": 123}'
 * @return mixed|WP_Error Result of the feature execution or WP_Error on failure.
 */
function execute_feature_api_action(string $action_string) {
    $feature_id = '';
    $args_json_str = '{}';

    if (preg_match('/^([a-z0-9\-\/]+)[\s]*(.*)$/i', trim($action_string), $matches)) {
        $feature_id = trim($matches[1]);
        if (isset($matches[2]) && trim($matches[2])) {
             $args_json_str = trim($matches[2]);
             // Basic check for JSON object structure
             if (!str_starts_with($args_json_str, '{') || !str_ends_with($args_json_str, '}')) {
                  return new WP_Error('invalid_json_format', 'Action arguments must be a valid JSON object string starting with { and ending with }. Received: ' . $args_json_str);
             }
        }
    } else if (preg_match('/^finish\[.*\]$/i', trim($action_string))) {
        // This case is handled in the main loop, shouldn't reach here ideally
         return new WP_Error('finish_action_misrouted', 'The "finish" action should be handled by the main loop.');
    } else {
         return new WP_Error('invalid_action_format', 'Action format is invalid. Expected: feature_id {"key": "value"} or finish[Answer]. Received: ' . esc_html($action_string));
    }

    if ( ! function_exists( 'wp_find_feature' ) ) {
        return new WP_Error('feature_api_unavailable', 'Feature API function wp_find_feature is not available.');
    }

    $feature = wp_find_feature( $feature_id );

    if ( ! $feature instanceof WP_Feature ) {
        return new WP_Error('feature_not_found', sprintf('Feature "%s" not found or invalid.', esc_html($feature_id)));
    }

    // Decode JSON arguments
    $args = json_decode( $args_json_str, true );
    if (json_last_error() !== JSON_ERROR_NONE) {
        return new WP_Error('invalid_json_args', 'Invalid JSON arguments provided for the action: ' . json_last_error_msg() . ' | Input: ' . esc_html($args_json_str));
    }

    // --- Permission Check ---
    $dummy_request_for_perms = new class($args) extends WP_REST_Request {
         private $dummy_params;
         public function __construct($params) { $this->dummy_params = ['context' => $params]; parent::__construct(); }
         public function get_param( $key ) { return $this->dummy_params[$key] ?? null; }
         public function get_params() { return $this->dummy_params; }
    };

    $permission_callback = $feature->get_permission_callback();
    if ( is_callable( $permission_callback ) ) {
        $permission_result = call_user_func( $permission_callback, $dummy_request_for_perms );
        if ( is_wp_error( $permission_result ) ) { return $permission_result; }
        if ( true !== $permission_result ) { return new WP_Error('permission_denied', sprintf('Permission denied for feature "%s".', esc_html($feature_id)), array('status' => 403)); }
    } else {
         return new WP_Error('permission_undefined', sprintf('Permission check not defined for feature "%s". Access denied.', esc_html($feature_id)), array('status' => 500));
    }

    // --- Execute the feature ---
     $dummy_request_for_run = new class($args) extends WP_REST_Request {
         private $dummy_params;
         public function __construct($params) { $this->dummy_params = ['context' => $params]; parent::__construct(); }
         public function get_param( $key ) { return $this->dummy_params[$key] ?? null; }
         public function get_params() { return $this->dummy_params; }
    };

    try {
        $result = $feature->run( $dummy_request_for_run );
        return $result; // Pass WP_Error through if returned by run()
    } catch ( \Exception $e ) {
        error_log("Error executing feature {$feature_id}: " . $e->getMessage());
        return new WP_Error('feature_execution_error', 'Error executing feature: ' . $e->getMessage());
    }
} 
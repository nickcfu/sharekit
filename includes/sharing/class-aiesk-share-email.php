<?php
/**
 * Sharing — Email (HTML message + optional .eml)
 */

if (!defined('ABSPATH')) { exit; }

final class AIESK_Share_Email {

    public function __construct() {
        // Placeholder for full email sending logic
        // When enabled, this will let registered users send their AI message directly via wp_mail().
        add_action('rest_api_init', [$this, 'register_route']);
    }

    public function register_route(): void {
        register_rest_route('aiesk/v1', '/email', [
            'methods'             => 'POST',
            'callback'            => [$this, 'send_email'],
            'permission_callback' => function () { return is_user_logged_in(); },
        ]);
    }

    /**
     * Send the shared AI message via email using wp_mail()
     * Expected JSON: { "to": "user@example.com", "title": "...", "html": "..." }
     */
    public function send_email(WP_REST_Request $req) {
        $data  = json_decode($req->get_body(), true);
        if (!is_array($data)) {
            return new WP_Error('aiesk_bad_json', __('Invalid JSON', 'ai-share-kit'), ['status' => 400]);
        }

        $to    = sanitize_email($data['to'] ?? '');
        $title = sanitize_text_field($data['title'] ?? '');
        $html  = wp_kses_post($data['html'] ?? '');

        if (empty($to) || empty($title) || empty($html)) {
            return new WP_Error('aiesk_missing_data', __('Missing required fields', 'ai-share-kit'), ['status' => 422]);
        }

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
        ];

        $sent = wp_mail($to, $title, $html, $headers);

        if (!$sent) {
            return new WP_Error('aiesk_email_failed', __('Email failed to send', 'ai-share-kit'), ['status' => 500]);
        }

        return rest_ensure_response(['success' => true, 'to' => $to]);
    }
}

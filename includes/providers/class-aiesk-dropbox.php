<?php
/**
 * Provider — Dropbox
 * Handles OAuth and file save endpoints for Dropbox integration.
 */

if (!defined('ABSPATH')) { exit; }

final class AIESK_Dropbox {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void {

        // Begin Dropbox OAuth (returns auth URL)
        register_rest_route('aiesk/v1', '/dropbox/oauth/start', [
            'methods'             => 'GET',
            'callback'            => [$this, 'oauth_start'],
            'permission_callback' => function () { return is_user_logged_in(); },
        ]);

        // Dropbox OAuth callback endpoint
        register_rest_route('aiesk/v1', '/dropbox/oauth/callback', [
            'methods'             => 'GET',
            'callback'            => [$this, 'oauth_callback'],
            'permission_callback' => '__return_true', // must be open for Dropbox redirect
        ]);

        // Save file to Dropbox
        register_rest_route('aiesk/v1', '/dropbox/save', [
            'methods'             => 'POST',
            'callback'            => [$this, 'save'],
            'permission_callback' => function () { return is_user_logged_in(); },
        ]);
    }

    /**
     * Step 1: Start OAuth (return Dropbox authorization URL)
     */
    public function oauth_start(WP_REST_Request $req) {
        // TODO: use your real app key, redirect URI, scopes
        $auth_url = apply_filters('aiesk_dropbox_oauth_url', '');
        if (empty($auth_url)) {
            return new WP_Error('aiesk_dropbox_oauth_missing', __('Dropbox OAuth URL not configured yet', 'ai-share-kit'), ['status' => 501]);
        }
        return rest_ensure_response(['auth_url' => esc_url_raw($auth_url)]);
    }

    /**
     * Step 2: OAuth callback (Dropbox redirects here with code)
     */
    public function oauth_callback(WP_REST_Request $req) {
        $code = sanitize_text_field($req->get_param('code'));
        if (empty($code)) {
            wp_die(__('Dropbox OAuth failed: no code provided.', 'ai-share-kit'));
        }

        // TODO: exchange code → access_token via Dropbox API
        // TODO: save tokens in usermeta for get_current_user_id()
        wp_die(
            esc_html__('Dropbox OAuth callback received. Please finalize token storage in provider code.', 'ai-share-kit'),
            esc_html__('Dropbox OAuth', 'ai-share-kit')
        );
    }

    /**
     * Step 3: Save file to Dropbox
     * Expected JSON: { "title": "file name", "html": "...", "path": "/folder", "format": "pdf|docx" }
     */
    public function save(WP_REST_Request $req) {
        $data = json_decode($req->get_body(), true);
        if (!is_array($data)) {
            return new WP_Error('aiesk_bad_json', __('Invalid JSON', 'ai-share-kit'), ['status' => 400]);
        }

        $title  = sanitize_text_field($data['title'] ?? '');
        $html   = wp_kses_post($data['html'] ?? '');
        $path   = sanitize_text_field($data['path'] ?? '');
        $format = sanitize_key($data['format'] ?? 'pdf');

        if (empty($title) || empty($html)) {
            return new WP_Error('aiesk_missing_data', __('Missing title or html', 'ai-share-kit'), ['status' => 422]);
        }

        // Check for saved Dropbox token (via filter for now)
        $has_tokens = apply_filters('aiesk_dropbox_has_tokens', get_current_user_id());
        if (!$has_tokens) {
            return new WP_Error('aiesk_dropbox_auth_required', __('Dropbox authorization required', 'ai-share-kit'), ['status' => 401]);
        }

        // TODO: Upload to Dropbox API
        return new WP_Error('aiesk_dropbox_not_implemented', __('Dropbox upload not connected yet', 'ai-share-kit'), ['status' => 501]);
    }
}

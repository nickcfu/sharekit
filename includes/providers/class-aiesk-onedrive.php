<?php
/**
 * Provider — OneDrive
 * OAuth + save endpoints (stubs to be wired with your working code).
 */

if (!defined('ABSPATH')) { exit; }

final class AIESK_OneDrive {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void {

        // Step 1: Start OAuth (returns Microsoft consent URL)
        register_rest_route('aiesk/v1', '/onedrive/oauth/start', [
            'methods'             => 'GET',
            'callback'            => [$this, 'oauth_start'],
            'permission_callback' => function () { return is_user_logged_in(); },
        ]);

        // Step 2: OAuth callback catcher
        register_rest_route('aiesk/v1', '/onedrive/oauth/callback', [
            'methods'             => 'GET',
            'callback'            => [$this, 'oauth_callback'],
            'permission_callback' => '__return_true', // must be public for Microsoft redirect
        ]);

        // Step 3: Save file to OneDrive
        register_rest_route('aiesk/v1', '/onedrive/save', [
            'methods'             => 'POST',
            'callback'            => [$this, 'save'],
            'permission_callback' => function () { return is_user_logged_in(); },
        ]);
    }

    /**
     * Provide Microsoft OAuth consent URL (stub).
     * Frontend should redirect the user here to grant access.
     */
    public function oauth_start(WP_REST_Request $req) {
        // TODO: Build real URL using your app id, scopes, redirect_uri
        $auth_url = apply_filters('aiesk_onedrive_oauth_url', '');
        if (empty($auth_url)) {
            return new WP_Error('aiesk_onedrive_oauth_missing', __('OneDrive OAuth URL not configured yet', 'ai-share-kit'), ['status' => 501]);
        }
        return rest_ensure_response(['auth_url' => esc_url_raw($auth_url)]);
    }

    /**
     * Receive Microsoft OAuth callback and store tokens for the user (stub).
     */
    public function oauth_callback(WP_REST_Request $req) {
        $code  = sanitize_text_field($req->get_param('code'));
        $state = sanitize_text_field($req->get_param('state'));

        // TODO: Exchange $code for tokens and save to usermeta(get_current_user_id()).
        wp_die(
            esc_html__('OneDrive OAuth callback received. Please finalize token storage in provider code.', 'ai-share-kit'),
            esc_html__('OneDrive OAuth', 'ai-share-kit')
        );
    }

    /**
     * Save a file to OneDrive.
     * Expected JSON: { "title": "...", "html": "...", "path": "Folder/Sub", "format": "pdf|docx" }
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

        // Check per-user tokens via filter hook (to be implemented with your code)
        $has_tokens = apply_filters('aiesk_onedrive_has_tokens', get_current_user_id());
        if (!$has_tokens) {
            return new WP_Error('aiesk_onedrive_auth_required', __('OneDrive authorization required', 'ai-share-kit'), ['status' => 401]);
        }

        // TODO: Upload to OneDrive using stored tokens.
        return new WP_Error('aiesk_onedrive_not_implemented', __('OneDrive upload not connected yet', 'ai-share-kit'), ['status' => 501]);
    }
}

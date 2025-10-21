<?php
/**
 * Provider — Google Drive
 * Stubs OAuth + file save endpoints. Only registered users may use them.
 */

if (!defined('ABSPATH')) { exit; }

final class AIESK_Drive {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void {

        // Begin OAuth (returns auth URL to redirect user)
        register_rest_route('aiesk/v1', '/drive/oauth/start', [
            'methods'             => 'GET',
            'callback'            => [$this, 'oauth_start'],
            'permission_callback' => function () { return is_user_logged_in(); },
        ]);

        // OAuth callback catcher (to be wired to your real handler)
        register_rest_route('aiesk/v1', '/drive/oauth/callback', [
            'methods'             => 'GET',
            'callback'            => [$this, 'oauth_callback'],
            'permission_callback' => '__return_true', // Google will hit this unauthenticated; we’ll bind token to current user upon finalize step
        ]);

        // Save a file to Drive (HTML → docx/pdf bytes provided by other modules)
        register_rest_route('aiesk/v1', '/drive/save', [
            'methods'             => 'POST',
            'callback'            => [$this, 'save'],
            'permission_callback' => function () { return is_user_logged_in(); },
        ]);
    }

    /**
     * Provide the Google OAuth consent URL (stub).
     * Frontend should redirect user to this URL.
     */
    public function oauth_start(WP_REST_Request $req) {
        // TODO: Use your real client_id, scopes, redirect_uri
        $auth_url = apply_filters('aiesk_drive_oauth_url', '');
        if (empty($auth_url)) {
            return new WP_Error('aiesk_drive_oauth_missing', __('Drive OAuth URL not configured yet', 'ai-share-kit'), ['status' => 501]);
        }
        return rest_ensure_response(['auth_url' => esc_url_raw($auth_url)]);
    }

    /**
     * Receive Google OAuth callback and store tokens for the user (stub).
     * You will replace this with your working code that exchanges code→tokens and saves them in usermeta.
     */
    public function oauth_callback(WP_REST_Request $req) {
        // Example: code/state from Google
        $code  = sanitize_text_field($req->get_param('code'));
        $state = sanitize_text_field($req->get_param('state'));

        // TODO: exchange $code → tokens, then attach to current user on a finalize step
        // For now, just show simple message so the route exists.
        wp_die(
            esc_html__('Google Drive OAuth callback received. Please finalize token storage in provider code.', 'ai-share-kit'),
            esc_html__('Drive OAuth', 'ai-share-kit')
        );
    }

    /**
     * Save a file to Drive (stub).
     * Expected JSON: { "title": "...", "html": "...", "path": "Folder1/Folder2", "format": "pdf|docx" }
     * We’ll rely on the PDF/DOCX endpoints to produce bytes when needed.
     */
    public function save(WP_REST_Request $req) {
        $data  = json_decode($req->get_body(), true);
        if (!is_array($data)) {
            return new WP_Error('aiesk_bad_json', __('Invalid JSON', 'ai-share-kit'), ['status' => 400]);
        }

        $title  = sanitize_text_field($data['title'] ?? '');
        $html   = wp_kses_post($data['html'] ?? '');
        $path   = sanitize_text_field($data['path'] ?? '');
        $format = sanitize_key($data['format'] ?? 'pdf'); // pdf|docx

        if (empty($title) || empty($html)) {
            return new WP_Error('aiesk_missing_data', __('Missing title or html', 'ai-share-kit'), ['status' => 422]);
        }

        // TODO: check usermeta for Drive tokens; if missing, return a 401-like code or a special marker
        $has_tokens = apply_filters('aiesk_drive_has_tokens', get_current_user_id());
        if (!$has_tokens) {
            return new WP_Error('aiesk_drive_auth_required', __('Google Drive authorization required', 'ai-share-kit'), ['status' => 401]);
        }

        // TODO: upload bytes. For now, indicate not implemented.
        return new WP_Error('aiesk_drive_not_implemented', __('Google Drive upload not connected yet', 'ai-share-kit'), ['status' => 501]);
    }
}

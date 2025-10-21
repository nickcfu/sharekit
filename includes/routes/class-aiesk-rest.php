<?php
/**
 * REST API Routes — .eml download & TTL link creation
 * Safe for WP 6.8.x, PHP 8.x. Logged-in users only.
 */

if (!defined('ABSPATH')) { exit; }

final class AIESK_REST {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void {

        // Create downloadable .eml representing the message email
        register_rest_route('aiesk/v1', '/eml', [
            'methods'             => 'POST',
            'callback'            => [$this, 'create_eml'],
            'permission_callback' => function () { return is_user_logged_in(); },
            'args'                => [
                'title' => ['type' => 'string', 'required' => false],
                'html'  => ['type' => 'string', 'required' => true],
            ],
        ]);

        // Create TTL-based share link for guests (public viewer)
        register_rest_route('aiesk/v1', '/link', [
            'methods'             => 'POST',
            'callback'            => [$this, 'create_link'],
            'permission_callback' => function () { return is_user_logged_in(); },
            'args'                => [
                'title'  => ['type' => 'string', 'required' => false],
                'html'   => ['type' => 'string', 'required' => true],
                'amount' => ['type' => 'integer', 'required' => false],
                'unit'   => ['type' => 'string', 'required' => false],
            ],
        ]);
    }

    /**
     * Utility to safely parse the JSON body from a REST request.
     */
    private function get_json_body(\WP_REST_Request $req): array {
        $data = json_decode($req->get_body(), true);
        return is_array($data) ? $data : [];
    }

    /**
     * POST /aiesk/v1/eml
     * Build a simple .eml file (subject + HTML body) and return the public URL.
     * Expected JSON: { "title": "…", "html": "<div>…</div>" }
     */
    public function create_eml(\WP_REST_Request $req) {
        $data  = $this->get_json_body($req);
        $title = sanitize_text_field($data['title'] ?? 'Shared message');
        $html  = wp_kses_post($data['html'] ?? '');

        if ($html === '') {
            return new \WP_Error('aiesk_no_html', __('No HTML provided', 'ai-share-kit'), ['status' => 400]);
        }

        // Build minimal .eml
        $eml  = "Subject: {$title}\r\n";
        $eml .= "MIME-Version: 1.0\r\n";
        $eml .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $eml .= $html;

        // Save to uploads
        $upload = wp_upload_dir();
        if (!empty($upload['error'])) {
            return new \WP_Error('aiesk_upload_error', $upload['error'], ['status' => 500]);
        }

        $fname = 'aiesk-' . time() . '.eml';
        $fpath = trailingslashit($upload['basedir']) . $fname;
        $ok    = file_put_contents($fpath, $eml);

        if ($ok === false) {
            return new \WP_Error('aiesk_eml_write_failed', __('Failed to write EML file', 'ai-share-kit'), ['status' => 500]);
        }

        $url = trailingslashit($upload['baseurl']) . $fname;

        return rest_ensure_response([
            'url'      => esc_url_raw($url),
            'filename' => $fname,
        ]);
    }

    /**
     * POST /aiesk/v1/link
     * Create TTL link (stored in CPT) and return a /shared/{token} URL.
     * Expected JSON: { "title": "…", "html": "<div>…</div>", "amount": 7, "unit": "days|weeks|months" }
     */
    public function create_link(\WP_REST_Request $req) {
        $data   = $this->get_json_body($req);
        $title  = sanitize_text_field($data['title'] ?? 'Shared message');
        $html   = wp_kses_post($data['html'] ?? '');

        if ($html === '') {
            return new \WP_Error('aiesk_no_html', __('No HTML provided', 'ai-share-kit'), ['status' => 400]);
        }

        // TTL defaults from settings
        $amount = isset($data['amount']) ? absint($data['amount']) : (int) get_option('aiesk_ttl_default_amount', 7);
        $unit   = isset($data['unit'])   ? sanitize_text_field($data['unit']) : (string) get_option('aiesk_ttl_default_unit', 'days');
        if (!in_array($unit, ['days','weeks','months'], true)) {
            $unit = 'days';
        }

        $expires = strtotime("+{$amount} {$unit}");
        $token   = wp_generate_password(20, false, false);

        $post_id = wp_insert_post([
            'post_type'   => 'aiesk_link',
            'post_status' => 'publish',
            'post_title'  => $title,
        ]);

        if (is_wp_error($post_id) || !$post_id) {
            return new \WP_Error('aiesk_link_create_failed', __('Could not create share record', 'ai-share-kit'), ['status' => 500]);
        }

        update_post_meta($post_id, 'aiesk_token', $token);
        update_post_meta($post_id, 'aiesk_expires_at', $expires);
        update_post_meta($post_id, 'aiesk_payload_html', $html);
        update_post_meta($post_id, 'aiesk_creator', get_current_user_id());

        $url = home_url('/shared/' . $token);

        /**
         * Action: allow further processing (logging, webhooks, etc.)
         */
        do_action('aiesk_link_created', $post_id, $url, $expires);

        return rest_ensure_response([
            'url'        => esc_url_raw($url),
            'expires_at' => (int) $expires,
            'post_id'    => (int) $post_id,
        ]);
    }
}

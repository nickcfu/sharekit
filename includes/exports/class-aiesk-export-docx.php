<?php
/**
 * Export — DOCX
 * DOCX generation supplied by a registered conversion filter.
 */

if (!defined('ABSPATH')) { exit; }

final class AIESK_Export_DOCX {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_route']);
    }

    /**
     * Register REST route /aiesk/v1/docx
     */
    public function register_route(): void {
        register_rest_route('aiesk/v1', '/docx', [
            'methods'             => 'POST',
            'callback'            => [$this, 'generate'],
            'permission_callback' => function () { return aiesk_rest_permission('docx'); },
        ]);
    }

    /**
     * Generate DOCX file from provided HTML (uses your custom script)
     * Expected JSON: { "html": "<p>...</p>", "title": "..." }
     */
    public function generate(WP_REST_Request $req) {
        $data = aiesk_request_data($req);
        if (!is_array($data) || empty($data['html'])) {
            return new WP_Error('aiesk_no_html', __('No HTML provided', 'ai-share-kit'), ['status' => 400]);
        }

        $html  = aiesk_validate_share_html($data['html']);
        if (is_wp_error($html)) return $html;
        $title = aiesk_normalize_title($data['title'] ?? '');

        if (!has_filter('aiesk_generate_docx')) {
            return new WP_Error('aiesk_docx_error', __('DOCX generator not connected yet', 'ai-share-kit'), ['status' => 501]);
        }

        $reservation = AIESK_Download_Budget::begin();
        if (is_wp_error($reservation)) { return $reservation; }
        try {
            $bytes = apply_filters('aiesk_generate_docx', $html, $title, $data);
            if (is_wp_error($bytes)) { return $bytes; }
            if (!is_string($bytes) || strncmp($bytes, "PK\x03\x04", 4) !== 0) {
                return new WP_Error('aiesk_docx_error', __('DOCX generator did not return a DOCX file', 'ai-share-kit'), ['status' => 500]);
            }
            $file = AIESK_Download_Budget::store($reservation, $bytes, sanitize_file_name($title) . '.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            return is_wp_error($file) ? $file : rest_ensure_response($file);
        } catch (Throwable $error) {
            return new WP_Error('aiesk_docx_error', __('The file could not be generated. Please try again.', 'ai-share-kit'), ['status' => 500]);
        } finally { AIESK_Download_Budget::release($reservation); }

    }
}

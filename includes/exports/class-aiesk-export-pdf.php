<?php
/**
 * Export — PDF
 * PDF generation supplied by a registered conversion filter.
 */

if (!defined('ABSPATH')) { exit; }

final class AIESK_Export_PDF {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_route']);
    }

    /**
     * Register REST route /aiesk/v1/pdf
     */
    public function register_route(): void {
        register_rest_route('aiesk/v1', '/pdf', [
            'methods'             => 'POST',
            'callback'            => [$this, 'generate'],
            'permission_callback' => function () { return aiesk_rest_permission('pdf'); },
        ]);
    }

    /**
     * Generate PDF from HTML (uses your custom PDF script)
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

        if (!has_filter('aiesk_generate_pdf')) {
            return new WP_Error('aiesk_pdf_error', __('PDF generator not connected yet', 'ai-share-kit'), ['status' => 501]);
        }

        $reservation = AIESK_Download_Budget::begin();
        if (is_wp_error($reservation)) { return $reservation; }
        try {
            $bytes = apply_filters('aiesk_generate_pdf', $html, $title);
            if (is_wp_error($bytes)) { return $bytes; }
            if (!is_string($bytes) || strncmp($bytes, '%PDF-', 5) !== 0) {
                return new WP_Error('aiesk_pdf_error', __('PDF generator did not return a PDF file', 'ai-share-kit'), ['status' => 500]);
            }
            $file = AIESK_Download_Budget::store($reservation, $bytes, sanitize_file_name($title) . '.pdf', 'application/pdf');
            return is_wp_error($file) ? $file : rest_ensure_response($file);
        } catch (Throwable $error) {
            return new WP_Error('aiesk_pdf_error', __('The file could not be generated. Please try again.', 'ai-share-kit'), ['status' => 500]);
        } finally { AIESK_Download_Budget::release($reservation); }

    }
}

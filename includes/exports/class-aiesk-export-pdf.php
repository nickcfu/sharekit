<?php
/**
 * Export — PDF
 * Placeholder to integrate your working native PDF generation script.
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
            'permission_callback' => function () { return is_user_logged_in(); },
        ]);
    }

    /**
     * Generate PDF from HTML (uses your custom PDF script)
     * Expected JSON: { "html": "<p>...</p>", "title": "..." }
     */
    public function generate(WP_REST_Request $req) {
        $data = json_decode($req->get_body(), true);
        if (!is_array($data) || empty($data['html'])) {
            return new WP_Error('aiesk_no_html', __('No HTML provided', 'ai-share-kit'), ['status' => 400]);
        }

        $html  = wp_kses_post($data['html']);
        $title = sanitize_text_field($data['title'] ?? 'export-' . date('Ymd-His'));

        // ✅ Placeholder for your working PDF generator
        // Replace this filter call with actual PDF conversion code once you share your script
        $bytes = apply_filters('aiesk_generate_pdf', $html, $title);

        if (empty($bytes)) {
            return new WP_Error('aiesk_pdf_error', __('PDF generator not connected yet', 'ai-share-kit'), ['status' => 501]);
        }

        // Save the generated PDF
        $upload_dir = wp_upload_dir();
        $file_path  = trailingslashit($upload_dir['basedir']) . sanitize_file_name($title) . '.pdf';
        file_put_contents($file_path, $bytes);
        $url = trailingslashit($upload_dir['baseurl']) . basename($file_path);

        return rest_ensure_response([
            'url'       => esc_url_raw($url),
            'filename'  => basename($file_path),
        ]);
    }
}

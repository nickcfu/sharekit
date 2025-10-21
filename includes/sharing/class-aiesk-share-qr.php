<?php
/**
 * Sharing — QR Code (TTL for guest viewers)
 */

if (!defined('ABSPATH')) { exit; }

final class AIESK_Share_QR {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register the REST route to generate QR codes for a shared link.
     */
    public function register_routes(): void {
        register_rest_route('aiesk/v1', '/qr', [
            'methods'             => 'POST',
            'callback'            => [$this, 'create_qr'],
            'permission_callback' => function () { return is_user_logged_in(); },
        ]);
    }

    /**
     * Generate and return a QR code PNG URL for a TTL link.
     * Expected JSON: { "url": "https://example.com/shared/abc123" }
     */
    public function create_qr(WP_REST_Request $req) {
        $data = json_decode($req->get_body(), true);
        if (!is_array($data) || empty($data['url'])) {
            return new WP_Error('aiesk_no_url', __('Missing URL', 'ai-share-kit'), ['status' => 400]);
        }

        $url = esc_url_raw($data['url']);
        if (empty($url)) {
            return new WP_Error('aiesk_bad_url', __('Invalid URL', 'ai-share-kit'), ['status' => 422]);
        }

        // Generate QR code image
        if (!function_exists('imagepng')) {
            return new WP_Error('aiesk_gd_missing', __('GD library not available', 'ai-share-kit'), ['status' => 500]);
        }

        $size = 8;
        $margin = 2;

        // Simple QR code using PHP QR Code if available, otherwise fallback
        if (class_exists('\\QRcode')) {
            ob_start();
            \QRcode::png($url, null, QR_ECLEVEL_L, $size, $margin);
            $imageData = ob_get_clean();
        } else {
            // Fallback: use Google Chart API (no dependency)
            $qr_url = 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' . rawurlencode($url);
            $imageData = file_get_contents($qr_url);
        }

        // Save QR as file in uploads
        $upload = wp_upload_dir();
        $file = $upload['basedir'] . '/aiesk-qr-' . time() . '.png';
        file_put_contents($file, $imageData);

        $public_url = $upload['baseurl'] . '/' . basename($file);

        return rest_ensure_response([
            'url' => esc_url_raw($public_url),
        ]);
    }
}

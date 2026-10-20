<?php
/** Legacy server QR endpoint: retired in favour of browser-local generation. */
if (!defined('ABSPATH')) { exit; }

final class AIESK_Share_QR {
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void {
        register_rest_route('aiesk/v1', '/qr', [
            'methods' => 'POST',
            'callback' => [$this, 'create_qr'],
            'permission_callback' => function () { return aiesk_rest_permission('qr'); },
        ]);
    }

    public function create_qr(WP_REST_Request $req) {
        return new WP_Error(
            'aiesk_qr_retired',
            __('QR codes are now generated privately in your browser. Open Share or My shared links and choose QR code.', 'ai-share-kit'),
            ['status' => 410]
        );
    }
}

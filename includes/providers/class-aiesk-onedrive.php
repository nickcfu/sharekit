<?php
/** onedrive REST adapter. Authentication and uploads share the audited cloud services. */
if (!defined('ABSPATH')) { exit; }
final class AIESK_OneDrive {
    public function __construct() { add_action('rest_api_init', [$this, 'register_routes']); }
    public function register_routes(): void { AIESK_Cloud_Files::register('onedrive'); }
    public function oauth_start(WP_REST_Request $request) { return aiesk_private_rest_response(AIESK_Cloud_Auth::start('onedrive', $request)); }
    public function save(WP_REST_Request $request) { return AIESK_Cloud_Files::save('onedrive', $request); }
}

<?php
/** dropbox REST adapter. Authentication and uploads share the audited cloud services. */
if (!defined('ABSPATH')) { exit; }
final class AIESK_Dropbox {
    public function __construct() { add_action('rest_api_init', [$this, 'register_routes']); }
    public function register_routes(): void { AIESK_Cloud_Files::register('dropbox'); }
    public function oauth_start(WP_REST_Request $request) { return aiesk_private_rest_response(AIESK_Cloud_Auth::start('dropbox', $request)); }
    public function save(WP_REST_Request $request) { return AIESK_Cloud_Files::save('dropbox', $request); }
}

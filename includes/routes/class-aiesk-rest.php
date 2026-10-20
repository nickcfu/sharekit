<?php
/**
 * REST API routes for private downloads, EML files, TTL links, and revocation.
 */

if (!defined('ABSPATH')) { exit; }

final class AIESK_REST {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void {
        register_rest_route('aiesk/v1', '/download/(?P<token>[A-Za-z0-9_-]{20,128})', [
            'methods' => 'GET',
            'callback' => [$this, 'download_private_file'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('aiesk/v1', '/eml', [
            'methods' => 'POST',
            'callback' => [$this, 'create_eml'],
            'permission_callback' => function () { return aiesk_rest_permission(); },
        ]);

        register_rest_route('aiesk/v1', '/link', [
            'methods' => 'POST',
            'callback' => [$this, 'create_link'],
            'permission_callback' => function () { return aiesk_rest_permission('link'); },
        ]);

        register_rest_route('aiesk/v1', '/link/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'revoke_link'],
            'permission_callback' => 'aiesk_manage_links_permission',
        ]);
    }

    private function get_json_body(\WP_REST_Request $req): array {
        return aiesk_request_data($req);
    }

    public function create_eml(\WP_REST_Request $req) {
        return new WP_Error('aiesk_email_draft_retired', __('Email drafts are no longer available. Choose another sharing option.', 'ai-share-kit'), ['status' => 410]);
    }

    public function create_link(\WP_REST_Request $req) {
        $data = $this->get_json_body($req);
        $result = AIESK_Share_Link::create_record(
            $data['title'] ?? '',
            $data['html'] ?? '',
            array_merge(
                ['amount' => $data['amount'] ?? null, 'unit' => $data['unit'] ?? null, 'report_lang' => $data['report_lang'] ?? ''],
                array_key_exists('short_url', $data) ? ['short_url' => $data['short_url']] : []
            )
        );

        return is_wp_error($result) ? $result : aiesk_private_rest_response($result);
    }

    public function revoke_link(\WP_REST_Request $req) {
        $permission = aiesk_manage_links_permission();
        if (is_wp_error($permission)) {
            return $permission;
        }
        $post_id = absint($req['id']);
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'aiesk_link' || $post->post_status !== 'publish' || !aiesk_can_manage_link($post)) {
            // Use the same response for another teacher's record and a missing ID.
            return new \WP_Error('aiesk_link_not_found', __('Share link not found.', 'ai-share-kit'), ['status' => 404]);
        }

        $revoked_at = (int) get_post_meta($post_id, 'aiesk_revoked_at', true);
        if (!$revoked_at) {
            $revoked_at = time();
            update_post_meta($post_id, 'aiesk_revoked_at', $revoked_at);
            $revoked_at = (int) get_post_meta($post_id, 'aiesk_revoked_at', true);
            if (!$revoked_at) {
                return new \WP_Error('aiesk_revoke_failed', __('Access could not be revoked. Please try again.', 'ai-share-kit'), ['status' => 500]);
            }
        }
        $retention = class_exists('AIESK_Privacy') ? AIESK_Privacy::revoked($post_id, $revoked_at) : true;
        $result = ['success' => true, 'post_id' => $post_id, 'revoked_at' => $revoked_at, 'status' => 'revoked'];
        if (is_wp_error($retention)) { $result['warning'] = $retention->get_error_message(); }
        return aiesk_private_rest_response($result);
    }

    public function download_private_file(\WP_REST_Request $req) {
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0', true);
        header('Pragma: no-cache', true);
        // WordPress also routes HEAD to GET handlers. Metadata probes must not
        // redeem a one-use download before the user requests its contents.
        if ($req->get_method() !== 'GET') {
            header('Allow: GET', true);
            return new WP_Error('aiesk_download_method_not_allowed', __('Method not allowed.', 'ai-share-kit'), ['status' => 405]);
        }
        $file = aiesk_consume_private_download((string) $req['token']);
        if (is_wp_error($file)) {
            return $file;
        }
        header('X-Content-Type-Options: nosniff', true);
        header('Content-Type: ' . $file['mime']);
        header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
        header('Content-Length: ' . (string) strlen($file['bytes']));
        echo $file['bytes'];
        exit;
    }
}
